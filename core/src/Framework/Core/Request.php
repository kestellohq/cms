<?php

declare(strict_types=1);

namespace Kestello\Framework\Core;

/**
 * Value-object wrapper around the incoming HTTP request.
 */
final class Request
{
    /**
     * @param string                          $method  HTTP method (GET, POST, ...).
     * @param string                          $uri     Full request URI including query string.
     * @param string                          $path    URI path without query string.
     * @param array<string, string>           $headers Request headers.
     * @param array<string, mixed>            $query   GET parameters.
     * @param array<string, mixed>            $post    POST parameters.
     * @param array<string, string>           $cookies Cookie values.
     * @param array<string, mixed>            $files   Uploaded files ($_FILES).
     * @param array<string, mixed>            $server  Server/environment variables.
     */
    public function __construct(
        public readonly string $method,
        public readonly string $uri,
        public readonly string $path,
        public readonly array $headers,
        public readonly array $query,
        public readonly array $post,
        public readonly array $cookies,
        public readonly array $files,
        public readonly array $server,
        private ?string $rawBody = null,
    ) {}

    /**
     * The raw request body (e.g. an upload chunk), at most $max bytes; null
     * when it is longer. Read once from php://input unless given.
     */
    public function content(int $max = 16_777_216): ?string
    {
        if ($this->rawBody === null) {
            $this->rawBody = (string) file_get_contents('php://input', length: $max + 1);
        }

        return strlen($this->rawBody) > $max ? null : $this->rawBody;
    }

    /**
     * Build a Request from PHP super-globals.
     */
    public static function createFromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        $headers = self::extractHeaders($_SERVER);

        return new self(
            method: $method,
            uri: $uri,
            path: $path,
            headers: $headers,
            query: $_GET,
            post: self::parseBody($method, $_SERVER['CONTENT_TYPE'] ?? '', $_POST),
            cookies: $_COOKIE,
            files: $_FILES,
            server: $_SERVER,
        );
    }

    public const int MAX_JSON_BYTES = 1048576;

    /**
     * Decode JSON request bodies (the Vue router and API clients send JSON).
     * Form posts keep using $_POST. Bodies over 1 MB or nested deeper than
     * 32 levels are ignored rather than decoded.
     *
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    public static function parseBody(string $method, string $contentType, array $post, ?string $raw = null): array
    {
        if ($method === 'GET' || $method === 'HEAD' || !str_starts_with(strtolower(trim($contentType)), 'application/json')) {
            return $post;
        }

        $raw ??= (string) file_get_contents('php://input', length: self::MAX_JSON_BYTES + 1);
        if ($raw === '' || strlen($raw) > self::MAX_JSON_BYTES) {
            return $post;
        }

        try {
            $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $post;
        }

        return is_array($data) ? $data : $post;
    }

    /**
     * Retrieve an input value from POST first, then GET.
     */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $this->query[$key] ?? $default;
    }

    /**
     * Check whether the request uses the given HTTP method.
     */
    public function isMethod(string $method): bool
    {
        return strtoupper($this->method) === strtoupper($method);
    }

    /**
     * Determine if the request was made via AJAX / XMLHttpRequest.
     */
    public function isAjax(): bool
    {
        return strtolower($this->header('X-Requested-With', '')) === 'xmlhttprequest';
    }

    /**
     * The client IP address. X-Forwarded-For is only honoured when the
     * connection comes from a configured trusted proxy (see TrustedProxies).
     */
    public function ip(): string
    {
        return \Kestello\Framework\Http\TrustedProxies::clientIp(
            (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0'),
            (string) ($this->server['HTTP_X_FORWARDED_FOR'] ?? ''),
        );
    }

    /**
     * Whether the request is for a state-changing method.
     */
    public function isUnsafe(): bool
    {
        return !in_array(strtoupper($this->method), ['GET', 'HEAD', 'OPTIONS'], true);
    }

    /**
     * The bearer token from the Authorization header, or null.
     */
    public function bearerToken(): ?string
    {
        $header = trim($this->header('Authorization'));

        if (stripos($header, 'Bearer ') !== 0) {
            return null;
        }

        $token = trim(substr($header, 7));

        return $token === '' ? null : $token;
    }

    /**
     * Return a single URI path segment (1-indexed).
     *
     * For /users/42/edit: segment(1) = "users", segment(2) = "42", segment(3) = "edit".
     */
    public function segment(int $index): ?string
    {
        $segments = array_values(array_filter(explode('/', $this->path), fn(string $s): bool => $s !== ''));

        return $segments[$index - 1] ?? null;
    }

    /**
     * Return a request header value (case-insensitive lookup).
     */
    public function header(string $name, string $default = ''): string
    {
        $normalized = strtolower($name);

        foreach ($this->headers as $key => $value) {
            if (strtolower($key) === $normalized) {
                return $value;
            }
        }

        return $default;
    }

    // ------------------------------------------------------------------
    // Internal
    // ------------------------------------------------------------------

    /**
     * Extract HTTP headers from the $_SERVER array.
     *
     * @param  array<string, mixed> $server
     * @return array<string, string>
     */
    private static function extractHeaders(array $server): array
    {
        $headers = [];

        foreach ($server as $key => $value) {
            if (!is_string($value)) {
                continue;
            }

            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', substr($key, 5));
                $headers[$name] = $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                $headers[str_replace('_', '-', $key)] = $value;
            }
        }

        return $headers;
    }
}
