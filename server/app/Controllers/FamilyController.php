<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\Hash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Core\View;
use App\Services\TokenService;

/**
 * Familien- und Kontoverwaltung (nur Admin): Familien + Eltern-Accounts
 * anlegen, Studio-Tokens erzeugen/widerrufen.
 */
final class FamilyController
{
    public function index(Request $request): Response
    {
        $families = Db::select(
            'SELECT f.*,
                (SELECT COUNT(*) FROM users u WHERE u.family_id = f.id) AS user_count,
                (SELECT COUNT(*) FROM children c WHERE c.family_id = f.id) AS child_count
             FROM families f ORDER BY f.name'
        );
        $users = Db::select(
            'SELECT u.*, f.name AS family_name FROM users u
             LEFT JOIN families f ON f.id = u.family_id ORDER BY u.email'
        );
        $tokens = Db::select(
            'SELECT t.*, u.email FROM api_tokens t JOIN users u ON u.id = t.user_id
             WHERE t.revoked_at IS NULL ORDER BY t.created_at DESC'
        );
        return View::render('family/index', [
            'title' => 'Familie & Konten', 'families' => $families, 'users' => $users, 'tokens' => $tokens,
        ]);
    }

    public function storeFamily(Request $request): Response
    {
        $v = Validator::make($request->all(), ['name' => 'required|max:100']);
        if ($v->fails()) {
            Session::flash('error', $v->firstError());
            return Response::redirect('/familie');
        }
        Db::insert('families', ['name' => $request->str('name'), 'created_at' => now()]);
        Session::flash('success', 'Familie angelegt.');
        return Response::redirect('/familie');
    }

    public function storeUser(Request $request): Response
    {
        $v = Validator::make($request->all(), [
            'email'    => 'required|email',
            'name'     => 'required|max:100',
            'password' => 'required|min:8',
        ]);
        if ($v->fails()) {
            Session::flash('error', $v->firstError());
            return Response::redirect('/familie');
        }
        $email = strtolower($request->str('email'));
        if (Db::first('SELECT id FROM users WHERE email = ?', [$email]) !== null) {
            Session::flash('error', 'E-Mail-Adresse ist bereits vergeben.');
            return Response::redirect('/familie');
        }
        $familyId = (int) $request->input('family_id');
        if (Db::first('SELECT id FROM families WHERE id = ?', [$familyId]) === null) {
            Session::flash('error', 'Bitte eine Familie auswählen.');
            return Response::redirect('/familie');
        }
        Db::insert('users', [
            'email'         => $email,
            'password_hash' => Hash::make((string) $request->input('password')),
            'name'          => $request->str('name'),
            'role'          => $request->str('role') === 'admin' ? 'admin' : 'parent',
            'family_id'     => $familyId,
            'status'        => 'active',
            'created_at'    => now(),
        ]);
        Session::flash('success', 'Konto angelegt.');
        return Response::redirect('/familie');
    }

    /** Studio-Token für den EIGENEN Account erzeugen (einmalige Anzeige). */
    public function createToken(Request $request): Response
    {
        $name = $request->str('name', 'Studio');
        $token = TokenService::createUserToken((int) Auth::id(), mb_substr($name, 0, 100));
        Session::flash('new_token', $token);
        Session::flash('success', 'Token erzeugt — jetzt kopieren, es wird nur einmal angezeigt.');
        return Response::redirect('/familie');
    }

    public function revokeToken(Request $request, string $id): Response
    {
        $token = Db::first('SELECT * FROM api_tokens WHERE id = ?', [(int) $id]);
        if ($token === null) {
            return Response::notFound();
        }
        Db::update('api_tokens', ['revoked_at' => now()], 'id = :id', ['id' => $token['id']]);
        Session::flash('success', 'Token widerrufen.');
        return Response::redirect('/familie');
    }
}
