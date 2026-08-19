<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use RuntimeException;
use ZipArchive;

/**
 * Ablage und Auslieferung von .lesepaket-Inhalten.
 *
 * Layout (oberhalb des Webroots):
 *   storage/packages/{package_id}/paket.lesepaket   Original-ZIP (Komplett-Download)
 *   storage/packages/{package_id}/content/…         entpackt (manifest.json, content.json,
 *                                                   audio/, pages/, cover.webp) für Streaming
 */
final class PackageStore
{
    public static function root(): string
    {
        return storage_path('packages');
    }

    public static function dir(int $packageId): string
    {
        return self::root() . '/' . $packageId;
    }

    /**
     * Importiert ein hochgeladenes .lesepaket (ZIP) für eine Familie.
     * Gleiche manifest-id in derselben Familie ⇒ Ersetzen (neue Paketversion).
     *
     * @return array<string,mixed> die packages-Zeile
     * @throws RuntimeException bei ungültigem Paket
     */
    public static function importZip(int $familyId, string $zipPath, string $originalName = ''): array
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Datei ist kein lesbares ZIP-Archiv.');
        }

        $manifestJson = $zip->getFromName('manifest.json');
        if ($manifestJson === false) {
            $zip->close();
            throw new RuntimeException('manifest.json fehlt im Paket.');
        }
        $manifest = json_decode($manifestJson, true);
        if (!is_array($manifest) || !isset($manifest['id'], $manifest['title'])) {
            $zip->close();
            throw new RuntimeException('manifest.json ist ungültig (id/title fehlen).');
        }
        if ($zip->getFromName('content.json') === false) {
            $zip->close();
            throw new RuntimeException('content.json fehlt im Paket.');
        }

        // Eintragsnamen validieren (kein Traversal, keine absoluten Pfade)
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (str_contains($name, '..') || str_starts_with($name, '/') || preg_match('#^[a-zA-Z]:#', $name)) {
                $zip->close();
                throw new RuntimeException("Unzulässiger Pfad im Archiv: {$name}");
            }
        }

        $uuid = (string) $manifest['id'];
        $existing = Db::first(
            'SELECT * FROM packages WHERE family_id = ? AND uuid = ?',
            [$familyId, $uuid]
        );

        $data = [
            'uuid'            => $uuid,
            'family_id'       => $familyId,
            'title'           => (string) $manifest['title'],
            'author'          => isset($manifest['author']) ? (string) $manifest['author'] : null,
            'type'            => in_array($manifest['type'] ?? '', ['FACSIMILE', 'REFLOW'], true) ? $manifest['type'] : 'REFLOW',
            'language'        => (string) ($manifest['language'] ?? 'de-DE'),
            'reading_level'   => (int) ($manifest['readingLevel'] ?? 1),
            'page_count'      => (int) ($manifest['pageCount'] ?? 0),
            'duration_ms'     => (int) ($manifest['durationMs'] ?? 0),
            'voice'           => isset($manifest['voice']) ? (string) $manifest['voice'] : null,
            'package_version' => (int) ($manifest['packageVersion'] ?? 1),
            'size_bytes'      => (int) (filesize($zipPath) ?: 0),
            'status'          => 'ready',
            'checksum'        => 'sha256:' . hash_file('sha256', $zipPath),
            'updated_at'      => now(),
        ];

        if ($existing !== null) {
            $packageId = (int) $existing['id'];
            Db::update('packages', $data, 'id = :id', ['id' => $packageId]);
            Db::delete('package_files', 'package_id = :id', ['id' => $packageId]);
            self::removeDir(self::dir($packageId));
        } else {
            $data['created_at'] = now();
            $packageId = Db::insert('packages', $data);
        }

        $contentDir = self::dir($packageId) . '/content';
        if (!is_dir($contentDir) && !mkdir($contentDir, 0775, true)) {
            $zip->close();
            throw new RuntimeException('Paketverzeichnis konnte nicht angelegt werden.');
        }
        if (!$zip->extractTo($contentDir)) {
            $zip->close();
            throw new RuntimeException('Entpacken des Pakets fehlgeschlagen.');
        }
        $zip->close();

        if (!copy($zipPath, self::dir($packageId) . '/paket.lesepaket')) {
            throw new RuntimeException('Original-Archiv konnte nicht abgelegt werden.');
        }

        foreach (self::scanFiles($contentDir) as $relPath => $absPath) {
            Db::insert('package_files', [
                'package_id' => $packageId,
                'rel_path'   => $relPath,
                'size_bytes' => (int) (filesize($absPath) ?: 0),
                'sha256'     => hash_file('sha256', $absPath) ?: null,
            ]);
        }

        return Db::first('SELECT * FROM packages WHERE id = ?', [$packageId]);
    }

    /**
     * Traversal-sicherer absoluter Pfad zu einer Paketdatei (oder null).
     * Muster aus wai-portal LearnController::content().
     */
    public static function resolveFile(int $packageId, string $relPath): ?string
    {
        $base = realpath(self::dir($packageId) . '/content');
        if ($base === false) {
            return null;
        }
        $candidate = realpath($base . '/' . $relPath);
        if ($candidate === false || !is_file($candidate)) {
            return null;
        }
        $prefix = rtrim($base, '/\\') . DIRECTORY_SEPARATOR;
        if (!str_starts_with($candidate, $prefix)) {
            return null;
        }
        return $candidate;
    }

    public static function archivePath(int $packageId): ?string
    {
        $path = self::dir($packageId) . '/paket.lesepaket';
        return is_file($path) ? $path : null;
    }

    /** Paket vollständig löschen (DB-Zeilen + Dateien). */
    public static function delete(int $packageId): void
    {
        Db::delete('assignments', 'package_id = :id', ['id' => $packageId]);
        Db::delete('progress', 'package_id = :id', ['id' => $packageId]);
        Db::delete('package_files', 'package_id = :id', ['id' => $packageId]);
        Db::exec('UPDATE usage_events SET package_id = NULL WHERE package_id = ?', [$packageId]);
        Db::delete('packages', 'id = :id', ['id' => $packageId]);
        self::removeDir(self::dir($packageId));
    }

    /** @return array<string,string> rel_path => abs_path (rekursiv, mit /-Trennern) */
    private static function scanFiles(string $dir): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        /** @var \SplFileInfo $file */
        foreach ($it as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $rel = substr($file->getPathname(), strlen($dir) + 1);
            $out[str_replace('\\', '/', $rel)] = $file->getPathname();
        }
        ksort($out);
        return $out;
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }
}
