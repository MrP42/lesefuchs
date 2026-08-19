<?php
declare(strict_types=1);

namespace App\Core;

/**
 * HTTP-Response.
 */
final class Response
{
    /** Gesetzt von fileRange(): Datei wird in send() blockweise gestreamt statt als body-String gehalten. */
    public ?string $streamPath = null;
    public int $streamStart = 0;
    public int $streamLength = 0;

    public function __construct(
        public string $body = '',
        public int $status = 200,
        public array $headers = []
    ) {}

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public static function json(array $data, int $status = 200): self
    {
        return new self(
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            $status,
            ['Content-Type' => 'application/json; charset=UTF-8']
        );
    }

    public static function redirect(string $to, int $status = 302): self
    {
        return new self('', $status, ['Location' => $to]);
    }

    public static function notFound(string $body = 'Nicht gefunden'): self
    {
        return self::html($body, 404);
    }

    /** MIME-Typ anhand der Dateiendung (Paket-Inhalte: WebP, Opus, JSON …). */
    public static function mimeFor(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return self::MIME_TYPES[$ext] ?? 'application/octet-stream';
    }

    private const MIME_TYPES = [
        'html' => 'text/html; charset=UTF-8', 'htm' => 'text/html; charset=UTF-8',
        'css' => 'text/css; charset=UTF-8', 'js' => 'application/javascript; charset=UTF-8',
        'mjs' => 'application/javascript; charset=UTF-8', 'json' => 'application/json; charset=UTF-8',
        'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'ico' => 'image/x-icon',
        'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf',
        'pdf' => 'application/pdf', 'txt' => 'text/plain; charset=UTF-8', 'md' => 'text/plain; charset=UTF-8',
        'opus' => 'audio/ogg', 'ogg' => 'audio/ogg', 'oga' => 'audio/ogg',
        'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'm4a' => 'audio/mp4',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'zip' => 'application/zip', 'lesepaket' => 'application/zip',
    ];

    /** Datei-Antwort mit passendem Content-Type (für gehostete Schulungsinhalte/Downloads). */
    public static function file(string $absolutePath, ?string $downloadName = null): self
    {
        $ext = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
        $types = [
            'html' => 'text/html; charset=UTF-8', 'htm' => 'text/html; charset=UTF-8',
            'css' => 'text/css; charset=UTF-8', 'js' => 'application/javascript; charset=UTF-8',
            'mjs' => 'application/javascript; charset=UTF-8', 'json' => 'application/json; charset=UTF-8',
            'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif', 'webp' => 'image/webp', 'ico' => 'image/x-icon',
            'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf',
            'pdf' => 'application/pdf', 'txt' => 'text/plain; charset=UTF-8', 'md' => 'text/plain; charset=UTF-8',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'zip' => 'application/zip',
        ];
        $ctype = $types[$ext] ?? 'application/octet-stream';
        $headers = ['Content-Type' => $ctype];
        // Office-/PDF-/Zip-Dateien als Download anbieten
        if ($downloadName !== null || in_array($ext, ['docx', 'xlsx', 'pptx', 'zip'], true)) {
            $name = $downloadName ?? basename($absolutePath);
            $headers['Content-Disposition'] = 'attachment; filename="' . str_replace('"', '', $name) . '"';
        }
        return new self((string) file_get_contents($absolutePath), 200, $headers);
    }

    /**
     * Datei-Antwort mit HTTP-Range-Unterstützung (RFC 7233, ein Bereich) —
     * Grundlage für Opus-Streaming und resümierbare Paket-Downloads am Tablet.
     * Ohne Range-Header: 200 mit kompletter Datei + Accept-Ranges.
     */
    public static function fileRange(string $absolutePath, ?string $rangeHeader, ?string $downloadName = null): self
    {
        $size = filesize($absolutePath);
        if ($size === false) {
            return self::json(['error' => ['code' => 'not_found', 'message' => 'Datei nicht lesbar']], 404);
        }
        $headers = [
            'Content-Type'  => self::mimeFor($absolutePath),
            'Accept-Ranges' => 'bytes',
        ];
        if ($downloadName !== null) {
            $headers['Content-Disposition'] = 'attachment; filename="' . str_replace('"', '', $downloadName) . '"';
        }

        $start = 0;
        $end = $size - 1;
        $status = 200;
        if ($rangeHeader !== null && preg_match('/^bytes=(\d*)-(\d*)$/', trim($rangeHeader), $m)) {
            if ($m[1] === '' && $m[2] === '') {
                // "bytes=-" ist ungültig
            } elseif ($m[1] === '') {
                // Suffix-Range: letzte N Bytes
                $n = min((int) $m[2], $size);
                if ($n === 0) {
                    return self::rangeNotSatisfiable($size);
                }
                $start = $size - $n;
                $status = 206;
            } else {
                $start = (int) $m[1];
                if ($start >= $size) {
                    return self::rangeNotSatisfiable($size);
                }
                if ($m[2] !== '') {
                    $end = min((int) $m[2], $size - 1);
                    if ($end < $start) {
                        return self::rangeNotSatisfiable($size);
                    }
                }
                $status = 206;
            }
        }

        $length = $end - $start + 1;
        $headers['Content-Length'] = (string) $length;
        if ($status === 206) {
            $headers['Content-Range'] = "bytes {$start}-{$end}/{$size}";
        }

        // Nicht in den Speicher laden — send() streamt in 512-KiB-Blöcken
        // (IONOS memory_limit verträgt keine 100-MB-Strings).
        $response = new self('', $status, $headers);
        $response->streamPath = $absolutePath;
        $response->streamStart = $start;
        $response->streamLength = $length;
        return $response;
    }

    private static function rangeNotSatisfiable(int $size): self
    {
        return new self('', 416, ['Content-Range' => "bytes */{$size}", 'Accept-Ranges' => 'bytes']);
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            // Sicherheits-Header (Defaults). HSTS wird hier gesetzt, weil
            // .htaccess-Header-Direktiven auf IONOS-FastCGI-Antworten nicht
            // greifen (Statics bekommen HSTS über public/.htaccess).
            $defaults = [
                'X-Content-Type-Options' => 'nosniff',
                'X-Frame-Options'        => 'SAMEORIGIN',
                'Referrer-Policy'        => 'strict-origin-when-cross-origin',
            ];
            if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
                $defaults['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
            }
            foreach ($defaults as $k => $v) {
                if (!isset($this->headers[$k])) {
                    header("$k: $v");
                }
            }
            foreach ($this->headers as $name => $value) {
                header("$name: $value");
            }
        }
        if ($this->streamPath !== null) {
            $fh = fopen($this->streamPath, 'rb');
            if ($fh !== false) {
                fseek($fh, $this->streamStart);
                $remaining = $this->streamLength;
                while ($remaining > 0 && !feof($fh)) {
                    $chunk = fread($fh, min(524_288, $remaining));
                    if ($chunk === false) {
                        break;
                    }
                    echo $chunk;
                    $remaining -= strlen($chunk);
                    if (PHP_SAPI !== 'cli') {
                        flush();
                    }
                }
                fclose($fh);
            }
            return;
        }
        echo $this->body;
    }

    /** Für Tests: gestreamten Datei-Ausschnitt als String lesen (ohne send()). */
    public function streamedBody(): string
    {
        if ($this->streamPath === null) {
            return $this->body;
        }
        $fh = fopen($this->streamPath, 'rb');
        if ($fh === false) {
            return '';
        }
        fseek($fh, $this->streamStart);
        $data = (string) stream_get_contents($fh, $this->streamLength);
        fclose($fh);
        return $data;
    }
}
