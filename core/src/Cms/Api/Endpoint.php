<?php

declare(strict_types=1);

namespace Kestello\Cms\Api;

/**
 * One API endpoint, declared once: route, authorization, input schemas,
 * output resource and rate limit. Routing, validation, authorization and
 * the OpenAPI document all come from this declaration.
 *
 * Deny by default: an endpoint must either be isPublic(), or declare a
 * capability AND at least one scope, or it is not registered.
 *
 *   final class ListBooks extends Endpoint
 *   {
 *       public function route(): ApiRoute      { return ApiRoute::get('/acme-books/books'); }
 *       public function capability(): ?string  { return 'acme-books.read'; }
 *       public function scopes(): array        { return ['acme-books:read']; }
 *       public function query(): ?Schema       { return Schema::object(['limit' => Schema::int()->min(1)->max(100)->default(25)]); }
 *       public function handle(ApiRequest $request): mixed { return BookResource::collection(...); }
 *   }
 *
 * handle() may return a Resource, a ResourceCollection, an array (sent as
 * {"data": ...}), a Response, or null (204 No Content). Throw ApiProblem
 * for errors. Constructor dependencies are injected from the container.
 *
 * @api
 */
abstract class Endpoint
{
    abstract public function route(): ApiRoute;

    abstract public function handle(ApiRequest $request): mixed;

    /** Reachable without authentication (capability and scopes are then ignored). */
    public function isPublic(): bool
    {
        return false;
    }

    /** The capability the user must hold (from their role). */
    public function capability(): ?string
    {
        return null;
    }

    /**
     * Scopes a token needs (all of them). Sessions are not scope-limited.
     *
     * @return list<string>
     */
    public function scopes(): array
    {
        return [];
    }

    /** Query-string schema (values arrive as strings and are coerced). */
    public function query(): ?Schema
    {
        return null;
    }

    /** JSON body schema. Unknown keys are rejected. */
    public function body(): ?Schema
    {
        return null;
    }

    /**
     * Object-level authorization (policies), after the capability check.
     * Return false (or throw ApiProblem) to deny. Use it against IDOR.
     */
    public function authorize(ApiRequest $request): bool
    {
        return true;
    }

    /** Destructive actions: session users must have authenticated in the last 10 minutes; tokens are refused. */
    public function requiresRecentAuth(): bool
    {
        return false;
    }

    /**
     * Public writes from pages that can't carry a session token (a form on
     * a cached page): instead of the CSRF header, the request must come
     * from this site (Origin, or Referer when there is no Origin). Bring
     * your own abuse checks (a signed token, a honeypot, a rate limit).
     */
    public function sameOriginOnly(): bool
    {
        return false;
    }

    /**
     * [max requests, per seconds] for this endpoint, per user (or IP for guests). Null = API default.
     *
     * @return array{int, int}|null
     */
    public function rateLimit(): ?array
    {
        return null;
    }

    /** The resource class this endpoint returns (documents the response in OpenAPI). */
    public function resource(): ?string
    {
        return null;
    }

    /**
     * The shape of "data" in the answer, for OpenAPI and the generated
     * TypeScript client. Describe one item; collection() says whether data
     * is a list of them. Takes precedence over resource().
     */
    public function response(): ?Schema
    {
        return null;
    }

    /**
     * The status of a successful answer: 200, 201 when it made something,
     * or 204 when there is nothing to return (handle() returns null). In
     * debug mode an answer with another status is an error.
     */
    public function status(): int
    {
        return 200;
    }

    /**
     * The problems this endpoint answers with besides the ones any endpoint
     * can (401, 403, 422, 429): 404 when what the address names does not
     * exist, 409 when the request conflicts with what is stored (a slug
     * taken, an entry changed since it was opened). For OpenAPI and the
     * TypeScript client. By default, 404 for an address with parameters.
     *
     * @return list<int>
     */
    public function problems(): array
    {
        return $this->route()->params() !== [] ? [404] : [];
    }

    /** Whether "data" is a list. Defaults to true for classes named List…. */
    public function collection(): bool
    {
        $class = static::class;

        return str_starts_with(substr($class, (int) strrpos($class, '\\') + 1), 'List');
    }

    public function summary(): string
    {
        return '';
    }

    /** @return list<string> */
    public function tags(): array
    {
        return [];
    }
}
