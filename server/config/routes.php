<?php
declare(strict_types=1);

use App\Controllers\Api;
use App\Controllers\AssignmentsController;
use App\Controllers\AuthController;
use App\Controllers\ChildrenController;
use App\Controllers\DashboardController;
use App\Controllers\DevicesController;
use App\Controllers\FamilyController;
use App\Controllers\PackagesController;
use App\Core\Response;
use App\Core\Router;
use App\Middleware\AdminGuard;
use App\Middleware\ApiUserGuard;
use App\Middleware\AuthGuard;
use App\Middleware\DeviceGuard;
use App\Middleware\VerifyCsrf;

return function (Router $r): void {
    // ---- System ----------------------------------------------------------
    $r->get('/healthz', fn () => Response::json(['status' => 'ok', 'app' => 'lesefuchs']));

    // ---- Web-Admin (Session + CSRF) -------------------------------------
    $auth = [AuthGuard::class];
    $web  = [AuthGuard::class, VerifyCsrf::class];
    $admin = [AuthGuard::class, AdminGuard::class];
    $adminWeb = [AuthGuard::class, AdminGuard::class, VerifyCsrf::class];

    $r->get('/login', [AuthController::class, 'showLogin']);
    $r->post('/login', [AuthController::class, 'login'], [VerifyCsrf::class]);
    $r->post('/logout', [AuthController::class, 'logout'], $web);

    $r->get('/', [DashboardController::class, 'index'], $auth);

    $r->get('/kinder', [ChildrenController::class, 'index'], $auth);
    $r->get('/kinder/neu', [ChildrenController::class, 'create'], $auth);
    $r->post('/kinder', [ChildrenController::class, 'store'], $web);
    $r->get('/kinder/{id}', [ChildrenController::class, 'edit'], $auth);
    $r->post('/kinder/{id}', [ChildrenController::class, 'update'], $web);
    $r->post('/kinder/{id}/loeschen', [ChildrenController::class, 'destroy'], $web);

    $r->get('/geraete', [DevicesController::class, 'index'], $auth);
    $r->post('/geraete/code', [DevicesController::class, 'createCode'], $web);
    $r->post('/geraete/{id}/abmelden', [DevicesController::class, 'revoke'], $web);

    $r->get('/bibliothek', [PackagesController::class, 'index'], $auth);
    $r->post('/bibliothek/upload', [PackagesController::class, 'upload'], $web);
    $r->get('/bibliothek/{id}', [PackagesController::class, 'show'], $auth);
    $r->get('/bibliothek/{id}/datei/{path*}', [PackagesController::class, 'file'], $auth);
    $r->post('/bibliothek/{id}/archiv', [PackagesController::class, 'archiveToggle'], $web);
    $r->post('/bibliothek/{id}/loeschen', [PackagesController::class, 'destroy'], $web);

    $r->get('/zuweisungen', [AssignmentsController::class, 'index'], $auth);
    $r->post('/zuweisungen/umschalten', [AssignmentsController::class, 'toggle'], $web);

    $r->get('/familie', [FamilyController::class, 'index'], $admin);
    $r->post('/familie/familien', [FamilyController::class, 'storeFamily'], $adminWeb);
    $r->post('/familie/konten', [FamilyController::class, 'storeUser'], $adminWeb);
    $r->post('/familie/token', [FamilyController::class, 'createToken'], $web);
    $r->post('/familie/token/{id}/widerrufen', [FamilyController::class, 'revokeToken'], $adminWeb);

    // ---- API v1 ----------------------------------------------------------
    // Studio (Eltern-Account, Bearer)
    $r->post('/api/v1/auth/login', [Api\AuthController::class, 'login']);
    $studio = [ApiUserGuard::class];
    $r->get('/api/v1/packages', [Api\PackagesController::class, 'index'], $studio);
    $r->post('/api/v1/packages/upload/init', [Api\PackagesController::class, 'uploadInit'], $studio);
    $r->put('/api/v1/packages/upload/{token}/chunk/{n}', [Api\PackagesController::class, 'uploadChunk'], $studio);
    $r->post('/api/v1/packages/upload/{token}/finish', [Api\PackagesController::class, 'uploadFinish'], $studio);

    // Tablet (Geräte-Token, Bearer)
    $r->post('/api/v1/devices/pair', [Api\PairController::class, 'pair']);
    $device = [DeviceGuard::class];
    $r->get('/api/v1/sync/state', [Api\SyncController::class, 'state'], $device);
    $r->post('/api/v1/sync/events', [Api\SyncController::class, 'events'], $device);
    $r->post('/api/v1/sync/progress', [Api\SyncController::class, 'progress'], $device);
    $r->get('/api/v1/packages/{id}/archive', [Api\PackagesController::class, 'archive'], $device);
    $r->get('/api/v1/packages/{id}/files/{path*}', [Api\PackagesController::class, 'file'], $device);
};
