<?php

declare(strict_types=1);

namespace Kestello\Framework\Http\Client;

use InvalidArgumentException;

/**
 * HTTP to one service an administrator configured (a Meilisearch or
 * Typesense server, an internal API), often on a private network.
 *
 * Plugins can't reach private addresses or other ports through HttpClient.
 * Instead, a driver lists the option holding the service's address
 * (DriverDefinition::$endpoints) and core hands its factory one of these,
 * locked to that origin: every request stays on it, redirects are not
 * followed, and only that address gets the private-network exception.
 *
 *   factory: fn(array $o) => new MeilisearchEngine($o['clients']['url'], $o['key'])
 *   $client->post('/indexes/content/search', ['json' => [...], 'bearer' => $key]);
 *
 * @api
 */
final class ServiceClient
{
    private readonly string $base;
    private readonly string $origin;

    public function __construct(private readonly HttpClient $http, string $baseUrl)
    {
        $parts = parse_url(rtrim(trim($baseUrl), '/'));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!is_array($parts) || !in_array($scheme, ['http', 'https'], true) || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('A service address is a plain http(s) URL such as http://meilisearch:7700.');
        }
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $this->origin = $scheme . '://' . strtolower($parts['host']) . $port;
        $this->base = $this->origin . rtrim((string) ($parts['path'] ?? ''), '/');
    }

    public function baseUrl(): string
    {
        return $this->base;
    }

    /** @param array<string, mixed> $options HttpClient options (json, query, headers, bearer, timeout…) */
    public function request(string $method, string $path, array $options = []): HttpResponse
    {
        if (!str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '..') || preg_match('/[\x00-\x20\\\\#]/', $path) === 1) {
            throw new HttpBlocked("Refused service path \"{$path}\".");
        }
        $url = $this->base . $path;

        return $this->http->requestAsService($method, $url, array_diff_key($options, ['allow_private' => true, 'max_redirects' => true, 'sink' => true, 'body_stream' => true]));
    }

    /** @param array<string, mixed> $options */
    public function get(string $path, array $options = []): HttpResponse
    {
        return $this->request('GET', $path, $options);
    }
    /** @param array<string, mixed> $options */
    public function post(string $path, array $options = []): HttpResponse
    {
        return $this->request('POST', $path, $options);
    }
    /** @param array<string, mixed> $options */
    public function put(string $path, array $options = []): HttpResponse
    {
        return $this->request('PUT', $path, $options);
    }
    /** @param array<string, mixed> $options */
    public function patch(string $path, array $options = []): HttpResponse
    {
        return $this->request('PATCH', $path, $options);
    }
    /** @param array<string, mixed> $options */
    public function delete(string $path, array $options = []): HttpResponse
    {
        return $this->request('DELETE', $path, $options);
    }
}
