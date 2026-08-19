<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Db;
use App\Core\Request;
use App\Core\Response;
use App\Services\TokenService;

/** Tablet-Kopplung über 6-stelligen Code aus dem Web-Admin (Konzept §8.1). */
final class PairController
{
    public function pair(Request $request): Response
    {
        $code = preg_replace('/\D/', '', $request->str('code'));
        $name = $request->str('device_name', 'Tablet');
        if (strlen((string) $code) !== 6) {
            return Response::json(['error' => ['code' => 'validation', 'message' => 'code (6-stellig) ist erforderlich']], 422);
        }

        $row = Db::first(
            'SELECT * FROM pairing_codes WHERE code = ? AND used_at IS NULL AND expires_at > ?',
            [$code, now()]
        );
        if ($row === null) {
            return Response::json(['error' => ['code' => 'invalid_code', 'message' => 'Code unbekannt oder abgelaufen']], 401);
        }

        Db::update('pairing_codes', ['used_at' => now()], 'id = :id', ['id' => $row['id']]);
        $device = TokenService::createDevice((int) $row['family_id'], mb_substr($name, 0, 100));

        return Response::json([
            'device_id' => $device['device_id'],
            'token'     => $device['token'],
            'family_id' => (int) $row['family_id'],
        ]);
    }
}
