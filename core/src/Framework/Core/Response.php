<?php

declare(strict_types=1);

namespace Kestello\Framework\Core;

/**
 * HTTP response that can be built fluently, then sent.
 */
final class Response
{
    /** @var array<string, string> Response headers. */
    private array $headers;

    /** @var list<array{name: string, value: string, options: array<string, mixed>}> Cookies to set. */
    private array $cookies = [];

    /**
     * @param string               $content    Response body.
     * @param int                  $statusCode HTTP status code.
     * @param array<string, string> $headers    Initial response headers.
     */
    private ?object $payload = null;

    /** @var resource|null Streamed body (files); replaces $content when set. */
    private mixed $stream = null;

    public function __construct(
        private string $content = '',
        private int $statusCode = 200,
        array $headers = [],
    ) {
        $this->headers = $headers;
    }

    // ------------------------------------------------------------------
    // Static factories
    // ------------------------------------------------------------------

    /**
     * Create an HTML response.
     */
    public static function html(string $body, int $status = 200): self
    {
        return new self(
            content: $body,
            statusCode: $status,
            headers: ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }

    /**
     * Stream a resource as the body (closed after sending).
     *
     * @param resource              $stream
     * @param array<string, string> $headers
     */
    public static function stream(mixed $stream, int $status = 200, array $headers = []): self
    {
        $response = new self('', $status, $headers);
        $response->stream = $stream;

        return $response;
    }

    /** @return resource|null */
    public function bodyStream(): mixed
    {
        return $this->stream;
    }

    /**
     * A response whose body is produced later by middleware (e.g. a Vue page
     * rendered by the CMS frontend). The payload is opaque to the framework.
     */
    public static function deferred(object $payload, int $status = 200): self
    {
        $response = new self('', $status);
        $response->payload = $payload;

        return $response;
    }

    public function payload(): ?object
    {
        return $this->payload;
    }

    /**
     * Create a JSON response.
     *
     * @param mixed $data Data to encode as JSON.
     */
    public static function json(mixed $data, int $status = 200): self
    {
        return new self(
            content: (string) json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            statusCode: $status,
            headers: ['Content-Type' => 'application/json; charset=UTF-8'],
        );
    }

    /**
     * Create a redirect response.
     */
    public static function redirect(string $url, int $status = 302): self
    {
        return new self(
            content: '',
            statusCode: $status,
            headers: ['Location' => $url],
        );
    }

    /**
     * Create a 404 Not Found response.
     */
    public static function notFound(string $body = '404 Not Found'): self
    {
        return new self(
            content: $body,
            statusCode: 404,
            headers: ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }

    // ------------------------------------------------------------------
    // Fluent modifiers
    // ------------------------------------------------------------------

    /**
     * Return a new instance with the given header set.
     */
    public function withHeader(string $name, string $value): static
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;

        return $clone;
    }

    public function withStatus(int $status): static
    {
        $clone = clone $this;
        $clone->statusCode = $status;

        return $clone;
    }

    /**
     * Return a new instance with a different body (headers and cookies kept).
     */
    public function withContent(string $content): static
    {
        $clone = clone $this;
        $clone->content = $content;

        return $clone;
    }

    /**
     * Return a new instance that will set the given cookie on send.
     *
     * @param array{expires?: int, path?: string, domain?: string, secure?: bool, httponly?: bool, samesite?: string} $options
     */
    public function withCookie(string $name, string $value, array $options = []): static
    {
        $clone = clone $this;
        $clone->cookies[] = ['name' => $name, 'value' => $value, 'options' => $options];

        return $clone;
    }

    // ------------------------------------------------------------------
    // Accessors
    // ------------------------------------------------------------------

    /** @return list<array{name: string, value: string, options: array<string, mixed>}> */
    public function cookies(): array
    {
        return $this->cookies;
    }

    public function content(): string
    {
        return $this->content;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    // ------------------------------------------------------------------
    // Sending
    // ------------------------------------------------------------------

    /**
     * Emit the HTTP response (status line, headers, cookies, body).
     */
    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->statusCode);

            foreach ($this->headers as $name => $value) {
                header("{$name}: {$value}");
            }

            foreach ($this->cookies as $cookie) {
                setcookie(
                    $cookie['name'],
                    $cookie['value'],
                    [
                        'expires'  => (int) ($cookie['options']['expires'] ?? 0),
                        'path'     => (string) ($cookie['options']['path'] ?? '/'),
                        'domain'   => (string) ($cookie['options']['domain'] ?? ''),
                        'secure'   => (bool) ($cookie['options']['secure'] ?? false),
                        'httponly' => (bool) ($cookie['options']['httponly'] ?? true),
                        'samesite' => (string) ($cookie['options']['samesite'] ?? 'Lax'),
                    ],
                );
            }
        }

        if ($this->stream !== null) {
            if (is_resource($this->stream)) {
                while (ob_get_level() > 0) {
                    ob_end_flush();
                }
                fpassthru($this->stream);
                fclose($this->stream);
            }
            return;
        }

        echo $this->content;
    }
}
