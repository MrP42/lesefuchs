<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Services\PackageStore;
use RuntimeException;

/** Bibliothek im Web-Admin: Liste, Upload (fürs Testen ohne Studio), Detail, Archiv. */
final class PackagesController
{
    public function index(Request $request): Response
    {
        $packages = Db::select(
            'SELECT p.*, (SELECT COUNT(*) FROM package_files f WHERE f.package_id = p.id) AS file_count
             FROM packages p WHERE p.family_id = ? ORDER BY p.status, p.title',
            [(int) Auth::familyId()]
        );
        return View::render('packages/index', ['title' => 'Bibliothek', 'packages' => $packages]);
    }

    public function upload(Request $request): Response
    {
        $file = $request->file('paket');
        if ($file === null || $file['error'] !== UPLOAD_ERR_OK) {
            Session::flash('error', 'Bitte eine .lesepaket-Datei auswählen (Upload-Fehlercode: ' . ($file['error'] ?? '-') . ').');
            return Response::redirect('/bibliothek');
        }
        try {
            $package = PackageStore::importZip((int) Auth::familyId(), $file['tmp_name'], $file['name']);
            Session::flash('success', '„' . $package['title'] . '" importiert (Version ' . $package['package_version'] . ').');
        } catch (RuntimeException $e) {
            Session::flash('error', 'Import fehlgeschlagen: ' . $e->getMessage());
        }
        return Response::redirect('/bibliothek');
    }

    public function show(Request $request, string $id): Response
    {
        $package = $this->find((int) $id);
        if ($package === null) {
            return Response::notFound();
        }
        $files = Db::select(
            'SELECT * FROM package_files WHERE package_id = ? ORDER BY rel_path',
            [$package['id']]
        );
        $assigned = Db::select(
            'SELECT c.name FROM assignments a JOIN children c ON c.id = a.child_id WHERE a.package_id = ?',
            [$package['id']]
        );
        return View::render('packages/show', [
            'title' => $package['title'], 'package' => $package, 'files' => $files, 'assigned' => $assigned,
        ]);
    }

    /** Datei-Vorschau (Cover, Audio-Probe) für das Admin-UI — Session-Auth. */
    public function file(Request $request, string $id, string $path): Response
    {
        $package = $this->find((int) $id);
        if ($package === null) {
            return Response::notFound();
        }
        $abs = PackageStore::resolveFile((int) $package['id'], $path);
        if ($abs === null) {
            return Response::notFound();
        }
        return Response::fileRange($abs, $request->header('Range'));
    }

    public function archiveToggle(Request $request, string $id): Response
    {
        $package = $this->find((int) $id);
        if ($package === null) {
            return Response::notFound();
        }
        $new = $package['status'] === 'archived' ? 'ready' : 'archived';
        Db::update('packages', ['status' => $new, 'updated_at' => now()], 'id = :id', ['id' => $package['id']]);
        Session::flash('success', $new === 'archived' ? 'Paket archiviert (für Tablets unsichtbar).' : 'Paket reaktiviert.');
        return Response::redirect('/bibliothek/' . $package['id']);
    }

    public function destroy(Request $request, string $id): Response
    {
        $package = $this->find((int) $id);
        if ($package === null) {
            return Response::notFound();
        }
        PackageStore::delete((int) $package['id']);
        Session::flash('success', 'Paket vollständig gelöscht.');
        return Response::redirect('/bibliothek');
    }

    private function find(int $id): ?array
    {
        return Db::first(
            'SELECT * FROM packages WHERE id = ? AND family_id = ?',
            [$id, (int) Auth::familyId()]
        );
    }
}
