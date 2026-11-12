<?php

declare(strict_types=1);

namespace Kestello\Framework\Core\Middleware;

use Closure;
use Kestello\Framework\Core\Container;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Core\Response;
use RuntimeException;

/**
 * Executes a chain of middleware around a final request handler.
 *
 * Middleware are run in FIFO order: the first piped middleware is the
 * outermost layer.
 */
final class MiddlewarePipeline
{
    /** @var list<MiddlewareInterface|string> */
    private array $layers = [];

    /** @param Container|null $container builds middleware named by class, with their dependencies */
    public function __construct(private readonly ?Container $container = null) {}

    /**
     * Append a middleware to the pipeline.
     *
     * @param MiddlewareInterface|string $middleware An instance or a fully-qualified class name.
     */
    public function pipe(MiddlewareInterface|string $middleware): static
    {
        $this->layers[] = $middleware;

        return $this;
    }

    /**
     * Send the request through the middleware stack, ending at $destination.
     *
     * @param Request                    $request     The incoming request.
     * @param Closure(Request): Response $destination The innermost handler.
     */
    public function process(Request $request, Closure $destination): Response
    {
        $pipeline = array_reduce(
            array_reverse($this->layers),
            fn(Closure $next, MiddlewareInterface|string $layer): Closure => function (Request $req) use ($next, $layer): Response {
                $middleware = $this->resolve($layer);

                return $middleware->handle($req, $next);
            },
            $destination,
        );

        return $pipeline($request);
    }

    /**
     * Resolve a middleware from a class name string or return it as-is.
     */
    private function resolve(MiddlewareInterface|string $middleware): MiddlewareInterface
    {
        if ($middleware instanceof MiddlewareInterface) {
            return $middleware;
        }

        if (!class_exists($middleware)) {
            throw new RuntimeException("Middleware class [{$middleware}] does not exist.");
        }

        $instance = $this->container !== null ? $this->container->make($middleware) : new $middleware();

        if (!$instance instanceof MiddlewareInterface) {
            throw new RuntimeException("Class [{$middleware}] does not implement MiddlewareInterface.");
        }

        return $instance;
    }
}
