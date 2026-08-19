<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;

/** Gekoppelte Tablets + Erzeugung von Pairing-Codes. */
final class DevicesController
{
    public function index(Request $request): Response
    {
        $familyId = (int) Auth::familyId();
        $devices = Db::select(
            'SELECT * FROM devices WHERE family_id = ? ORDER BY paired_at DESC',
            [$familyId]
        );
        $activeCode = Db::first(
            'SELECT * FROM pairing_codes WHERE family_id = ? AND used_at IS NULL AND expires_at > ? ORDER BY id DESC',
            [$familyId, now()]
        );
        return View::render('devices/index', [
            'title' => 'Geräte', 'devices' => $devices, 'activeCode' => $activeCode,
        ]);
    }

    public function createCode(Request $request): Response
    {
        $familyId = (int) Auth::familyId();
        // Alte offene Codes verwerfen — es gilt immer nur der neueste.
        Db::exec('UPDATE pairing_codes SET used_at = ? WHERE family_id = ? AND used_at IS NULL', [now(), $familyId]);

        do {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        } while (Db::first('SELECT id FROM pairing_codes WHERE code = ? AND used_at IS NULL AND expires_at > ?', [$code, now()]) !== null);

        $ttl = (int) config('pairing.code_ttl_minutes', 10);
        Db::insert('pairing_codes', [
            'family_id'  => $familyId,
            'code'       => $code,
            'expires_at' => date('Y-m-d H:i:s', time() + $ttl * 60),
            'created_at' => now(),
        ]);

        return Response::redirect('/geraete');
    }

    public function revoke(Request $request, string $id): Response
    {
        $device = Db::first(
            'SELECT * FROM devices WHERE id = ? AND family_id = ?',
            [(int) $id, (int) Auth::familyId()]
        );
        if ($device === null) {
            return Response::notFound();
        }
        Db::update('devices', ['revoked_at' => now()], 'id = :id', ['id' => $device['id']]);
        Session::flash('success', 'Gerät abgemeldet — das Token ist ab sofort ungültig.');
        return Response::redirect('/geraete');
    }
}
