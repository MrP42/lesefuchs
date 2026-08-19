<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

/** Erfordert eingeloggten Nutzer; sonst Weiterleitung zum Login. */
final class AuthGuard
{
    public function handle(Request $request): ?Response
    {
        if (!Auth::check()) {
            Session::flash('intended', $request->path);
            return Response::redirect('/login');
        }
        return null;
    }
}
