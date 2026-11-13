<?php

declare(strict_types=1);

namespace Kestello\Cms\Api;

use Kestello\Framework\Core\Response;

/**
 * Whether an endpoint's answer is what it declares: its status(), and the
 * shape of "data" from response() or resource(). The API checks every
 * answer in debug mode, so the test suite checks every endpoint it calls,
 * and what OpenAPI and the TypeScript client promise stays true.
 *
 * Fields a resource() answer carries beyond its properties() are allowed:
 * plugins add them (ResourceExtension, the api.response.<name> filter). A
 * response() schema describes the whole answer, so unknown fields are not.
 */
final class AnswerContract
{
    /** Whether the endpoint says what it answers: a shape, or nothing (204). */
    public static function describes(Endpoint $endpoint): bool
    {
        return $endpoint->status() === 204 || self::schema($endpoint) !== null;
    }

    /** The shape of one item of "data", or null when the endpoint declares none. */
    public static function schema(Endpoint $endpoint): ?Schema
    {
        $response = $endpoint->response();
        if ($response !== null) {
            return $response;
        }
        $resource = $endpoint->resource();
        if ($resource === null || !is_subclass_of($resource, Resource::class) || $resource::properties() === []) {
            return null;
        }

        // a property described as raw OpenAPI (not a Schema) is checked for being there only
        return Schema::object(array_map(static fn(mixed $p): Schema => $p instanceof Schema ? $p : Schema::any()->nullable(), $resource::properties()));
    }

    /**
     * What is wrong with an answer, or [] when it is what the endpoint declares.
     * Problems (4xx, 5xx) and redirects are not checked: ApiProblem shapes them.
     *
     * @return list<string>
     */
    public static function check(Endpoint $endpoint, Response $response): array
    {
        $status = $response->statusCode();
        if ($status >= 300) {
            return [];
        }
        if ($status !== $endpoint->status()) {
            return ["answered {$status}, but status() says " . $endpoint->status()];
        }
        if ($status === 204) {
            return [];
        }
        $schema = self::schema($endpoint);
        if ($schema === null || !str_contains(strtolower((string) ($response->headers()['Content-Type'] ?? '')), 'json')) {
            return [];
        }
        $body = json_decode($response->content(), true);
        if (!is_array($body) || !array_key_exists('data', $body)) {
            return ['the answer has no "data"'];
        }
        $data = $body['data'];
        if ($endpoint->collection() && (!is_array($data) || !array_is_list($data))) {
            return ['"data" is not a list, but collection() says it is'];
        }
        $open = $endpoint->response() === null;     // a resource: plugins may add fields
        $wrong = [];
        foreach ($endpoint->collection() ? $data : [$data] as $i => $item) {
            if ($open && is_array($item)) {
                $item = array_intersect_key($item, $schema->properties());
            }
            [, $errors] = $schema->validate($item);
            foreach ($errors as $path => $messages) {
                $at = $endpoint->collection() ? "data.{$i}" : 'data';
                $wrong[] = ($path === '_' ? $at : "{$at}.{$path}") . ': ' . implode(' ', $messages);
            }
        }

        return $wrong;
    }
}
