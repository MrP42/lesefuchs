<?php
declare(strict_types=1);

namespace App\Core;

/**
 * HTTP-Request-Abstraktion.
 */
final class Request
{
    /** Wird true, wenn Content-Type application/json ist, der Body aber nicht dekodierbar war. */
    public bool $jsonError = false;

    public function __construct(
        public string $method,
        public string $path,
        public array $query,
        public array $post,
        public array $server,
        public array $cookies = [],
        public array $files = []
    ) {}

    public static function pathFromServer(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        return '/' . trim((string) $path, '/');
    }

    public static function capture(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        // Method-Override für PUT/DELETE via _method
        if ($method === 'POST' && isset($_POST['_method'])) {
            $override = strtoupper((string) $_POST['_method']);
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                $method = $override;
            }
        }
        $path = self::pathFromServer();

        $post = $_POST ?? [];
        $jsonError = false;
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains(strtolower((string) $contentType), 'application/json')) {
            $raw = file_get_contents('php://input');
            if ($raw !== false && $raw !== '') {
                $json = json_decode($raw, true);
                if (is_array($json)) {
                    $post = array_merge($post, $json);
                } else {
                    $jsonError = true;
                }
            }
        }

        $request = new self($method, $path, $_GET ?? [], $post, $_SERVER ?? [], $_COOKIE ?? [], self::normalizeFiles($_FILES ?? []));
        $request->jsonError = $jsonError;
        return $request;
    }

    /**
     * Normalisiert $_FILES auf je EINE Datei pro Feldname:
     * ['feld' => ['name','type','tmp_name','error','size']]. Mehrfach-Uploads
     * (name[]-Syntax) werden bewusst nicht unterstützt — kein Formular nutzt sie.
     *
     * @param array<string,mixed> $files
     * @return array<string,array{name:string,type:string,tmp_name:string,error:int,size:int}>
     */
    private static function normalizeFiles(array $files): array
    {
        $out = [];
        foreach ($files as $field => $f) {
            if (!is_array($f) || is_array($f['name'] ?? null)) {
                continue;
            }
            $out[(string) $field] = [
                'name'     => (string) ($f['name'] ?? ''),
                'type'     => (string) ($f['type'] ?? ''),
                'tmp_name' => (string) ($f['tmp_name'] ?? ''),
                'error'    => (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE),
                'size'     => (int) ($f['size'] ?? 0),
            ];
        }
        return $out;
    }

    /** Hochgeladene Datei eines Feldes (oder null, wenn keine übertragen wurde). */
    public function file(string $key): ?array
    {
        $f = $this->files[$key] ?? null;
        if ($f === null || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        return $f;
    }

    /** Test-/Utility-Fabrik: baut einen Request mit JSON-Body (Content-Type application/json). */
    public static function fromJson(string $method, string $path, array $json, array $server = []): self
    {
        $server['CONTENT_TYPE'] = 'application/json';
        return new self($method, $path, [], $json, $server);
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $this->query[$key] ?? $default;
    }

    public function str(string $key, string $default = ''): string
    {
        $v = $this->input($key, $default);
        return is_string($v) ? trim($v) : $default;
    }

    public function all(): array
    {
        return array_merge($this->query, $this->post);
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    public function wantsJson(): bool
    {
        $accept = $this->server['HTTP_ACCEPT'] ?? '';
        return str_contains((string) $accept, 'application/json');
    }

    public function ip(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function userAgent(): string
    {
        return substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $this->server[$key] ?? null;
    }
}
