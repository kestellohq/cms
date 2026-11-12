<?php

declare(strict_types=1);

namespace Kestello\Cms\Api;

use Closure;
use InvalidArgumentException;
use Kestello\Cms\Auth\AuthRegistry;
use Kestello\Cms\Auth\InvalidCredentials;
use Kestello\Cms\Auth\Roles;
use Kestello\Cms\Demo\DemoPolicy;
use Kestello\Contracts\Auth\Principal;
use Kestello\Framework\Core\Container;
use Kestello\Framework\Core\ExecutionContext;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Core\Response;
use Kestello\Framework\Core\Router;
use Kestello\Framework\Events\Filters;
use Kestello\Framework\Http\RateLimiter;
use Kestello\Framework\Http\RateLimitResult;
use LogicException;
use Throwable;

/**
 * $cms->api: endpoints, scopes and resource extensions.
 *
 *   $cms->api->scope('acme-seo:write', 'Change SEO settings');
 *   $cms->api->endpoint(AnalyzeEntry::class);       // path must start with /acme-seo/
 *   $cms->api->extend('users')->field('acme_seo_score', fn(array $row) => ...);
 *
 * Every request runs the same pipeline: authenticate → CSRF (cookie
 * requests) → capability ∩ scopes → recent auth → rate limit → validate
 * query/body → policy (authorize) → handle → render. Errors are RFC 9457
 * problems; stack traces never leave the server outside debug mode.
 *
 * @api
 */
final class ApiRegistry
{
    public const string PREFIX = '/api/v1';

    /** Core scopes. Plugin scopes are prefixed with the plugin slug. */
    public const array CORE_SCOPES = [
        'profile' => 'Read your profile and manage your tokens',
        'content:read' => 'Read content, including drafts',
        'content:write' => 'Create, edit and delete content',
        'media:write' => 'Upload and edit media',
        'users:read' => 'List and view users',
        'users:write' => 'Create, edit and delete users',
        'settings:write' => 'Change site settings',
        'audit:read' => 'Read the audit log',
        'security' => 'See everyone\'s sign-in security, and end their sessions and tokens',
        'webhooks:manage' => 'Set up webhooks and read their deliveries',
        'system' => 'Manage infrastructure, jobs and Site Health',
        'fleet' => 'Report this site\'s version, updates, health and backups to a fleet console, and nothing else',
        'admin' => 'Everything your account can do',
    ];

    /** Default limits per principal (user, or IP for guests): [max, seconds]. */
    private const array DEFAULT_READ_LIMIT = [300, 60];
    private const array DEFAULT_WRITE_LIMIT = [60, 60];

    /** @var array<string, array{description: string, owner: string}> */
    private array $scopes = [];

    /** @var list<array{endpoint: Endpoint, owner: string, route: ApiRoute}> */
    private array $endpoints = [];

    /** @var array<string, ResourceExtension> */
    private array $extensions = [];

    /**
     * @param Closure(Request): bool $csrf validates the X-CSRF-TOKEN header of cookie requests
     */
    public function __construct(
        private readonly Router $router,
        private readonly AuthRegistry $auth,
        private readonly Container $container,
        private readonly Filters $filters,
        private readonly RateLimiter $limiter,
        private readonly Closure $csrf,
        private readonly bool $debug = false,
        private readonly bool $demo = false,
    ) {
        foreach (self::CORE_SCOPES as $name => $description) {
            $this->scopes[$name] = ['description' => $description, 'owner' => 'core'];
        }
    }

    // ── Registration ────────────────────────────────────────────────

    public function scope(string $name, string $description): void
    {
        $owner = ExecutionContext::current();
        Roles::assertOwnedName($name, $owner, ':');

        if (isset($this->scopes[$name]) && $this->scopes[$name]['owner'] !== $owner) {
            throw new InvalidArgumentException("Scope [{$name}] is already declared by {$this->scopes[$name]['owner']}.");
        }

        $this->scopes[$name] = ['description' => $description, 'owner' => $owner];
    }

    /** @return array<string, array{description: string, owner: string}> */
    public function scopes(): array
    {
        return $this->scopes;
    }

