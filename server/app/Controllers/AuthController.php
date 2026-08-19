<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;

final class AuthController
{
    public function showLogin(Request $request): Response
    {
        if (Auth::check()) {
            return Response::redirect('/');
        }
        return View::render('auth/login', ['title' => 'Anmelden'], 'layouts/auth');
    }

    public function login(Request $request): Response
    {
        $email = $request->str('email');
        $password = (string) $request->input('password', '');

        if (!Auth::attempt($email, $password)) {
            Session::flash('error', 'Anmeldung fehlgeschlagen. Bitte E-Mail und Passwort prüfen.');
            return Response::redirect('/login');
        }

        $intended = Session::getFlash('intended', '/');
        return Response::redirect(is_string($intended) ? $intended : '/');
    }

    public function logout(Request $request): Response
    {
        Auth::logout();
        return Response::redirect('/login');
    }
}
