<?php

declare(strict_types=1);

namespace Kestello\Cms\Backup;

use Kestello\Cms\Cms;
use Kestello\Cms\Extension\Provider;
use Kestello\Cms\Health\HealthResult;
use Kestello\Framework\Core\Container;
use Kestello\Framework\Settings\Settings;

/**
 * Backups: the service, System → Backups, the scheduled task and a
 * Site Health check.
 *
 * Settings (System → Backups):
 *   backups_schedule  daily (default) | weekly | off
 *   backups_files     0 (default: database only) | 1
 *   backups_keep      7 automatic backups kept (manual ones stay until deleted)
 */
final class BackupModule extends Provider
{
    public function register(Container $container): void
    {
        $container->singleton(Backups::class, static fn(): Backups => new Backups(Cms::instance() ?? throw new \RuntimeException('Not booted.')));
    }

    public function boot(Cms $cms): void
    {
        $cms->auth->roles->capability('backups.manage', 'Make and download backups (restoring needs an administrator)', sensitive: true);
        $cms->admin->page('system/backups', '@core/admin/system/Backups', 'Backups', 'backups.manage', ['label' => 'Backups', 'icon' => 'archive', 'group' => 'system', 'order' => 15]);

        $cms->schedule->call(static function () use ($cms): string {
            $backups = $cms->container->make(Backups::class);
            $made = $backups->create(['files' => self::option('backups_files', '0') === '1', 'kind' => 'scheduled']);
            $removed = $backups->prune(self::keep());

            return "{$made['name']}" . ($removed > 0 ? ", {$removed} old backup(s) removed" : '') . '.';
        }, 'backups.run')->dailyAt('02:30')->withoutOverlapping(120)
            ->when(static fn(): bool => match (self::option('backups_schedule', 'daily')) {
                'daily' => true,
                'weekly' => (int) (new \DateTimeImmutable('now', \Kestello\Cms\Content\Permalinks::timezone()))->format('w') === 0,   // Sunday on the site's clock, like the 02:30
                default => false,
            })
            ->description('Backs up the site (System → Backups sets how often, what and how many to keep).');

        $cms->health->check('core.backups', 'Backups', static function () use ($cms): HealthResult {
            $latest = $cms->container->make(Backups::class)->all()[0] ?? null;
            $schedule = self::option('backups_schedule', 'daily');
            $details = ['schedule' => $schedule, 'latest' => $latest !== null ? date('c', $latest['created_at']) : null];
            if ($latest === null) {
                return HealthResult::recommended('There is no backup of this site yet.', $details, ['Open backups', '/admin/system/backups']);
            }
            $days = (time() - $latest['created_at']) / 86400;
            if ($days > ($schedule === 'weekly' ? 8 : 2) && $schedule !== 'off') {
                return HealthResult::recommended('Scheduled backups are on, but the newest is ' . (int) $days . ' days old. Check that the scheduler runs.', $details, ['Open backups', '/admin/system/backups']);
            }
            if ($schedule === 'off' && $days > 7) {
                return HealthResult::recommended('Scheduled backups are off and the newest backup is ' . (int) $days . ' days old.', $details, ['Open backups', '/admin/system/backups']);
            }

            return HealthResult::good('The newest backup is from ' . date('Y-m-d H:i', $latest['created_at']) . '.', $details);
        });
    }

    public static function option(string $key, string $default): string
    {
        try {
            $value = Settings::getInstance()->get($key);
        } catch (\Throwable) {
            return $default;
        }

        return is_string($value) && $value !== '' ? $value : $default;
    }

    public static function keep(): int
    {
        return max(1, min(100, (int) self::option('backups_keep', '7')));
    }
}
