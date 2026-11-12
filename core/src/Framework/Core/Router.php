<?php

declare(strict_types=1);

namespace Kestello\Framework\Core;

use Closure;
use Kestello\Framework\Core\Middleware\MiddlewareInterface;
use Kestello\Framework\Core\Middleware\MiddlewarePipeline;
use RuntimeException;

/**
 * HTTP router with support for groups, named routes, middleware, and URL generation.
 */
final class Router
{
    /** @var list<Route> All registered routes. */
    private array $routes = [];

    /** @var array<string, Route> Named routes index. */
    private array $namedRoutes = [];

    /** @var string Active group prefix. */
    private string $groupPrefix = '';

    /** @var list<MiddlewareInterface|string> Middleware applied to every request. */
    private array $globalMiddleware = [];

    /** @var list<string> Active group middleware stack. */
    private array $groupMiddleware = [];

    /**
     * @param Container|null $container builds controllers and middleware named by class, with
     *                                  their dependencies; without one they are made with `new`
     */
    public function __construct(private readonly ?Container $container = null) {}

    // ------------------------------------------------------------------
    // HTTP verb helpers
    // ------------------------------------------------------------------

    public function get(string $path, Closure|array $handler): self
    {
        return $this->addRoute('GET', $path, $handler);
    }

    public function post(string $path, Closure|array $handler): self
    {
        return $this->addRoute('POST', $path, $handler);
    }

    public function put(string $path, Closure|array $handler): self
    {
        return $this->addRoute('PUT', $path, $handler);
    }

    public function patch(string $path, Closure|array $handler): self
    {
        return $this->addRoute('PATCH', $path, $handler);
    }

    public function delete(string $path, Closure|array $handler): self
    {
        return $this->addRoute('DELETE', $path, $handler);
    }

    // ------------------------------------------------------------------
    // Route registration
    // ------------------------------------------------------------------

    /**
     * Register a route and return $this for fluent chaining.
     *
     * @param string              $method  HTTP method.
     * @param string              $path    URI template.
     * @param Closure|array<string> $handler Handler callable.
     */
    public function addRoute(string $method, string $path, Closure|array $handler): self
    {
        $fullPath = $this->groupPrefix . '/' . ltrim($path, '/');
        $fullPath = '/' . trim($fullPath, '/');

        $route = new Route(
            method: strtoupper($method),
            path: $fullPath,
            handler: $handler,
            middlewares: $this->groupMiddleware,
            owner: ExecutionContext::current(),
        );

        $this->routes[] = $route;

        return $this;
    }

    /**
     * Assign a name to the most recently registered route.
     */
    public function name(string $name): self
    {
        $lastIndex = array_key_last($this->routes);

        if ($lastIndex === null) {
            throw new RuntimeException('Cannot name a route: no routes have been registered.');
        }

        $route = $this->routes[$lastIndex];

        // Reconstruct the Route with a name (it is readonly, so we create a new one).
        $named = new Route(
            method: $route->method,
            path: $route->path,
            handler: $route->handler,
            name: $name,
            middlewares: $route->middlewares,
            constraints: $route->constraints,
            owner: $route->owner,
        );

        $this->routes[$lastIndex] = $named;
        $this->namedRoutes[$name] = $named;

        return $this;
    }

    /**
     * Add regex constraints to the most recently registered route's parameters.
     *
     * @param array<string, string> $constraints Parameter name => regex.
     */
    public function where(array $constraints): self
    {
        $lastIndex = array_key_last($this->routes);

        if ($lastIndex === null) {
            throw new RuntimeException('Cannot add constraints: no routes have been registered.');
        }

        $route = $this->routes[$lastIndex];

        $constrained = new Route(
            method: $route->method,
            path: $route->path,
            handler: $route->handler,
            name: $route->name,
            middlewares: $route->middlewares,
            constraints: array_merge($route->constraints, $constraints),
            owner: $route->owner,
        );

        $this->routes[$lastIndex] = $constrained;

        if ($constrained->name !== null) {
            $this->namedRoutes[$constrained->name] = $constrained;
        }

        return $this;
    }

    /**
     * Add middleware to the most recently registered route.
     *
     * @param string|list<string> $middleware One or more middleware class names.
     */
    public function middleware(string|array $middleware): self
    {
        $lastIndex = array_key_last($this->routes);

        if ($lastIndex === null) {
            throw new RuntimeException('Cannot add middleware: no routes have been registered.');
        }

        $route = $this->routes[$lastIndex];
        $middlewares = is_array($middleware) ? $middleware : [$middleware];

        $updated = new Route(
            method: $route->method,
            path: $route->path,
            handler: $route->handler,
            name: $route->name,
            middlewares: array_merge($route->middlewares, $middlewares),
            constraints: $route->constraints,
            owner: $route->owner,
        );

        $this->routes[$lastIndex] = $updated;

        if ($updated->name !== null) {
            $this->namedRoutes[$updated->name] = $updated;
        }

        return $this;
    }

