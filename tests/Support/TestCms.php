<?php

declare(strict_types=1);

namespace Kestello\Tests\Support;

use Closure;
use Kestello\Cms\Api\ApiRegistry;
use Kestello\Cms\Auth\AuthRegistry;
use Kestello\Cms\Cms;
use Kestello\Cms\Extension\CommandRegistry;
use Kestello\Cms\Platform\DriverRegistry;
use Kestello\Cms\View\Pages;
use Kestello\Framework\Core\Config;
use Kestello\Framework\Core\Container;
use Kestello\Framework\Core\Paths;
use Kestello\Framework\Core\Router;
use Kestello\Framework\Events\EventDispatcher;
use Kestello\Framework\Events\Filters;
use Kestello\Framework\Http\RateLimiter;

/** Builds a Cms facade for tests (no database, no HTTP globals). */
final class TestCms
{
    /** @param Closure(\Kestello\Framework\Core\Request): bool|null $csrf */
    public static function make(string $root, bool $debug = true, ?Closure $csrf = null): Cms
    {
        $container = new Container();
        $router = new Router();
        $filters = new Filters();
        $auth = new AuthRegistry();
        $api = new ApiRegistry(
            router: $router,
            auth: $auth,
            container: $container,
            filters: $filters,
            limiter: new RateLimiter(new \Kestello\Framework\Cache\ArrayStore()),
            csrf: $csrf ?? static fn(): bool => false,
            debug: $debug,
        );

        $config = new Config([]);
        $container->instance(Config::class, $config);
        $events = new EventDispatcher($container);
        $container->instance(EventDispatcher::class, $events);     // as the kernel binds it

        return new Cms(
            container: $container,
            paths: new Paths($root, dirname(__DIR__, 2) . '/core'),
            config: $config,
            routes: $router,
            events: $events,
            filters: $filters,
            pages: new Pages(),
            console: new CommandRegistry(),
            auth: $auth,
            api: $api,
            drivers: new DriverRegistry($container),
        );
    }
}
