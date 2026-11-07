<?php

declare(strict_types=1);

namespace Kestello\Framework\Console\Commands;

use Kestello\Framework\Console\Command;
use Kestello\Framework\Core\Router;

/**
 * List all registered application routes.
 */
final class RoutesCommand extends Command
{
    protected string $name = 'routes';
    protected string $description = 'List all registered routes';

    /**
     * @param \Closure(): Router $loadRoutes Boots the app and registers all routes.
     */
    public function __construct(
        private readonly \Closure $loadRoutes,
    ) {}

    public function handle(array $arguments, array $options): int
    {
        $router = ($this->loadRoutes)();

        $routes = $router->routes();

        if (empty($routes)) {
            $this->output->muted('  No routes registered.');
            return 0;
        }

        $rows = [];
        foreach ($routes as $route) {
            $handler = match (true) {
                $route->handler instanceof \Closure => 'Closure',
                is_array($route->handler) => implode('@', $route->handler),
                is_string($route->handler) => $route->handler,
                default => '(unknown)',
            };

            $rows[] = [
                $route->method,
                $route->path,
                $route->name ?? '-',
                $handler,
                !empty($route->middlewares) ? implode(', ', $route->middlewares) : '-',
            ];
        }

        $this->output->newLine();
        $this->output->table(['Method', 'Path', 'Name', 'Handler', 'Middleware'], $rows);

        return 0;
    }
}
