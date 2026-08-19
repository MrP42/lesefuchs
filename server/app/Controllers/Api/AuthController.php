<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Db;
use App\Core\Hash;
use App\Core\Request;
use App\Core\Response;
use App\Services\TokenService;

/** Studio-Login: E-Mail + Passwort ⇒ Bearer-Token für die Paket-Upload-API. */
final class AuthController
{
    public function login(Request $request): Response
    {
        $email = strtolower($request->str('email'));
        $password = (string) $request->input('password', '');
        $deviceName = $request->str('client', 'Studio');

        if ($email === '' || $password === '') {
            return Response::json(['error' => ['code' => 'validation', 'message' => 'email und password sind erforderlich']], 422);
        }
        if (Auth::tooManyAttempts($email)) {
            return Response::json(['error' => ['code' => 'rate_limited', 'message' => 'Zu viele Fehlversuche — bitte 15 Minuten warten']], 429);
        }

        $user = Db::first('SELECT * FROM users WHERE email = ?', [$email]);
        if ($user === null || $user['status'] !== 'active' || !Hash::verify($password, $user['password_hash'] ?? null)) {
            Db::insert('login_attempts', ['identifier' => $email, 'attempted_at' => now()]);
            return Response::json(['error' => ['code' => 'unauthorized', 'message' => 'Anmeldung fehlgeschlagen']], 401);
        }
        if ($user['family_id'] === null) {
            return Response::json(['error' => ['code' => 'forbidden', 'message' => 'Konto ist keiner Familie zugeordnet']], 403);
        }

        Db::delete('login_attempts', 'identifier = :id', ['id' => $email]);
        $token = TokenService::createUserToken((int) $user['id'], $deviceName);

        return Response::json([
            'token' => $token,
            'user'  => ['id' => (int) $user['id'], 'name' => $user['name'], 'email' => $user['email']],
        ]);
    }
}
