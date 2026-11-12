<?php

declare(strict_types=1);

namespace Kestello\Cms;

use Closure;
use Kestello\Cms\Api\ApiGateway;
use Kestello\Cms\Api\ApiRegistry;
use Kestello\Cms\Auth\Auth;
use Kestello\Cms\Auth\AuthRegistry;
use Kestello\Cms\Auth\Guards\SessionGuard;
use Kestello\Cms\Auth\Guards\TokenGuard;
use Kestello\Cms\Auth\PersonalAccessTokens;
use Kestello\Cms\Extension\CommandRegistry;
use Kestello\Cms\Notifications\SendNotificationJob;
use Kestello\Cms\Platform\DriverRegistry;
use Kestello\Cms\Queue\JobQueue;
use Kestello\Cms\View\Pages;
use Kestello\Contracts\Auth\Guard;
use Kestello\Framework\Core\Config;
use Kestello\Framework\Core\Container;
use Kestello\Framework\Core\Paths;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Core\Router;
use Kestello\Framework\Database\Model;
use Kestello\Framework\Events\EventDispatcher;
use Kestello\Framework\Events\Filters;
use Kestello\Framework\Http\CorsMiddleware;
use Kestello\Framework\Http\RateLimiter;
use Kestello\Framework\Log\Log;

/**
 * Builds the Cms facade and wires its parts to each other: the one place
 * this happens, for the Kernel and for the tests alike, so a test's site is
 * put together the way a real one is.
 *
 * What depends on the environment stays with the caller: which drivers
 * exist and the rate limiter's store, how a CSRF token is checked, the
 * platform settings, storage, the audit log's request details, and the
 * modules. Nothing here touches files, the database or globals.
 */
final class CmsFactory
{
    /**
     * @param Closure(Request): bool $csrf   whether a request carries a valid CSRF token
     * @param list<Guard>|null       $guards how people sign in; null: the session and personal access tokens
     */
    public static function make(
        Container $container,
        Paths $paths,
        Config $config,
        Router $router,
        DriverRegistry $drivers,
        RateLimiter $limiter,
        Closure $csrf,
        ?array $guards = null,
    ): Cms {
        $debug = (bool) $config->get('app.debug', false);
        $filters = new Filters(strict: $debug);
        $auth = new AuthRegistry();
        $api = new ApiRegistry(
            router: $router,
            auth: $auth,
            container: $container,
            filters: $filters,
            limiter: $limiter,
            csrf: $csrf,
            debug: $debug,
            demo: (bool) $config->get('app.demo', false),
        );
        $cms = new Cms(
            container: $container,
            paths: $paths,
            config: $config,
            routes: $router,
            events: new EventDispatcher($container),
            filters: $filters,
            pages: new Pages(),
            console: new CommandRegistry(),
            auth: $auth,
            api: $api,
            drivers: $drivers,
        );

        foreach ($guards ?? [new SessionGuard($auth, static fn(): ?Auth => Auth::getInstance()), new TokenGuard($auth, new PersonalAccessTokens())] as $guard) {
            $auth->guard($guard);
        }
        $router->pushGlobalMiddleware(new ApiGateway(new CorsMiddleware(
            origins: (array) $config->get('api.cors.origins', []),
            credentials: (bool) $config->get('api.cors.credentials', false),
        )));

        $container->instance(Config::class, $config);
        $container->instance(Cms::class, $cms);
        $container->instance(EventDispatcher::class, $cms->events);
        $container->instance(Filters::class, $cms->filters);
        $container->instance(AuthRegistry::class, $auth);
        $container->instance(ApiRegistry::class, $api);
        $container->instance(RateLimiter::class, $limiter);
        $container->instance(JobQueue::class, $cms->queue);

        $cms->queue->setContainer($container, $cms->events);
        $cms->queue->wireListeners($cms->events);
        $cms->models->setDispatcher($cms->events);
        if ($debug) {
            $seen = [];
            $cms->models->handleLazyLoadingWith(static function (Model $model, string $relation) use (&$seen): void {
                $key = $model::class . '::' . $relation;
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    Log::warning("orm: N+1: [{$relation}] lazy loaded on each " . $model::class . " of a list; eager load it with ->with('{$relation}').");
                }
            });
        }
        $cms->notifications->wire(static fn() => $cms->queue, $cms->events);
        $cms->queue->job(SendNotificationJob::class);     // the notifications above are sent through it

        return $cms;
    }
}
