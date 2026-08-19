<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/**
 * Bearer-Tokens für Studio (Eltern-Account) und Tablets (Geräte).
 * In der DB liegt ausschließlich der SHA-256-Hash; das Klartext-Token
 * existiert nur in der Antwort des erzeugenden Requests.
 */
final class TokenService
{
    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Erzeugt ein Studio-Token für einen Eltern-Account. @return string Klartext-Token */
    public static function createUserToken(int $userId, string $name): string
    {
        $token = 'lfu_' . str_random(32);
        Db::insert('api_tokens', [
            'user_id'    => $userId,
            'name'       => $name,
            'token_hash' => self::hash($token),
            'created_at' => now(),
        ]);
        return $token;
    }

    /** @return array<string,mixed>|null Nutzerzeile zum Token */
    public static function verifyUserToken(string $token): ?array
    {
        $row = Db::first(
            'SELECT t.id AS token_id, u.* FROM api_tokens t
             JOIN users u ON u.id = t.user_id
             WHERE t.token_hash = ? AND t.revoked_at IS NULL AND u.status = ?',
            [self::hash($token), 'active']
        );
        if ($row === null) {
            return null;
        }
        Db::update('api_tokens', ['last_used_at' => now()], 'id = :id', ['id' => $row['token_id']]);
        return $row;
    }

    /** Erzeugt ein Gerät nach erfolgreichem Pairing. @return array{device_id:int,token:string} */
    public static function createDevice(int $familyId, string $name): array
    {
        $token = 'lfd_' . str_random(32);
        $id = Db::insert('devices', [
            'family_id'  => $familyId,
            'name'       => $name,
            'token_hash' => self::hash($token),
            'paired_at'  => now(),
        ]);
        return ['device_id' => $id, 'token' => $token];
    }

    /** @return array<string,mixed>|null Gerätezeile zum Token */
    public static function verifyDeviceToken(string $token): ?array
    {
        $row = Db::first(
            'SELECT * FROM devices WHERE token_hash = ? AND revoked_at IS NULL',
            [self::hash($token)]
        );
        if ($row === null) {
            return null;
        }
        Db::update('devices', ['last_seen_at' => now()], 'id = :id', ['id' => $row['id']]);
        return $row;
    }

    /** Bearer-Token aus dem Request extrahieren (inkl. IONOS-FastCGI-Fallbacks). */
    public static function bearerFrom(\App\Core\Request $request): ?string
    {
        $auth = $request->header('Authorization')
            ?? ($request->server['REDIRECT_HTTP_AUTHORIZATION'] ?? null);
        if ($auth === null && function_exists('apache_request_headers')) {
            foreach (apache_request_headers() as $name => $value) {
                if (strcasecmp($name, 'Authorization') === 0) {
                    $auth = $value;
                    break;
                }
            }
        }
        $auth = (string) ($auth ?? '');
        return str_starts_with($auth, 'Bearer ') ? trim(substr($auth, 7)) : null;
    }
}
