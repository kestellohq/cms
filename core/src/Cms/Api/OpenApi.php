<?php

declare(strict_types=1);

namespace Kestello\Cms\Api;

use Kestello\Contracts\Auth\Principal;
use Kestello\Framework\Session\Session;
use Kestello\Kernel;

/**
 * Builds the OpenAPI 3.0 document from endpoint declarations. Callers only
 * see endpoints they could call (public ones, plus those their role allows),
 * so the document doesn't advertise every installed plugin to guests.
 */
final class OpenApi
{
    public function __construct(private readonly ApiRegistry $api) {}

    /** @return array<string, mixed> */
    public function build(Principal $principal, string $serverUrl = ''): array
    {
        $paths = [];
        $tags = [];

        foreach ($this->api->endpoints() as ['endpoint' => $endpoint, 'owner' => $owner, 'route' => $route]) {
            if (!$endpoint->isPublic() && !$principal->can((string) $endpoint->capability())) {
                continue;
            }

            $operation = [
                'operationId' => self::operationId($endpoint::class),
                'summary' => $endpoint->summary() ?: null,
                'tags' => $endpoint->tags() ?: [self::defaultTag($route->path)],
                'parameters' => [...self::pathParameters($route), ...self::queryParameters($endpoint->query())],
                'responses' => self::responses($endpoint),
            ];

            if ($owner !== 'core') {
                $operation['x-kestello-owner'] = $owner;
            }
            if (!$endpoint->isPublic()) {
                $operation['security'] = [['session' => []], ['bearer' => $endpoint->scopes()]];
                $operation['x-kestello-capability'] = $endpoint->capability();
            } else {
                $operation['security'] = [];
            }
            if ($endpoint->requiresRecentAuth()) {
                $operation['x-kestello-recent-auth'] = true;
            }
            if ($endpoint->body() !== null) {
                $operation['requestBody'] = [
                    'required' => true,
                    'content' => ['application/json' => ['schema' => $endpoint->body()->toOpenApi()]],
                ];
            }

            foreach ($operation['tags'] as $tag) {
                $tags[$tag] = true;
            }

            $paths[$route->path][strtolower($route->method)] = array_filter($operation, static fn($v) => $v !== null && $v !== []) + ['security' => $operation['security']];
        }

        ksort($paths);

        $scopes = [];
        foreach ($this->api->scopes() as $name => $scope) {
            $scopes[$name] = $scope['description'];
        }

        return [
            'openapi' => '3.0.3',
            'info' => ['title' => 'Kestello API', 'version' => 'v1', 'x-kestello-version' => ($principal->can('core.update') || $principal->can('plugins.manage')) ? Kernel::version() : null],
            'servers' => [['url' => rtrim($serverUrl, '/') . ApiRegistry::PREFIX]],
            'tags' => array_map(static fn(string $t): array => ['name' => $t], array_keys($tags)),
            'paths' => $paths === [] ? (object) [] : $paths,
            'components' => [
                'securitySchemes' => [
                    'session' => ['type' => 'apiKey', 'in' => 'cookie', 'name' => Session::NAME, 'description' => 'Browser session. Unsafe methods also need the X-CSRF-TOKEN header.'],
                    'bearer' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'Personal access token (kst_pat_...). Scopes: ' . implode(', ', array_keys($scopes))],
                ],
                'schemas' => [
                    'Problem' => [
                        'type' => 'object',
                        'properties' => [
                            'type' => ['type' => 'string'], 'title' => ['type' => 'string'], 'status' => ['type' => 'integer'],
                            'detail' => ['type' => 'string'],
                            'errors' => ['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']]],
                        ],
                    ],
                ],
                'x-scopes' => $scopes,
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function pathParameters(ApiRoute $route): array
    {
        return array_map(static fn(string $name): array => [
            'name' => $name,
            'in' => 'path',
            'required' => true,
            'schema' => preg_match('/^(?:\\\\d|\[0-9\])(?:\+|\{\d+(?:,\d*)?\})$/', $route->constraints[$name] ?? '') === 1 ? ['type' => 'integer'] : ['type' => 'string'],
        ], $route->params());
    }

    /** @return list<array<string, mixed>> */
    private static function queryParameters(?Schema $query): array
    {
        if ($query === null) {
            return [];
        }

        $out = [];
        foreach ($query->properties() as $name => $schema) {
            $param = ['name' => $name, 'in' => 'query', 'required' => !$schema->isOptional(), 'schema' => $schema->toOpenApi()];
            if ($schema->type() === 'object') {
                $param['style'] = 'deepObject';
                $param['explode'] = true;
            }
            $out[] = $param;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private static function responses(Endpoint $endpoint): array
    {
        $problem = ['description' => 'Problem', 'content' => ['application/problem+json' => ['schema' => ['$ref' => '#/components/schemas/Problem']]]];
        $data = ['type' => 'object'];
        $resource = $endpoint->resource();
        if ($endpoint->response() !== null) {
            $data = $endpoint->response()->toOpenApi();
        } elseif ($resource !== null && is_subclass_of($resource, Resource::class) && $resource::properties() !== []) {
            $props = $resource::properties();
            $data = array_filter($props, static fn(mixed $p): bool => !$p instanceof Schema) === []
                ? Schema::object($props)->toOpenApi()
                : ['type' => 'object', 'properties' => array_map(static fn(mixed $p): mixed => $p instanceof Schema ? $p->toOpenApi() : $p, $props), 'required' => array_keys($props)];
            unset($data['additionalProperties']);        // answers may carry fields plugins add
        }
        if ($endpoint->collection()) {
            $data = ['type' => 'array', 'items' => $data];
        }

        $status = $endpoint->status();
        $responses = $status === 204
            ? ['204' => ['description' => 'Done; nothing to return']]
            : [(string) $status => ['description' => $status === 201 ? 'Created' : 'OK', 'content' => $endpoint->produces() !== null
                ? [$endpoint->produces() => ['schema' => $endpoint->produces() === 'application/json' ? ['type' => 'object'] : ['type' => 'string', 'format' => 'binary']]]     // a file or a document of its own
                : ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['data' => $data, 'meta' => ['type' => 'object']], 'required' => ['data']]]]]];
        if ($status !== 204 && $endpoint->route()->method === 'DELETE' && !AnswerContract::describes($endpoint)) {
            $responses['204'] = ['description' => 'Done; nothing to return'];     // not described yet: most removals answer nothing
        }
        foreach ($endpoint->problems() as $code) {
            $responses[(string) $code] = $problem;
        }
        $responses['422'] = $problem;
        $responses['429'] = $problem;
        if (!$endpoint->isPublic()) {
            $responses['401'] = $problem;
            $responses['403'] = $problem;
        }

        return $responses;
    }

    private static function operationId(string $class): string
    {
        $parts = explode('\\', $class);

        return lcfirst((string) end($parts));
    }

    private static function defaultTag(string $path): string
    {
        return explode('/', trim($path, '/'))[0] ?: 'api';
    }
}
