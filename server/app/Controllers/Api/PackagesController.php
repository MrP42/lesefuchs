<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Db;
use App\Core\Request;
use App\Core\Response;
use App\Middleware\DeviceGuard;
use App\Services\PackageStore;
use RuntimeException;

/**
 * Paket-API.
 *  - Upload (Studio, ApiUserGuard): chunked — init ⇒ Chunks ⇒ finish.
 *  - Auslieferung (Tablet, DeviceGuard): Einzeldateien mit Range-Support
 *    (Streaming) oder Komplett-Archiv (Offline-Download).
 */
final class PackagesController
{
    // ---- Studio: Chunk-Upload -------------------------------------------

    public function uploadInit(Request $request): Response
    {
        $filename = $request->str('filename', 'paket.lesepaket');
        $totalSize = (int) $request->input('total_size', 0);
        $totalChunks = (int) $request->input('total_chunks', 0);
        $sha256 = strtolower($request->str('sha256'));

        $maxSize = (int) config('packages.max_size_bytes');
        if ($totalSize < 1 || $totalSize > $maxSize) {
            return Response::json(['error' => ['code' => 'validation', 'message' => "total_size muss zwischen 1 und {$maxSize} Bytes liegen"]], 422);
        }
        if ($totalChunks < 1 || $totalChunks > 10000) {
            return Response::json(['error' => ['code' => 'validation', 'message' => 'total_chunks ungültig']], 422);
        }
        if (!preg_match('/^[0-9a-f]{64}$/', $sha256)) {
            return Response::json(['error' => ['code' => 'validation', 'message' => 'sha256 (hex, 64 Zeichen) erforderlich']], 422);
        }

        $token = str_random(24);
        Db::insert('upload_sessions', [
            'token'        => $token,
            'family_id'    => (int) Auth::familyId(),
            'user_id'      => (int) Auth::id(),
            'filename'     => mb_substr($filename, 0, 200),
            'total_size'   => $totalSize,
            'total_chunks' => $totalChunks,
            'sha256'       => $sha256,
            'status'       => 'open',
            'created_at'   => now(),
        ]);
        @mkdir($this->uploadDir($token), 0775, true);

        return Response::json([
            'upload_token' => $token,
            'chunk_bytes'  => (int) config('packages.chunk_bytes'),
        ], 201);
    }

    public function uploadChunk(Request $request, string $token, string $n): Response
    {
        $session = $this->openSession($token);
        if ($session === null) {
            return Response::json(['error' => ['code' => 'not_found', 'message' => 'Upload-Session unbekannt oder abgeschlossen']], 404);
        }
        $index = (int) $n;
        if ($index < 0 || $index >= (int) $session['total_chunks']) {
            return Response::json(['error' => ['code' => 'validation', 'message' => 'Chunk-Index außerhalb des Bereichs']], 422);
        }

        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return Response::json(['error' => ['code' => 'validation', 'message' => 'Leerer Chunk-Body']], 422);
        }
        $limit = (int) config('packages.chunk_bytes') + 1024;
        if (strlen($raw) > $limit) {
            return Response::json(['error' => ['code' => 'payload_too_large', 'message' => 'Chunk größer als chunk_bytes']], 413);
        }