    /**
     * Register an endpoint class (or instance). Endpoints that break the
     * rules are refused: an exception in debug mode, a log line otherwise.
     *
     * @param class-string<Endpoint>|Endpoint $endpoint
     */
    public function endpoint(string|Endpoint $endpoint): void
    {
        $owner = ExecutionContext::current();

        try {
            $instance = is_string($endpoint) ? $this->container->make($endpoint) : $endpoint;
            if (!$instance instanceof Endpoint) {
                throw new LogicException((is_string($endpoint) ? $endpoint : $endpoint::class) . ' must extend ' . Endpoint::class . '.');
            }

            $route = $instance->route();
            $this->assertRegistrable($instance, $route, $owner);
        } catch (LogicException|InvalidArgumentException $e) {
            if ($this->debug) {
                throw $e;
            }
            error_log('[kestello] API endpoint refused (' . $owner . '): ' . $e->getMessage());
            return;
        }

        $entry = ['endpoint' => $instance, 'owner' => $owner, 'route' => $route];
        $this->endpoints[] = $entry;

        $this->router->addRoute($route->method, self::PREFIX . $route->path, function (Request $request, string ...$params) use ($entry): Response {
            return $this->dispatch($entry, $request, $params);
        });
        if ($route->constraints !== []) {
            $this->router->where($route->constraints);
        }
    }

    /** @return list<array{endpoint: Endpoint, owner: string, route: ApiRoute}> */
    public function endpoints(): array
    {
        return $this->endpoints;
    }

    /** Add fields to a resource (by its NAME). */
    public function extend(string $resource): ResourceExtension
    {
        return $this->extensions[$resource] ??= new ResourceExtension($resource);
    }

    /** @internal */
    public function extensionFor(string $resource): ResourceExtension
    {
        return $this->extend($resource);
    }

    /**
     * "api.response.<resource>" filter. Callbacks may add keys or redact
     * values, but a result that drops existing keys is discarded.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     * @internal
     */
    public function filterResponse(string $resource, array $data, array $row, Principal $principal): array
    {
        $name = 'api.response.' . $resource;
        if (!$this->filters->has($name)) {
            return $data;
        }

        $filtered = $this->filters->apply($name, $data, $row, $principal);

        if (!is_array($filtered) || array_diff_key($data, $filtered) !== []) {
            error_log("[kestello] {$name} filter removed fields; result ignored.");
            return $data;
        }

        return $filtered;
    }

    // ── Dispatch ────────────────────────────────────────────────────

