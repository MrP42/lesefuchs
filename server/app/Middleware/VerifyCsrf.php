<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;

/** Prüft das CSRF-Token bei zustandsändernden Methoden (nur Web-Formulare). */
final class VerifyCsrf
{
    public function handle(Request $request): ?Response
    {
        if (in_array($request->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $token = $request->input('_csrf') ?? $request->header('X-CSRF-Token');
            if (!Csrf::check(is_string($token) ? $token : null)) {
                return Response::html('Sitzung abgelaufen oder ungültiges Sicherheits-Token. Bitte Seite neu laden.', 419);
            }
        }
        return null;
    }
}
