<?php

declare(strict_types=1);

namespace GoldBot\Core;

/**
 * An immutable snapshot of the incoming HTTP request.
 *
 * Superglobals are read exactly once, here. Everything downstream receives
 * this object, which is what lets controllers and middleware be tested by
 * constructing a request rather than mutating $_SERVER.
 */
final class Request
{
    /**
     * @param array<string,mixed>  $query
     * @param array<string,mixed>  $post
     * @param array<string,mixed>  $server
     * @param array<string,string> $cookies
     * @param array<string,mixed>  $files
     */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query = [],
        private readonly array $post = [],
        private readonly array $server = [],
        private readonly array $cookies = [],
        private readonly array $files = [],
        private readonly string $rawBody = ''
    ) {
    }

    public static function capture(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        // Browsers can only send GET and POST from a form, so PUT/PATCH/DELETE
        // arrive as a POST carrying _method. Honouring it only for POST stops
        // a GET being escalated into a destructive verb via the query string.
        if ($method === 'POST' && isset($_POST['_method'])) {
            $spoofed = strtoupper((string) $_POST['_method']);

            if (in_array($spoofed, ['PUT', 'PATCH', 'DELETE'], true)) {
                $method = $spoofed;
            }
        }

        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);

        return new self(
            $method,
            '/' . trim(is_string($path) ? $path : '/', '/'),
            $_GET,
            $_POST,
            $_SERVER,
            array_map(strval(...), $_COOKIE),
            $_FILES,
            (string) file_get_contents('php://input')
        );
    }

    public function method(): string
    {
        return $this->method;
    }

    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $this->query[$key] ?? $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->input($key);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->input($key);

        if ($value === null) {
            return $default;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->post) || array_key_exists($key, $this->query);
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return [...$this->query, ...$this->post];
    }

    /** @return array<string,mixed> */
    public function query(): array
    {
        return $this->query;
    }

    public function cookie(string $key, ?string $default = null): ?string
    {
        return $this->cookies[$key] ?? $default;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

        $value = $this->server[$key]
            ?? $this->server[strtoupper(str_replace('-', '_', $name))]
            ?? $default;

        return $value === null ? null : (string) $value;
    }

    public function rawBody(): string
    {
        return $this->rawBody;
    }

    /** @return array<string,mixed>|null */
    public function json(): ?array
    {
        if ($this->rawBody === '') {
            return null;
        }

        $decoded = json_decode($this->rawBody, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * The client IP.
     *
     * Proxy headers are deliberately NOT trusted: on shared hosting anyone can
     * send X-Forwarded-For, and believing it would let an attacker evade the
     * per-IP login throttle by varying the header. If a trusted reverse proxy
     * is ever introduced, this is the one place to change.
     */
    public function ip(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    /** The IP packed for VARBINARY(16) storage, or null if unparseable. */
    public function ipBinary(): ?string
    {
        $packed = @inet_pton($this->ip());

        return $packed === false ? null : $packed;
    }

    public function userAgent(): string
    {
        return substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public function isSecure(): bool
    {
        return ($this->server['HTTPS'] ?? '') !== ''
            && strtolower((string) $this->server['HTTPS']) !== 'off';
    }

    /** True when the caller expects JSON rather than a rendered page. */
    public function wantsJson(): bool
    {
        $accept = (string) $this->header('Accept', '');

        return str_contains($accept, 'application/json')
            || $this->header('X-Requested-With') === 'XMLHttpRequest'
            || str_starts_with($this->path, '/internal/');
    }

    public function withPath(string $path): self
    {
        return new self(
            $this->method,
            $path,
            $this->query,
            $this->post,
            $this->server,
            $this->cookies,
            $this->files,
            $this->rawBody
        );
    }
}
