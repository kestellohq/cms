<?php

declare(strict_types=1);

namespace Kestello\Tests\Support;

use Closure;
use Kestello\Cms\Cms;
use Kestello\Cms\CmsFactory;
use Kestello\Cms\Platform\DriverRegistry;
use Kestello\Framework\Cache\ArrayStore;
use Kestello\Framework\Core\Config;
use Kestello\Framework\Core\Container;
use Kestello\Framework\Core\Paths;
use Kestello\Framework\Core\Router;
use Kestello\Framework\Http\RateLimiter;

/**
 * A Cms for tests, built by the Kernel's own CmsFactory. Only what depends
 * on the machine differs: no drivers, rate limits counted in memory, and a
 * CSRF check the test decides.
 */
final class TestCms
{
    /**
     * @param Closure(\Kestello\Framework\Core\Request): bool|null $csrf
     * @param list<\Kestello\Contracts\Auth\Guard>|null                  $guards null: the real ones
     */
    public static function make(string $root, bool $debug = true, ?Closure $csrf = null, bool $demo = false, ?array $guards = null): Cms
    {
        $container = new Container();
        $config = new Config([]);
        $config->set('app.debug', $debug);
        $config->set('app.demo', $demo);

        return CmsFactory::make(
            container: $container,
            paths: new Paths($root, dirname(__DIR__, 2) . '/core'),
            config: $config,
            router: new Router($container),
            drivers: new DriverRegistry($container),
            limiter: new RateLimiter(new ArrayStore()),
            csrf: $csrf ?? static fn(): bool => false,
            guards: $guards,
        );
    }
}
