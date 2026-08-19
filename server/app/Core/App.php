<?php
declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * Anwendungs-Bootstrap & Lebenszyklus.
 */
final class App
{
    public static function boot(): void
    {
        Env::load(base_path('.env'));
        Config::load(require base_path('config/config.php'));
        date_default_timezone_set('Europe/Berlin');
        self::registerErrorHandling();
        Database::connect();
        Session::start();
    }

    public static function run(): void
    {
        self::boot();
        $router = new Router();
        (require base_path('config/routes.php'))($router);
        $request = Request::capture();
        $response = $router->dispatch($request);
        // HEAD: identische Header wie GET, aber ohne Body (RFC 7231 §4.3.2).
        if ($request->method === 'HEAD') {
            $response->body = '';
            $response->streamPath = null;
        }
        $response->send();
    }

    private static function registerErrorHandling(): void
    {
        $debug = (bool) config('app.debug', false);
        error_reporting(E_ALL);
        ini_set('display_errors', $debug ? '1' : '0');

        set_exception_handler(function (Throwable $e) use ($debug): void {
            Logger::error('Unhandled: ' . $e->getMessage(), [
                'file' => $e->getFile(), 'line' => $e->getLine(),
            ]);

            $path = Request::pathFromServer();
            if (str_starts_with($path, '/api/')) {
                Response::json(['error' => ['code' => 'server_error', 'message' => 'Interner Fehler']], 500)->send();
                return;
            }

            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: text/html; charset=UTF-8');
            }
            if ($debug) {
                echo '<pre style="padding:1rem;font-family:monospace;">';
                echo e($e::class . ': ' . $e->getMessage()) . "\n\n";
                echo e($e->getFile() . ':' . $e->getLine()) . "\n\n";
                echo e($e->getTraceAsString());
                echo '</pre>';
            } else {
                echo '<h1>Es ist ein Fehler aufgetreten.</h1>';
            }
        });
    }
}
