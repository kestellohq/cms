<?php

declare(strict_types=1);

namespace Kestello\Cms\System;

use Kestello\Cms\Cms;
use Kestello\Cms\Schedule\ScheduleRunner;

/**
 * Cron for hosts without cron: after a page has been sent to the visitor,
 * run the scheduler (and with it the queue) for a few seconds.
 *
 * Config (config/cron.php): cron.web_runner  'auto' (default: only while no real cron or
 * pinger has run in 5 minutes) | true | false; cron.web_runner_seconds 20.
 * Needs PHP-FPM or LiteSpeed (so the visitor isn't kept waiting); at most
 * once a minute.
 */
final class WebRunner
{
    /** @var list<\Closure(): void> */
    private static array $deferred = [];

    /**
     * Run $work after the response has reached the visitor, so how long it
     * takes tells them nothing (a password reset mail is only sent for
     * accounts that exist). On the command line, and in tests, it runs now.
     */
    public static function defer(\Closure $work): void
    {
        if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
            $work();
            return;
        }
        self::$deferred[] = $work;
    }

    public static function afterResponse(Cms $cms): void
    {
        if (self::$deferred !== []) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } elseif (function_exists('litespeed_finish_request')) {
                litespeed_finish_request();
            }
            ignore_user_abort(true);
            foreach (array_splice(self::$deferred, 0) as $work) {
                try {
                    $work();
                } catch (\Throwable $e) {
                    error_log('[kestello] deferred work failed: ' . $e->getMessage());
                }
            }
        }

        $mode = $cms->config->get('cron.web_runner', 'auto');
        if ($mode === false || $mode === 'false' || $mode === 0 || $mode === '0') {
            return;
        }
        $finish = match (true) {
            function_exists('fastcgi_finish_request') => 'fastcgi_finish_request',
            function_exists('litespeed_finish_request') => 'litespeed_finish_request',
            default => null,
        };
        if ($finish === null) {
            return;
        }

        try {
            $cache = CoreTasks::cache($cms);
            if ($mode === 'auto') {
                $beat = $cache->get(ScheduleRunner::HEARTBEAT_KEY);
                if (is_array($beat) && ($beat['via'] ?? '') !== 'web' && time() - (int) $beat['at'] < 300) {
                    return; // real cron (or a pinger) is doing the job
                }
            }
            if (!$cache->add('cron:web', time(), 60)) {
                return;
            }

            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close(); // don't hold the visitor's session lock
            }
            $finish();
            ignore_user_abort(true);
            $budget = max(5, min(55, (int) $cms->config->get('cron.web_runner_seconds', 20)));
            @set_time_limit($budget + 15);

            $cms->container->make(ScheduleRunner::class)->run(via: 'web', budget: $budget);
        } catch (\Throwable $e) {
            error_log('[cron] web runner failed: ' . $e->getMessage());
        }
    }
}
