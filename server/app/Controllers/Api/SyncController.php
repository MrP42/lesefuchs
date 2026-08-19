<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Db;
use App\Core\Request;
use App\Core\Response;
use App\Middleware\DeviceGuard;

/**
 * Tablet-Sync: ein GET liefert den kompletten Zustand der Familie
 * (Kinder + Einstellungen, Zuweisungen, Paketliste inkl. Dateiliste für die
 * Download-Verwaltung); POSTs bringen Events und Fortschritt zurück.
 */
final class SyncController
{
    private const EVENT_TYPES = [
        'OPEN', 'PLAY', 'PAUSE', 'SEEK', 'PAGE', 'WORD_TAP',
        'GLOSSARY', 'SCAN', 'QUIZ', 'FINISH', 'CLOSE',
    ];

    public function state(Request $request): Response
    {
        $device = DeviceGuard::$device;
        $familyId = (int) $device['family_id'];

        $children = Db::select('SELECT * FROM children WHERE family_id = ? ORDER BY name', [$familyId]);
        foreach ($children as &$child) {
            $child['settings'] = Db::first('SELECT * FROM child_settings WHERE child_id = ?', [$child['id']]);
        }
        unset($child);

        $assignments = Db::select(
            'SELECT a.* FROM assignments a JOIN children c ON c.id = a.child_id
             WHERE c.family_id = ? ORDER BY a.order_index',
            [$familyId]
        );

        $packages = Db::select(
            "SELECT * FROM packages WHERE family_id = ? AND status = 'ready' ORDER BY title",
            [$familyId]
        );
        foreach ($packages as &$pkg) {
            $pkg['files'] = Db::select(
                'SELECT rel_path, size_bytes, sha256 FROM package_files WHERE package_id = ? ORDER BY rel_path',
                [$pkg['id']]
            );
        }
        unset($pkg);

        $progress = Db::select(
            'SELECT p.* FROM progress p JOIN children c ON c.id = p.child_id WHERE c.family_id = ?',
            [$familyId]
        );

        return Response::json([
            'server_time' => now(),
            'device'      => ['id' => (int) $device['id'], 'name' => $device['name']],
            'children'    => $children,
            'assignments' => $assignments,
            'packages'    => $packages,
            'progress'    => $progress,
        ]);
    }

    /** Event-Batch vom Tablet; idempotent über (device_id, client_event_id). */
    public function events(Request $request): Response
    {
        $device = DeviceGuard::$device;
        $events = $request->input('events');
        if (!is_array($events)) {
            return Response::json(['error' => ['code' => 'validation', 'message' => 'events[] erforderlich']], 422);
        }
        if (count($events) > 5000) {
            return Response::json(['error' => ['code' => 'validation', 'message' => 'Höchstens 5000 Events je Batch']], 422);
        }

        $familyId = (int) $device['family_id'];
        $accepted = 0;
        $skipped = 0;
        foreach ($events as $ev) {
            if (!is_array($ev) || !isset($ev['id'], $ev['type'], $ev['ts'])) {
                $skipped++;
                continue;
            }
            if (!in_array((string) $ev['type'], self::EVENT_TYPES, true)) {
                $skipped++;
                continue;
            }
            $childId = isset($ev['child_id']) ? (int) $ev['child_id'] : null;
            if ($childId !== null && !$this->childInFamily($childId, $familyId)) {
                $skipped++;
                continue;
            }
            $exists = Db::first(
                'SELECT id FROM usage_events WHERE device_id = ? AND client_event_id = ?',
                [(int) $device['id'], (int) $ev['id']]
            );
            if ($exists !== null) {
                $skipped++;
                continue;
            }
            Db::insert('usage_events', [
                'device_id'       => (int) $device['id'],
                'client_event_id' => (int) $ev['id'],
                'child_id'        => $childId,
                'package_id'      => isset($ev['package_id']) ? (int) $ev['package_id'] : null,
                'type'            => (string) $ev['type'],
                'ts_utc'          => (int) $ev['ts'],
                'page'            => isset($ev['page']) ? (int) $ev['page'] : null,
                'position_ms'     => isset($ev['position_ms']) ? (int) $ev['position_ms'] : null,
                'duration_ms'     => isset($ev['duration_ms']) ? (int) $ev['duration_ms'] : null,
                'received_at'     => now(),
            ]);
            $accepted++;
        }

        return Response::json(['accepted' => $accepted, 'skipped' => $skipped]);
    }

    /** Fortschritt (last-write-wins über updated_at des Clients). */
    public function progress(Request $request): Response
    {
        $device = DeviceGuard::$device;
        $items = $request->input('progress');
        if (!is_array($items)) {
            return Response::json(['error' => ['code' => 'validation', 'message' => 'progress[] erforderlich']], 422);
        }

        $familyId = (int) $device['family_id'];
        $updated = 0;
        $skipped = 0;
        foreach ($items as $p) {
            if (!is_array($p) || !isset($p['child_id'], $p['package_id'])) {
                $skipped++;
                continue;
            }
            $childId = (int) $p['child_id'];
            $packageId = (int) $p['package_id'];
            if (!$this->childInFamily($childId, $familyId)) {
                $skipped++;
                continue;
            }
            $pkg = Db::first('SELECT id FROM packages WHERE id = ? AND family_id = ?', [$packageId, $familyId]);
            if ($pkg === null) {
                $skipped++;
                continue;
            }
            $clientUpdated = isset($p['updated_at']) ? (string) $p['updated_at'] : now();
            $data = [
                'last_page'         => (int) ($p['last_page'] ?? 0),
                'last_position_ms'  => (int) ($p['last_position_ms'] ?? 0),
                'last_token_index'  => (int) ($p['last_token_index'] ?? 0),
                'listen_count'      => (int) ($p['listen_count'] ?? 0),
                'completed_at'      => isset($p['completed_at']) ? (string) $p['completed_at'] : null,
                'total_listened_ms' => (int) ($p['total_listened_ms'] ?? 0),
                'updated_at'        => $clientUpdated,
            ];
            $existing = Db::first(
                'SELECT * FROM progress WHERE child_id = ? AND package_id = ?',
                [$childId, $packageId]
            );
            if ($existing === null) {
                Db::insert('progress', array_merge($data, ['child_id' => $childId, 'package_id' => $packageId]));
                $updated++;
            } elseif (($existing['updated_at'] ?? '') <= $clientUpdated) {
                Db::update('progress', $data, 'id = :id', ['id' => $existing['id']]);
                $updated++;
            } else {
                $skipped++;
            }
        }

        return Response::json(['updated' => $updated, 'skipped' => $skipped]);
    }

    private function childInFamily(int $childId, int $familyId): bool
    {
        return Db::first('SELECT id FROM children WHERE id = ? AND family_id = ?', [$childId, $familyId]) !== null;
    }
}
