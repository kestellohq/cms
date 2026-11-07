<?php

declare(strict_types=1);

namespace Kestello\Cms\System;

use Kestello\Cms\Auth\PasswordResets;
use Kestello\Cms\Auth\Registrations;
use Kestello\Cms\Cms;
use Kestello\Cms\Queue\JobQueue;
use Kestello\Cms\Queue\Worker;
use Kestello\Cms\Schedule\ScheduleRunner;
use Kestello\Contracts\Cache\Store as CacheStore;
use Kestello\Contracts\Session\SessionStore;
use Kestello\Framework\Cache\ArrayStore;
use Kestello\Framework\Cache\FileStore;
use Kestello\Framework\Database\QueryBuilder;
use Kestello\Framework\Queue\SyncQueue;

/**
 * Core housekeeping on the scheduler.
 *
 * Config (config/queue.php for queue.*, config/audit.php for audit.*):
 *   queue.worker                 'cron' (default: the scheduler works the queue every minute) | 'daemon' (you run queue:work)
 *   audit.retention_days         365
 *   queue.failed_retention_days  30
 */
final class CoreTasks
{
    public static function register(Cms $cms): void
    {
        $s = $cms->schedule;
        $config = $cms->config;

        if ($config->get('queue.worker', 'cron') !== 'daemon') {
            $s->call(static function () use ($cms): string {
                if ($cms->queue->backend() instanceof SyncQueue) {
                    return 'Sync queue: nothing to work on.';
                }
                $worker = new Worker($cms->queue, self::cache($cms));
                $reason = $worker->run(JobQueue::QUEUES, stopWhenEmpty: true, maxTime: min(50, ScheduleRunner::remaining() ?? 50));
                ['done' => $d, 'retry' => $r, 'failed' => $f] = $worker->counts;
                return "{$d} done, {$r} to retry, {$f} failed ({$reason}).";
            }, 'queue.work')->everyMinute()->withoutOverlapping(2)
                ->description('Runs queued jobs (set queue.worker = daemon when a queue:work process runs instead).');
        }

        $s->call(static function () use ($cms): string {
            $days = max(1, (int) $cms->config->get('audit.retention_days', 365));
            $entries = $cms->audit->prune($days);
            $resets = QueryBuilder::table(PasswordResets::TABLE)->where('expires_at', '<', time())->delete();
            $signups = QueryBuilder::table(Registrations::TABLE)->where('expires_at', '<', time())->delete();
            return "{$entries} audit entries, {$resets} expired reset tokens, {$signups} expired sign-up links.";
        }, 'audit.prune')->dailyAt('03:10')->description('Deletes audit entries past retention, and expired password-reset and sign-up links.');

        $s->call(static function () use ($cms): string {
            $days = max(1, (int) $cms->config->get('queue.failed_retention_days', 30));
            $failed = QueryBuilder::table('failed_jobs')->where('failed_at', '<', time() - $days * 86400)->delete();
            $batches = $cms->queue->batches()->prune($days * 86400);
            return "{$failed} failed jobs, {$batches} finished batches.";
        }, 'queue.prune-failed')->dailyAt('03:20')->description('Deletes failed jobs and finished batches past retention.');

        $s->call(static function (): string {
            $cutoff = date('Y-m-d H:i:s', time() - 30 * 86400);
            return QueryBuilder::table('personal_access_tokens')->whereNull('expires_at', not: true)->where('expires_at', '<', $cutoff)->delete() . ' expired tokens.';
        }, 'auth.prune-tokens')->dailyAt('03:30')->description('Deletes API tokens that expired over 30 days ago.');

        $s->call(static fn(): string => $cms->container->make(\Kestello\Cms\Content\Previews::class)->prune() . ' expired preview links.', 'content.prune-previews')
            ->dailyAt('03:35')->description('Deletes preview links that expired.');

        $s->call(static function () use ($cms): string {
            $stores = [$cms->drivers->resolve('cache'), new FileStore($cms->paths->storage('framework/ratelimit'))];
            return CachePruner::prune($stores) . ' expired cache entries.';
        }, 'cache.prune')->hourlyAt(23)->description('Removes expired cache entries and rate-limit counters.');

        $s->call(static function () use ($cms): string {
            $store = $cms->container->has(SessionStore::class) ? $cms->container->make(SessionStore::class) : $cms->drivers->resolve('session');
            $removed = $store->gc(max(300, (int) $cms->config->get('app.session_lifetime', 7200)));
            return ($removed === false ? 0 : $removed) . ' expired sessions.';
        }, 'sessions.gc')->hourlyAt(17)->description('Removes expired sessions.');

        $s->call(static function () use ($cms): string {
            $n = $cms->notifications->prune(
                (int) $cms->config->get('notifications.read_retention_days', 90),
                (int) $cms->config->get('notifications.retention_days', 365),
            );
            return "Removed {$n} old notification(s).";
        }, 'notifications.prune')->dailyAt('03:40')->description('Removes read notifications after 90 days and all after a year.');
    }

    public static function cache(Cms $cms): CacheStore
    {
        return $cms->container->has(CacheStore::class) ? $cms->container->make(CacheStore::class) : new ArrayStore();
    }
}