    /**
     * @param array{endpoint: Endpoint, owner: string, route: ApiRoute} $entry
     * @param array<string, string> $params
     */
    public function dispatch(array $entry, Request $request, array $params = []): Response
    {
        $requestId = bin2hex(random_bytes(8));
        $endpoint = $entry['endpoint'];
        $limit = null;
        $principal = Principal::guest();

        try {
            try {
                $principal = $this->auth->authenticate($request);
            } catch (InvalidCredentials) {
                throw ApiProblem::invalidToken();
            }

            $limit = $this->rateLimit($endpoint, $entry, $request, $principal);
            if (!$limit->allowed) {
                throw ApiProblem::tooManyRequests($limit->resetIn);
            }

            // Cookie-authenticated (or cookie-capable) writes need the CSRF header.
            if ($request->isUnsafe() && $request->bearerToken() === null) {
                if ($endpoint->sameOriginOnly() ? !self::sameOrigin($request) : !($this->csrf)($request)) {
                    throw ApiProblem::csrf();
                }
            }

            if ($this->demo && !DemoPolicy::allows($entry['route']->method, $entry['route']->path, $endpoint->capability(), $endpoint->requiresRecentAuth())) {
                throw ApiProblem::demo();
            }

            $this->authorizePrincipal($endpoint, $principal);

            [$query, $queryErrors] = ($endpoint->query() ?? Schema::object())->validate($request->query, coerce: true);
            $body = [];
            $bodyErrors = [];
            if ($endpoint->body() !== null) {
                self::assertReadableBody($request);
                [$body, $bodyErrors] = $endpoint->body()->validate($request->post === [] ? [] : $request->post);
            }
            if ($queryErrors !== [] || $bodyErrors !== []) {
                throw ApiProblem::validation(self::prefixErrors($queryErrors, 'query') + $bodyErrors);
            }

            $apiRequest = new ApiRequest($request, $principal, $params, (array) $query, (array) $body, $requestId);

            $result = ExecutionContext::run($entry['owner'], function () use ($endpoint, $apiRequest): mixed {
                if (!$endpoint->authorize($apiRequest)) {
                    throw ApiProblem::forbidden();
                }

                return $endpoint->handle($apiRequest);
            });

            $response = $this->render($result, $principal);
        } catch (ApiProblem $problem) {
            $response = $problem->toResponse();
        } catch (Throwable $e) {
            $owner = ExecutionContext::blamed($e) ?? $entry['owner'];
            error_log(sprintf('[kestello] API %s %s failed [%s] in %s: %s (%s:%d)', $request->method, $request->path, $requestId, $owner, $e->getMessage(), $e->getFile(), $e->getLine()));
            if ($this->container->has(\Kestello\Framework\Core\ErrorHandler::class)) {
                $this->container->make(\Kestello\Framework\Core\ErrorHandler::class)->count($owner, $e::class . ': ' . $e->getMessage());
            }
            $response = ApiProblem::server($this->debug ? $e->getMessage() : null)->toResponse();
        }

        foreach ($limit?->headers() ?? [] as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        $response = $response->withHeader('X-Request-Id', $requestId);

        if (!isset($response->headers()['Cache-Control'])) {
            $response = $response->withHeader('Cache-Control', $principal->isGuest() && !$request->isUnsafe() ? 'no-cache' : 'private, no-store');
        }

        return $response->withHeader('Vary', 'Authorization, Cookie');
    }

    private function authorizePrincipal(Endpoint $endpoint, Principal $principal): void
    {
        if ($endpoint->isPublic()) {
            return;
        }

        if ($principal->isGuest()) {
            throw ApiProblem::unauthenticated();
        }

        if (!$principal->can((string) $endpoint->capability())) {
            throw ApiProblem::forbidden();
        }

        if (!$principal->hasScopes($endpoint->scopes())) {
            throw ApiProblem::insufficientScope($endpoint->scopes());
        }

        if ($endpoint->requiresRecentAuth()) {
            if ($principal->method !== 'session') {
                throw ApiProblem::forbidden('This action requires an interactive session.');
            }
            if (!$principal->recentlyAuthenticated()) {
                throw ApiProblem::reauthRequired();
            }
        }
    }

    /**
     * @param array{endpoint: Endpoint, owner: string, route: ApiRoute} $entry
     */
    private function rateLimit(Endpoint $endpoint, array $entry, Request $request, Principal $principal): RateLimitResult
    {
        [$max, $seconds] = $endpoint->rateLimit()
            ?? ($entry['route']->isUnsafe() ? self::DEFAULT_WRITE_LIMIT : self::DEFAULT_READ_LIMIT);

        $who = $principal->isGuest() ? 'ip:' . $request->ip() : 'user:' . $principal->userId;
        $bucket = $endpoint->rateLimit() !== null
            ? $entry['route']->method . ' ' . $entry['route']->path
            : ($entry['route']->isUnsafe() ? 'write' : 'read');

        return $this->limiter->hit("api|{$bucket}|{$who}", (int) $max, (int) $seconds);
    }

    private function render(mixed $result, Principal $principal): Response
    {
        return match (true) {
            $result instanceof Response => $result,
            $result === null => new Response('', 204),
            $result instanceof Resource => Response::json(['data' => $result->resolve($principal, $this)]),
            $result instanceof ResourceCollection => Response::json([
                'data' => array_map(fn(Resource $r): array => $r->resolve($principal, $this), $result->items),
                'meta' => $result->meta === [] ? (object) [] : $result->meta,
            ]),
            is_array($result) => Response::json(['data' => $result]),
            default => throw new LogicException('Endpoints must return a Resource, ResourceCollection, array, Response or null.'),
        };
    }

    // ── Rules ───────────────────────────────────────────────────────

    private function assertRegistrable(Endpoint $endpoint, ApiRoute $route, string $owner): void
    {
        $name = $endpoint::class;

        if ($endpoint->isPublic()) {
            if ($endpoint->capability() !== null || $endpoint->scopes() !== []) {
                throw new LogicException("{$name} is public but also declares a capability or scopes; pick one.");
            }
            if ($endpoint->requiresRecentAuth()) {
                throw new LogicException("{$name} is public but requires recent authentication.");
            }
        } else {
            if ($endpoint->capability() === null) {
                throw new LogicException("{$name} declares neither a capability nor isPublic(): refused (deny by default).");
            }
            if ($endpoint->scopes() === []) {
                throw new LogicException("{$name} declares no scopes: refused (tokens need an explicit scope).");
            }
            foreach ($endpoint->scopes() as $scope) {
                if (!isset($this->scopes[$scope])) {
                    throw new LogicException("{$name} uses unknown scope [{$scope}]. Declare it with \$cms->api->scope().");
                }
            }
        }

        if (str_starts_with($owner, 'plugin:') || str_starts_with($owner, 'theme:')) {
            $slug = explode(':', $owner, 2)[1];
            if (!str_starts_with($route->path, '/' . $slug . '/')) {
                throw new LogicException("{$name}: plugin endpoints must live under /{$slug}/ (got {$route->path}).");
            }
        }

        foreach ($route->params() as $param) {
            if (isset($route->constraints[$param]) && @preg_match('#^' . $route->constraints[$param] . '$#', '') === false) {
                throw new LogicException("{$name}: invalid constraint for {{$param}}.");
            }
        }

        foreach ($this->endpoints as $existing) {
            if ($existing['route']->method === $route->method && $existing['route']->path === $route->path) {
                throw new LogicException("{$name}: {$route->method} {$route->path} is already registered by {$existing['owner']}.");
            }
        }
    }

    /**
     * @param array<string, list<string>> $errors
     * @return array<string, list<string>>
     */
    private static function prefixErrors(array $errors, string $prefix): array
    {
        $out = [];
        foreach ($errors as $path => $messages) {
            $out[$path === '_' ? $prefix : $prefix . '.' . $path] = $messages;
        }

        return $out;
    }

    /**
     * Request::parseBody() falls back to an empty body when JSON doesn't parse; for the API that
     * would turn a broken create call into a blank record, so a JSON body that isn't readable is refused.
     */
    /** The request comes from a page of this site: its Origin (or Referer) names this host. */
    private static function sameOrigin(\Kestello\Framework\Core\Request $request): bool
    {
        $host = strtolower($request->header('Host') !== '' ? $request->header('Host') : (string) ($request->server['HTTP_HOST'] ?? ''));
        $from = $request->header('Origin') !== '' ? $request->header('Origin') : $request->header('Referer');
        if ($host === '' || $from === '' || $from === 'null') {
            return false;
        }
        $parts = parse_url($from);
        if (!is_array($parts) || !isset($parts['host']) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)) {
            return false;
        }

        return strtolower($parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '')) === $host;
    }

    private static function assertReadableBody(\Kestello\Framework\Core\Request $request): void
    {
        if ($request->post !== [] || !$request->isUnsafe() || !str_starts_with(strtolower(trim((string) $request->header('Content-Type'))), 'application/json')) {
            return;
        }
        $raw = $request->content(\Kestello\Framework\Core\Request::MAX_JSON_BYTES);
        if ($raw === null) {
            throw ApiProblem::malformedBody('The request body is larger than 1 MB.', 413);
        }
        if (trim($raw) === '') {
            return;
        }
        try {
            $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw ApiProblem::malformedBody('The request body is not valid JSON: ' . $e->getMessage() . '.');
        }
        if (!is_array($data)) {
            throw ApiProblem::malformedBody('The request body must be a JSON object.');
        }
    }
}
