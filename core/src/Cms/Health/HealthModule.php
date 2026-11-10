<?php

declare(strict_types=1);

namespace Kestello\Cms\Health;

use Kestello\Cms\Cms;
use Kestello\Cms\Extension\Provider;
use Kestello\Cms\Platform\PlatformChanged;
use Kestello\Cms\System\CoreTasks;
use Kestello\Framework\Database\DatabaseManager;

/**
 * Core module: Site Health checks, the daily alert task and "infrastructure
 * changed" notices to administrators.
 */
final class HealthModule extends Provider
{
    private const string ALERTED_KEY = 'health:alerted';

    public function boot(Cms $cms): void
    {
        $cms->health->setCache(static fn() => CoreTasks::cache($cms));
        CoreHealthChecks::register($cms);
        $cms->notifications->type(HealthAlertNotification::class, 'Site Health alerts', 'When a critical problem is found on the site', capability: 'system.manage'); // sent to Administrators::ids()

        $cms->events->on(PlatformChanged::class, static function (PlatformChanged $e) use ($cms): void {
            $by = 'Someone';
            if ($e->userId !== null) {
                $by = (string) (DatabaseManager::getConnection()->table('users')->where('id', $e->userId)->value('username') ?? 'Someone');
            } elseif (PHP_SAPI === 'cli') {
                $by = 'A command-line user';
            }
            try {
                $cms->notifications->send(Administrators::ids($cms), new PlatformChangedNotification($e->subsystem, $e->previousDriver, $e->driver, $e->reset, $by, (string) $cms->config->get('app.url', '')));
            } catch (\Throwable $ex) {
                error_log('[kestello] could not notify administrators about a platform change: ' . $ex->getMessage());
            }
        });

        $cms->schedule->call(static fn(): string => self::alert($cms), 'health.check')
            ->hourlyAt(23)->description('Runs Site Health and tells administrators about new critical problems.');
    }

    /** Notify about critical checks that were not critical at the last run. */
    public static function alert(Cms $cms): string
    {
        $report = $cms->health->run(fresh: true);
        $cache = CoreTasks::cache($cms);
        $previous = (array) ($cache->get(self::ALERTED_KEY) ?? []);
        $critical = [];
        foreach ($report['checks'] as $c) {
            if ($c['status'] === HealthResult::CRITICAL) {
                $critical[$c['id']] = ['label' => (string) $c['label'], 'message' => (string) $c['message']];
            }
        }
        $cache->put(self::ALERTED_KEY, array_keys($critical), 86400 * 30);
        $new = array_diff_key($critical, array_flip($previous));
        if ($new === []) {
            return count($critical) . ' critical, nothing new.';
        }
        $cms->notifications->send(Administrators::ids($cms), new HealthAlertNotification(array_values($new), (string) $cms->config->get('app.url', '')));

        return 'Alerted about ' . count($new) . ' new critical problem(s).';
    }
}