        file_put_contents($this->uploadDir($token) . '/chunk_' . $index, $raw);
        return Response::json(['received' => $index, 'bytes' => strlen($raw)]);
    }

    public function uploadFinish(Request $request, string $token): Response
    {
        $session = $this->openSession($token);
        if ($session === null) {
            return Response::json(['error' => ['code' => 'not_found', 'message' => 'Upload-Session unbekannt oder abgeschlossen']], 404);
        }

        $dir = $this->uploadDir($token);
        $total = (int) $session['total_chunks'];
        $missing = [];
        for ($i = 0; $i < $total; $i++) {
            if (!is_file($dir . '/chunk_' . $i)) {
                $missing[] = $i;
            }
        }
        if ($missing !== []) {
            return Response::json(['error' => ['code' => 'incomplete', 'message' => 'Chunks fehlen', 'missing' => array_slice($missing, 0, 50)]], 409);
        }

        $target = $dir . '/assembled.lesepaket';
        $out = fopen($target, 'wb');
        for ($i = 0; $i < $total; $i++) {
            $in = fopen($dir . '/chunk_' . $i, 'rb');
            stream_copy_to_stream($in, $out);
            fclose($in);
        }
        fclose($out);

        if ((int) filesize($target) !== (int) $session['total_size']) {
            $this->failSession($session, $dir);
            return Response::json(['error' => ['code' => 'size_mismatch', 'message' => 'Gesamtgröße stimmt nicht mit total_size überein']], 422);
        }
        if (!hash_equals((string) $session['sha256'], (string) hash_file('sha256', $target))) {
            $this->failSession($session, $dir);
            return Response::json(['error' => ['code' => 'checksum_mismatch', 'message' => 'SHA-256 stimmt nicht überein']], 422);
        }

        try {
            $package = PackageStore::importZip((int) $session['family_id'], $target, (string) $session['filename']);
        } catch (RuntimeException $e) {
            $this->failSession($session, $dir);
            return Response::json(['error' => ['code' => 'invalid_package', 'message' => $e->getMessage()]], 422);
        }

        Db::update('upload_sessions', ['status' => 'done'], 'id = :id', ['id' => $session['id']]);
        $this->removeDir($dir);

        return Response::json(['package' => $this->publicPackage($package)], 201);
    }

    /** Paketliste für das Studio (eigene Familie). */
    public function index(Request $request): Response
    {
        $rows = Db::select(
            'SELECT * FROM packages WHERE family_id = ? ORDER BY title',
            [(int) Auth::familyId()]
        );
        return Response::json(['packages' => array_map($this->publicPackage(...), $rows)]);
    }

    // ---- Tablet: Auslieferung -------------------------------------------

    public function file(Request $request, string $id, string $path): Response
    {
        $package = $this->devicePackage((int) $id);
        if ($package === null) {
            return Response::json(['error' => ['code' => 'not_found', 'message' => 'Paket nicht gefunden']], 404);
        }
        $abs = PackageStore::resolveFile((int) $package['id'], $path);
        if ($abs === null) {
            return Response::json(['error' => ['code' => 'not_found', 'message' => 'Datei nicht gefunden']], 404);
        }
        return Response::fileRange($abs, $request->header('Range'));
    }

    public function archive(Request $request, string $id): Response
    {
        $package = $this->devicePackage((int) $id);
        if ($package === null) {
            return Response::json(['error' => ['code' => 'not_found', 'message' => 'Paket nicht gefunden']], 404);
        }
        $abs = PackageStore::archivePath((int) $package['id']);
        if ($abs === null) {
            return Response::json(['error' => ['code' => 'not_found', 'message' => 'Archiv nicht vorhanden']], 404);
        }
        $name = slugify((string) $package['title']) . '.lesepaket';
        return Response::fileRange($abs, $request->header('Range'), $name);
    }

    // ---- intern ----------------------------------------------------------

    private function devicePackage(int $id): ?array
    {
        $device = DeviceGuard::$device;
        if ($device === null) {
            return null;
        }
        return Db::first(
            "SELECT * FROM packages WHERE id = ? AND family_id = ? AND status = 'ready'",
            [$id, (int) $device['family_id']]
        );
    }

    private function openSession(string $token): ?array
    {
        $ttl = (int) config('packages.upload_ttl_hours', 24);
        $since = date('Y-m-d H:i:s', time() - $ttl * 3600);
        return Db::first(
            "SELECT * FROM upload_sessions WHERE token = ? AND family_id = ? AND status = 'open' AND created_at >= ?",
            [$token, (int) Auth::familyId(), $since]
        );
    }

    private function uploadDir(string $token): string
    {
        return storage_path('tmp/uploads/' . preg_replace('/[^A-Za-z0-9_-]/', '', $token));
    }

    private function failSession(array $session, string $dir): void
    {
        Db::update('upload_sessions', ['status' => 'failed'], 'id = :id', ['id' => $session['id']]);
        $this->removeDir($dir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }

    private function publicPackage(array $p): array
    {
        return [
            'id'              => (int) $p['id'],
            'uuid'            => $p['uuid'],
            'title'           => $p['title'],
            'author'          => $p['author'],
            'type'            => $p['type'],
            'language'        => $p['language'],
            'reading_level'   => (int) $p['reading_level'],
            'page_count'      => (int) $p['page_count'],
            'duration_ms'     => (int) $p['duration_ms'],
            'voice'           => $p['voice'],
            'package_version' => (int) $p['package_version'],
            'size_bytes'      => (int) $p['size_bytes'],
            'status'          => $p['status'],
            'checksum'        => $p['checksum'],
        ];
    }
}