    // ------------------------------------------------------------------
    // Route groups
    // ------------------------------------------------------------------

    /**
     * Define a group of routes sharing a prefix and/or middleware.
     *
     * @param array{prefix?: string, middleware?: list<string>} $attributes Group attributes.
     * @param Closure                                           $callback   Receives $this.
     */
    public function group(array $attributes, Closure $callback): void
    {
        $previousPrefix = $this->groupPrefix;
        $previousMiddleware = $this->groupMiddleware;

        $prefix = trim($attributes['prefix'] ?? '', '/');
        // A group without its own prefix (e.g. middleware-only) must not add a "/".
        $this->groupPrefix = $prefix === '' ? $previousPrefix : $previousPrefix . '/' . $prefix;
        $this->groupMiddleware = array_merge($previousMiddleware, $attributes['middleware'] ?? []);

        $callback($this);

        $this->groupPrefix = $previousPrefix;
        $this->groupMiddleware = $previousMiddleware;
    }

    // ------------------------------------------------------------------
    // Dispatch
    // ------------------------------------------------------------------

    /**
     * Match the request to a route and return a Response.
     */
    public function dispatch(Request $request): Response
    {
        $match = function (Request $request): Response {
            foreach ($this->routes as $route) {
                $params = $route->matches($request->method, $request->path);

                if ($params === false) {
                    continue;
                }

                return $this->runRoute($route, $request, $params);
            }

            return Response::notFound();
        };

        if ($this->globalMiddleware === []) {
            return $match($request);
        }

        $pipeline = new MiddlewarePipeline($this->container);
        foreach ($this->globalMiddleware as $mw) {
            $pipeline->pipe($mw);
        }

        return $pipeline->process($request, $match);
    }

    /**
     * Register middleware that wraps every request, matched or not
     * (runs before route-level middleware).
     */
    public function pushGlobalMiddleware(MiddlewareInterface|string $middleware): void
    {
        $this->globalMiddleware[] = $middleware;
    }

    // ------------------------------------------------------------------
    // URL generation
    // ------------------------------------------------------------------

    /**
     * Generate a URL for a named route.
     *
     * @param string               $name   Route name.
     * @param array<string, string> $params Parameter values to substitute.
     */
    public function url(string $name, array $params = []): string
    {
        if (!isset($this->namedRoutes[$name])) {
            throw new RuntimeException("No route named [{$name}] has been registered.");
        }

        $path = $this->namedRoutes[$name]->path;

        foreach ($params as $key => $value) {
            $path = str_replace('{' . $key . '}', (string) $value, $path);
        }

        return $path;
    }

    /**
     * Return all registered routes.
     *
     * @return list<Route>
     */
    public function routes(): array
    {
        return $this->routes;
    }

    // ------------------------------------------------------------------
    // Internal
    // ------------------------------------------------------------------

    /**
     * Execute a matched route, passing through its middleware pipeline.
     *
     * @param Route                 $route  The matched route.
     * @param Request               $request The incoming request.
     * @param array<string, string> $params  Extracted URI parameters.
     */
    private function runRoute(Route $route, Request $request, array $params): Response
    {
        $destination = fn(Request $req): Response => ExecutionContext::run(
            $route->owner,
            fn(): Response => $this->callHandler($route, $req, $params),
        );

        if ($route->middlewares === []) {
            return $destination($request);
        }

        $pipeline = new MiddlewarePipeline($this->container);

        foreach ($route->middlewares as $mw) {
            $pipeline->pipe($mw);
        }

        return $pipeline->process($request, $destination);
    }

    /**
     * @param array<string, string> $params
     */
    private function callHandler(Route $route, Request $req, array $params): Response
    {
        $handler = $route->handler;

        if ($handler instanceof Closure) {
            $result = $handler($req, ...$params);
        } elseif (is_array($handler)) {
            [$class, $method] = $handler;
            $controller = $this->container !== null ? $this->container->make($class) : new $class();
            $result = $controller->{$method}($req, ...$params);
        } else {
            throw new RuntimeException('Invalid route handler.');
        }

        return $result instanceof Response ? $result : Response::html((string) $result);
    }
}
