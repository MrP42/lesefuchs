<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Services\TokenService;

/**
 * Bearer-Guard für Studio-Endpunkte (/api/v1/packages/upload/*, …):
 * verifiziert ein Eltern-Account-Token und setzt den sessionlosen Prinzipal.
 */
final class ApiUserGuard
{
    public function handle(Request $request): ?Response
    {
        if ($request->jsonError) {
            return Response::json(['error' => ['code' => 'invalid_json', 'message' => 'JSON-Body nicht lesbar']], 400);
        }
        $token = TokenService::bearerFrom($request);
        if ($token === null) {
            return Response::json(['error' => ['code' => 'unauthorized', 'message' => 'Bearer-Token erforderlich']], 401)
                ->withHeader('WWW-Authenticate', 'Bearer');
        }
        $user = TokenService::verifyUserToken($token);
        if ($user === null || $user['family_id'] === null) {
            return Response::json(['error' => ['code' => 'unauthorized', 'message' => 'Token ungültig oder widerrufen']], 401);
        }
        Auth::setUserForRequest($user);
        return null;
    }
}
