<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Services\TokenService;

/**
 * Bearer-Guard für Tablet-Endpunkte (Sync, Paket-Download/-Streaming).
 * Stellt die Gerätezeile prozessweit bereit (family-Scope).
 */
final class DeviceGuard
{
    public static ?array $device = null;

    public function handle(Request $request): ?Response
    {
        self::$device = null;
        if ($request->jsonError) {
            return Response::json(['error' => ['code' => 'invalid_json', 'message' => 'JSON-Body nicht lesbar']], 400);
        }
        $token = TokenService::bearerFrom($request);
        if ($token === null) {
            return Response::json(['error' => ['code' => 'unauthorized', 'message' => 'Bearer-Token erforderlich']], 401)
                ->withHeader('WWW-Authenticate', 'Bearer');
        }
        $device = TokenService::verifyDeviceToken($token);
        if ($device === null) {
            return Response::json(['error' => ['code' => 'unauthorized', 'message' => 'Geräte-Token ungültig oder widerrufen']], 401);
        }
        self::$device = $device;
        return null;
    }
}
