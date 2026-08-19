<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;

/** Erfordert die Rolle admin (Familien-/Nutzerverwaltung). */
final class AdminGuard
{
    public function handle(Request $request): ?Response
    {
        if (!Auth::isAdmin()) {
            return Response::html('Kein Zugriff.', 403);
        }
        return null;
    }
}
