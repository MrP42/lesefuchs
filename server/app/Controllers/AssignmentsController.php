<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;

/** Zuweisungs-Matrix Kind × Paket. */
final class AssignmentsController
{
    public function index(Request $request): Response
    {
        $familyId = (int) Auth::familyId();
        $children = Db::select('SELECT * FROM children WHERE family_id = ? ORDER BY name', [$familyId]);
        $packages = Db::select(
            "SELECT * FROM packages WHERE family_id = ? AND status = 'ready' ORDER BY title",
            [$familyId]
        );
        $assignments = Db::select(
            'SELECT a.* FROM assignments a JOIN children c ON c.id = a.child_id WHERE c.family_id = ?',
            [$familyId]
        );
        $map = [];
        foreach ($assignments as $a) {
            $map[$a['child_id'] . ':' . $a['package_id']] = $a;
        }
        return View::render('assignments/index', [
            'title' => 'Zuweisungen', 'children' => $children, 'packages' => $packages, 'map' => $map,
        ]);
    }

    /** Umschalten einer Zuweisung (Checkbox-Klick). */
    public function toggle(Request $request): Response
    {
        $familyId = (int) Auth::familyId();
        $childId = (int) $request->input('child_id');
        $packageId = (int) $request->input('package_id');

        $child = Db::first('SELECT id FROM children WHERE id = ? AND family_id = ?', [$childId, $familyId]);
        $package = Db::first('SELECT id FROM packages WHERE id = ? AND family_id = ?', [$packageId, $familyId]);
        if ($child === null || $package === null) {
            return Response::notFound();
        }

        $existing = Db::first(
            'SELECT id FROM assignments WHERE child_id = ? AND package_id = ?',
            [$childId, $packageId]
        );
        if ($existing !== null) {
            Db::delete('assignments', 'id = :id', ['id' => $existing['id']]);
            Session::flash('success', 'Zuweisung entfernt.');
        } else {
            $max = (int) Db::scalar('SELECT COALESCE(MAX(order_index),0) FROM assignments WHERE child_id = ?', [$childId]);
            Db::insert('assignments', [
                'child_id'    => $childId,
                'package_id'  => $packageId,
                'assigned_at' => now(),
                'order_index' => $max + 1,
            ]);
            Session::flash('success', 'Zuweisung angelegt.');
        }
        return Response::redirect('/zuweisungen');
    }
}
