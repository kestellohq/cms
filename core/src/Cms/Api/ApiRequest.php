<?php

declare(strict_types=1);

namespace Kestello\Cms\Api;

use Kestello\Contracts\Auth\Principal;
use Kestello\Framework\Core\Request;

/**
 * What an endpoint receives: the principal plus validated input.
 *
 * @api
 */
final readonly class ApiRequest
{
    /**
     * @param array<string, string> $params validated route parameters
     * @param array<string, mixed>  $query  validated query (schema defaults applied)
     * @param array<string, mixed>  $body   validated body (only declared keys)
     */
    public function __construct(
        public Request $http,
        public Principal $principal,
        public array $params = [],
        public array $query = [],
        public array $body = [],
        public string $requestId = '',
    ) {}

    public function param(string $name): ?string
    {
        return $this->params[$name] ?? null;
    }

    public function intParam(string $name): int
    {
        $value = $this->params[$name] ?? '';

        if (!preg_match('/^\d{1,18}$/', $value)) {
            throw ApiProblem::notFound();
        }

        return (int) $value;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    /** Whether the client sent the key (for PATCH semantics). */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->body);
    }

    public function userId(): ?int
    {
        return $this->principal->userId;
    }
}
