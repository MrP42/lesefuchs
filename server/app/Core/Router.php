<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Schlanker Router mit benannten Parametern und Middleware-Kette.
 * Handler: [ControllerClass::class, 'method'] oder Closure.
 * Middleware: Objekte mit handle(Request): ?Response (Response = Short-Circuit).
 */
final class Router
{
    /** @var array<int,array{method:string,regex:string,params:string[],handler:mixed,mw:array}> */
    private array $routes = [];

    public function get(string $path, mixed $handler, array $mw = []): void { $this->add('GET', $path, $handler, $mw); }
    public function post(string $path, mixed $handler, array $mw = []): void { $this->add('POST', $path, $handler, $mw); }
    public function put(string $path, mixed $handler, array $mw = []): void { $this->add('PUT', $path, $handler, $mw); }
    public function delete(string $path, mixed $handler, array $mw = []): void { $this->add('DELETE', $path, $handler, $mw); }

    public function add(string $method, string $path, mixed $handler, array $mw = []): void
    {
        $params = [];
        // {name}  → ein Segment ([^/]+) ; {name*} → Rest inkl. Slashes (.+) für Datei-/Pfad-Routen
        $regex = preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)(\*?)\}/', function ($m) use (&$params) {
            $params[] = $m[1];
            return $m[2] === '*' ? '(?P<' . $m[1] . '>.+)' : '(?P<' . $m[1] . '>[^/]+)';
        }, $path);
        $regex = '#^' . $regex . '$#';
        $this->routes[] = compact('method', 'regex', 'params', 'handler', 'mw');
    }

    public function dispatch(Request $request): Response
    {
        // HEAD wie GET behandeln: HEAD-Anfragen sollen dieselben Routen wie GET
        // treffen (RFC 7231). Der Body wird für HEAD an anderer Stelle unterdrückt
        // (App::run / Apache). So liefert z. B. `curl -I /` 200 statt 405.
        $effectiveMethod = $request->method === 'HEAD' ? 'GET' : $request->method;
        $pathMatched = false;
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path, $matches)) {
                continue;
            }
            $pathMatched = true;
            if ($route['method'] !== $effectiveMethod) {
                continue;
            }
            $params = [];
            foreach ($route['params'] as $name) {
                $params[$name] = $matches[$name] ?? null;
            }

            // Middleware-Kette
            foreach ($route['mw'] as $mw) {
                $mwInstance = is_string($mw) ? new $mw() : $mw;
                $result = $mwInstance->handle($request);
                if ($result instanceof Response) {
                    return $result;
                }
            }

            return $this->invoke($route['handler'], $request, $params);
        }

        $isApi = str_starts_with($request->path, '/api/');

        if ($pathMatched) {
            if ($isApi) {
                return Response::json(['error' => ['code' => 'method_not_allowed', 'message' => 'Methode nicht erlaubt']], 405);
            }
            return Response::html('Methode nicht erlaubt', 405);
        }
        if ($isApi) {
            return Response::json(['error' => ['code' => 'not_found', 'message' => 'Route nicht gefunden']], 404);
        }
        return View::render('errors/404', ['title' => 'Nicht gefunden'], 'layouts/auth', 404)
            ->withHeader('X-Robots-Tag', 'noindex');
    }

    private function invoke(mixed $handler, Request $request, array $params): Response
    {
        if (is_array($handler)) {
            [$class, $method] = $handler;
            $controller = new $class();
            $result = $controller->$method($request, ...array_values($params));
        } else {
            $result = $handler($request, ...array_values($params));
        }
        if ($result instanceof Response) {
            return $result;
        }
        if (is_array($result)) {
            return Response::json($result);
        }
        return Response::html((string) $result);
    }
}
