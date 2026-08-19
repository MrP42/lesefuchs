<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;

final class DashboardController
{
    public function index(Request $request): Response
    {
        $familyId = (int) Auth::familyId();
        $weekStartMs = (time() - 7 * 86400) * 1000;

        $children = Db::select('SELECT * FROM children WHERE family_id = ? ORDER BY name', [$familyId]);
        foreach ($children as &$child) {
            // Hörzeit dieser Woche: Summe der PAUSE/CLOSE-duration_ms-Events
            $child['week_ms'] = (int) Db::scalar(
                "SELECT COALESCE(SUM(duration_ms),0) FROM usage_events
                 WHERE child_id = ? AND ts_utc >= ? AND type IN ('PAUSE','CLOSE') AND duration_ms IS NOT NULL",
                [$child['id'], $weekStartMs]
            );
            $child['current'] = Db::first(
                'SELECT p.*, pkg.title FROM progress p JOIN packages pkg ON pkg.id = p.package_id
                 WHERE p.child_id = ? ORDER BY p.updated_at DESC LIMIT 1',
                [$child['id']]
            );
            $child['finished'] = (int) Db::scalar(
                'SELECT COUNT(*) FROM progress WHERE child_id = ? AND completed_at IS NOT NULL',
                [$child['id']]
            );
        }
        unset($child);

        $counts = [
            'packages' => (int) Db::scalar("SELECT COUNT(*) FROM packages WHERE family_id = ? AND status = 'ready'", [$familyId]),
            'devices'  => (int) Db::scalar('SELECT COUNT(*) FROM devices WHERE family_id = ? AND revoked_at IS NULL', [$familyId]),
            'events'   => (int) Db::scalar(
                'SELECT COUNT(*) FROM usage_events ue JOIN devices d ON d.id = ue.device_id WHERE d.family_id = ?',
                [$familyId]
            ),
        ];

        return View::render('dashboard/index', [
            'title'    => 'Übersicht',
            'children' => $children,
            'counts'   => $counts,
        ]);
    }
}
