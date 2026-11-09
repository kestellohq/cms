<?php

declare(strict_types=1);

namespace Kestello\Cms\Demo;

use Closure;
use Kestello\Cms\Backup\Backups;
use Kestello\Cms\Cms;
use Kestello\Cms\Events\KernelBooted;
use Kestello\Cms\Extension\Provider;
use Kestello\Cms\Health\HealthResult;
use Kestello\Cms\Schedule\ScheduleRunner;
use Kestello\Cms\System\CoreTasks;
use Kestello\Contracts\Mail\Transport;
use Kestello\Framework\Core\Config;
use Kestello\Framework\Core\Container;
use Kestello\Framework\Core\Middleware\MiddlewareInterface;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Core\Response;

/**
 * Turns a site into a public demo when config app.demo is on (Demo). What
 * the API allows is DemoPolicy, in ApiRegistry; this adds the rest:
 *
 * - no email leaves the server (DemoTransport);
 * - the demo's account, content and snapshot are made on the first boot,
 *   and the site goes back to the snapshot every hour (DemoSite);
 * - every page says it is a demo: the admin shows a bar (the "demo" page
 *   prop), the public site a small badge, the login page the account;
 * - search engines are asked to stay away (X-Robots-Tag, robots.txt).
 */
final class DemoModule extends Provider
{
    public function register(Container $container): void
    {
        if (!Demo::on($container->make(Config::class))) {
            return;
        }
        $container->singleton(Transport::class, static fn(): Transport => new DemoTransport());
        $container->singleton(DemoSite::class, static fn(Container $c): DemoSite => new DemoSite($c->make(Cms::class), $c->make(Backups::class)));
    }

    public function boot(Cms $cms): void
    {
        if (!Demo::on($cms->config)) {
            return;
        }
        $site = static fn(): DemoSite => $cms->container->make(DemoSite::class);

        // after content types are final: the sample site needs them
        $cms->events->on(KernelBooted::class, static function () use ($site): void {
            try {
                $site()->ensure();
            } catch (\Throwable $e) {
                error_log('[kestello] preparing the demo: ' . $e->getMessage());
            }
        });

        $cms->schedule->call(static function () use ($site): string {
            return 'Back to ' . $site()->reset() . '.';
        }, 'demo.reset')->everyMinute()->when(static fn(): bool => $site()->due())->withoutOverlapping(30)
            ->description('Puts the demo back to its snapshot every hour.');

        $cms->health->check('core.demo', 'Demo', static function () use ($site, $cms): HealthResult {
            $state = $site()->state();
            $beat = CoreTasks::cache($cms)->get(ScheduleRunner::HEARTBEAT_KEY);

            return self::health($state['snapshot'] !== null && $site()->exists($state['snapshot']), $site()->nextReset(), is_array($beat) ? (int) $beat['at'] : null, time());
        });
        $cms->pages->share('demo', static fn(): array => self::props($site()->nextReset()));
        $cms->filters->add('page.body_open', static fn(string $html, array $data = []): string => $html . self::badge((string) ($data['url'] ?? '/')));
        $cms->filters->add('robots.txt', static fn(string $txt): string => "User-agent: *\nDisallow: /\n");
        $cms->routes->pushGlobalMiddleware(new class implements MiddlewareInterface {
            public function handle(Request $request, Closure $next): Response
            {
                return $next($request)->withHeader('X-Robots-Tag', 'noindex, nofollow');
            }
        });
    }

    /**
     * The Demo check. A reset is only late when the scheduler ran after it
     * was due and the demo still did not go back: that is the reset failing.
     * A scheduler that does not run at all is the Scheduled tasks check's
     * to report, and a quiet demo on the web runner resets on its next visit.
     */
    public static function health(bool $hasSnapshot, ?int $nextReset, ?int $schedulerRanAt, int $now): HealthResult
    {
        if (!$hasSnapshot || $nextReset === null) {
            return HealthResult::critical('The demo has no snapshot, so it cannot reset. Run php bin/cms demo:snapshot on the server.');
        }
        if ($now >= $nextReset && $schedulerRanAt !== null && $schedulerRanAt >= $nextReset + 120) {
            return HealthResult::critical('The demo should have reset ' . intdiv($now - $nextReset, 60) . ' minutes ago, but did not. See demo.reset under Scheduled tasks for the error.', [], ['Scheduled tasks', '/admin/system/schedule']);
        }

        return HealthResult::good($now >= $nextReset ? 'The demo resets the next time scheduled tasks run.' : 'The demo resets every hour.');
    }

    /** @return array{username: string, password: string, next_reset: ?int, every: int} */
    public static function props(?int $nextReset): array
    {
        return ['username' => Demo::USERNAME, 'password' => Demo::PASSWORD, 'next_reset' => $nextReset, 'every' => intdiv(Demo::RESET_EVERY, 60)];
    }

    /** The public site's badge: it says this is a demo, and how to get in. Not in the admin, which has its own bar. */
    public static function badge(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        if (preg_match('#^/(admin|login|install)(/|$)#', $path) === 1) {
            return '';
        }

        return '<a href="/login" class="kestello-demo-badge" style="position:fixed;left:16px;bottom:16px;z-index:2147483000;'
            . 'padding:8px 12px;border-radius:999px;background:#111827;color:#fff;font:600 13px/1.2 system-ui,sans-serif;'
            . 'text-decoration:none;box-shadow:0 2px 8px rgb(0 0 0 / .25)">Demo site · resets every hour · sign in as demo</a>';
    }
}
