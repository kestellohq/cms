<?php

declare(strict_types=1);

namespace Kestello\Framework\Http\Client;

use Closure;

/**
 * For tests: answers from a route table and records every request.
 *
 *   $fake = new FakeTransport(['GET https://api.example.com/me' => ['status' => 200, 'json' => ['id' => 1]]]);
 *   $fake->on('POST https://idp.test/token', fn(PendingRequest $r) => new HttpResponse(200, [], '{}'));
 *
 * Keys are "METHOD url" with the query string ignored; "*" matches any method.
 *
 * @api
 */
final class FakeTransport implements Transport
{
    /** @var list<PendingRequest> */
    public array $sent = [];

    /** @var array<string, Closure(PendingRequest): HttpResponse|array> */
    private array $routes = [];

    /** @param array<string, array<string, mixed>|Closure> $routes */
    public function __construct(array $routes = [])
    {
        foreach ($routes as $key => $response) {
            $this->on($key, $response);
        }
    }

    /** @param array{status?: int, json?: mixed, body?: string, headers?: array<string, string>}|Closure(PendingRequest): HttpResponse $response */
    public function on(string $key, array|Closure $response): self
    {
        $this->routes[$key] = $response instanceof Closure ? $response : static fn() => $response;

        return $this;
    }

    public function send(PendingRequest $request): HttpResponse
    {
        $this->sent[] = $request;
        $base = strtok($request->url, '?');
        $handler = $this->routes[$request->method . ' ' . $base] ?? $this->routes['* ' . $base] ?? null;
        if ($handler === null) {
            throw new HttpException("FakeTransport: no route for {$request->method} {$base}.");
        }

        $result = $handler($request);
        if ($result instanceof HttpResponse) {
            if ($request->sink !== null) {
                fwrite($request->sink, $result->body);
                return new HttpResponse($result->status, $result->headers, '', $result->url);
            }
            return $result;
        }

        $headers = [];
        foreach ($result['headers'] ?? [] as $k => $v) {
            $headers[strtolower($k)] = [$v];
        }
        $body = array_key_exists('json', $result) ? json_encode($result['json'], JSON_THROW_ON_ERROR) : (string) ($result['body'] ?? '');

        if ($request->sink !== null) {
            fwrite($request->sink, $body);
            $body = '';
        }

        return new HttpResponse((int) ($result['status'] ?? 200), $headers, $body, $request->url);
    }
}
