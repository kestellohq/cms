<?php

declare(strict_types=1);

namespace Kestello\Cms\Api;

use InvalidArgumentException;

/**
 * Method + path of an endpoint, relative to /api/v1.
 *
 *   ApiRoute::get('/users/{id}')->where('id', '\d+')
 *
 * @api
 */
final readonly class ApiRoute
{
    /** @param array<string, string> $constraints */
    private function __construct(
        public string $method,
        public string $path,
        public array $constraints = [],
    ) {
        if (!preg_match('#^(/[a-z0-9._-]+|/\{[a-z_][a-z0-9_]*\})+$#', $path)) {
            throw new InvalidArgumentException("Invalid API path [{$path}]: use lowercase segments and {params}.");
        }
    }

    public static function get(string $path): self
    {
        return new self('GET', $path);
    }
    public static function post(string $path): self
    {
        return new self('POST', $path);
    }
    public static function put(string $path): self
    {
        return new self('PUT', $path);
    }
    public static function patch(string $path): self
    {
        return new self('PATCH', $path);
    }
    public static function delete(string $path): self
    {
        return new self('DELETE', $path);
    }

    public function where(string $param, string $regex): self
    {
        return new self($this->method, $this->path, [...$this->constraints, $param => $regex]);
    }

    /** @return list<string> */
    public function params(): array
    {
        preg_match_all('/\{([a-z_][a-z0-9_]*)\}/', $this->path, $m);

        return $m[1];
    }

    public function isUnsafe(): bool
    {
        return $this->method !== 'GET';
    }
}
