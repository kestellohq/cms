<?php

declare(strict_types=1);

namespace Kestello\Cms\Health;

use Kestello\Cms\Cms;
use Kestello\Cms\Queue\Worker;
use Kestello\Cms\Schedule\ScheduleRunner;
use Kestello\Cms\System\CoreTasks;
use Kestello\Framework\Database\DatabaseManager;
use Kestello\Framework\Mail\Mailer;
use Kestello\Framework\Queue\SyncQueue;
use Kestello\Framework\Storage\ReadOnlyDisk;
use Kestello\Support\AppKey;
use Kestello\Support\Number;

/** The checks core ships with. @internal */
final class CoreHealthChecks
{
    /** Required extensions => why. */
    public const array REQUIRED = ['pdo' => 'database access', 'mbstring' => 'text handling', 'sodium' => 'encryption', 'json' => 'API', 'zlib' => 'zip and compression', 'ctype' => 'validation'];
    /** Recommended extensions => why. */
    private const array RECOMMENDED = ['intl' => 'translations, plurals and number formatting', 'fileinfo' => 'reliable upload type detection', 'curl' => 'faster, more reliable HTTP requests', 'opcache' => 'much faster page loads', 'gd' => 'image resizing (or imagick)'];

    public static function register(Cms $cms): void
    {
        $h = $cms->health;

        $h->check('core.php-version', 'PHP version', static function (): HealthResult {
            $details = ['version' => PHP_VERSION, 'sapi' => PHP_SAPI];
            // PHP 8.4 gets security fixes until the end of 2028; 8.5 until the end of 2029.
            return version_compare(PHP_VERSION, '8.5.0', '>=') || time() < strtotime('2028-12-31')
                ? HealthResult::good('PHP ' . PHP_VERSION . ' is supported.', $details)
                : HealthResult::critical('PHP ' . PHP_VERSION . ' no longer gets security fixes. Ask your host for PHP 8.5 or newer.', $details);
        }, 'security');

        $h->check('core.php-extensions', 'PHP extensions', static function (): HealthResult {
            $missing = array_filter(array_keys(self::REQUIRED), static fn($e) => !extension_loaded($e));
            $optional = array_filter(array_keys(self::RECOMMENDED), static fn($e) => !extension_loaded($e) && !($e === 'opcache' && extension_loaded('Zend OPcache')) && !($e === 'gd' && extension_loaded('imagick')));
            $details = array_fill_keys(array_keys(self::REQUIRED + self::RECOMMENDED), true);
            foreach ([...$missing, ...$optional] as $e) {
                $details[$e] = false;
            }
            if ($missing !== []) {
                return HealthResult::critical('Missing required extensions: ' . implode(', ', array_map(static fn($e) => "{$e} (" . self::REQUIRED[$e] . ')', $missing)) . '.', $details);
            }
            if ($optional !== []) {
                return HealthResult::recommended('Install for the best experience: ' . implode(', ', array_map(static fn($e) => "{$e} (" . self::RECOMMENDED[$e] . ')', $optional)) . '.', $details);
            }
            return HealthResult::good('All required and recommended extensions are installed.', $details);
        }, 'performance');

        $h->check('core.deprecated-hooks', 'Deprecated hooks', static function () use ($cms): HealthResult {
            $byOwner = [];
            foreach ($cms->hooks->deprecatedUses($cms->filters, $cms->events) as $u) {
                $d = $u['hook']->deprecated;
                $byOwner[$u['owner']][] = $u['as'] . ($d['removal'] !== null ? " (removed in {$d['removal']})" : '');
            }
            if ($byOwner === []) {
                return HealthResult::good('No plugin or theme uses a deprecated hook.');
            }
            $lines = array_map(static fn(string $owner, array $names) => $owner . ': ' . implode(', ', $names), array_keys($byOwner), $byOwner);

            return HealthResult::recommended('Some plugins or themes use hooks that a later Kestello version removes. Update them before updating Kestello. ' . implode('; ', $lines) . '.', $byOwner);
        });

        $h->check('core.app-key', 'Encryption key', static fn(): HealthResult => AppKey::isSet()
            ? HealthResult::good('An application key is set.')
            : HealthResult::critical('No application key is set, so secrets and signed links are not protected. Run: php bin/cms key:generate'), 'security');

        $h->check('core.debug', 'Debug mode', static function () use ($cms): HealthResult {
            if (!(bool) $cms->config->get('app.debug', false)) {
                return HealthResult::good('Debug mode is off.');
            }
            return self::isLocal((string) $cms->config->get('app.url', ''))
                ? HealthResult::recommended('Debug mode is on. That is fine for local development; turn it off before going live.')
                : HealthResult::critical('Debug mode is on. Error pages can reveal code, paths and settings to visitors. Turn it off on live sites.');
        }, 'security');

        $h->check('core.https', 'HTTPS', static function () use ($cms): HealthResult {
            $url = (string) $cms->config->get('app.url', '');
            if ($url === '') {
                return HealthResult::recommended('The site address (app.url) is not set, so links in emails and from the scheduler may be wrong.');
            }
            return match (true) {
                str_starts_with(strtolower($url), 'https://') => HealthResult::good('The site uses HTTPS.', ['url' => $url]),
                self::isLocal($url) => HealthResult::recommended('The site runs on a local development address without HTTPS. Use HTTPS when it goes live.', ['url' => $url]),
                default => HealthResult::critical('The site address does not use HTTPS. Passwords and sessions can be intercepted.', ['url' => $url]),
            };
        }, 'security');

        $h->check('core.writable', 'Writable folders', static function () use ($cms): HealthResult {
            $dirs = ['storage' => $cms->paths->storage(), 'storage/framework' => $cms->paths->storage('framework'), 'logs' => $cms->paths->logs(), 'storage/app (uploads)' => $cms->paths->storage('app')];
            $details = [];
            foreach ($dirs as $label => $dir) {
                $details[$label] = is_dir($dir) && is_writable($dir);
            }
            $bad = array_keys(array_filter($details, static fn($ok) => !$ok));
            return $bad === []
                ? HealthResult::good('All folders Kestello writes to are writable.', $details)
                : HealthResult::critical('Kestello cannot write to: ' . implode(', ', $bad) . '. Check folder permissions.', $details);
        }, 'reliability');

        $h->check('core.disk-space', 'Free disk space', static function () use ($cms): HealthResult {
            $free = @disk_free_space($cms->paths->storage());
            if ($free === false) {
                return HealthResult::good('Free disk space could not be measured on this host.');
            }
            $details = ['free' => Number::fileSize($free)];
            return match (true) {
                $free < 200 * 1024 ** 2 => HealthResult::critical('Only ' . Number::fileSize($free) . ' of disk space is left. Uploads, caches and backups will start failing.', $details),
                $free < 1024 ** 3 => HealthResult::recommended('Less than 1 GB of disk space is left (' . Number::fileSize($free) . ').', $details),
                default => HealthResult::good(Number::fileSize($free) . ' of disk space is free.', $details),
            };
        }, 'reliability');

        $h->check('core.php-limits', 'PHP limits', static function (): HealthResult {
            $memory = self::bytes((string) ini_get('memory_limit'));
            $details = ['memory_limit' => (string) ini_get('memory_limit'), 'upload_max_filesize' => (string) ini_get('upload_max_filesize'), 'post_max_size' => (string) ini_get('post_max_size'), 'max_execution_time' => (string) ini_get('max_execution_time')];
            if ($memory !== -1 && $memory < 128 * 1024 ** 2) {
                return HealthResult::recommended('PHP memory_limit is ' . ini_get('memory_limit') . '; at least 128M is recommended.', $details);
            }
            if (self::bytes((string) ini_get('post_max_size')) !== -1 && self::bytes((string) ini_get('post_max_size')) > 0 && self::bytes((string) ini_get('post_max_size')) < self::bytes((string) ini_get('upload_max_filesize'))) {
                return HealthResult::recommended('post_max_size is smaller than upload_max_filesize, so large uploads fail without a clear error.', $details);
            }
            return HealthResult::good('PHP limits look fine.', $details);
        }, 'performance');

        $h->check('core.database', 'Database', static function (): HealthResult {
            $db = DatabaseManager::getConnection();
            $started = hrtime(true);
            $version = (string) $db->query('SELECT ' . ($db->driver() === 'sqlite' ? 'sqlite_version()' : 'VERSION()'))->fetchColumn();
            $ms = (int) round((hrtime(true) - $started) / 1e6);
            $details = ['driver' => $db->driver(), 'version' => explode(',', $version)[0], 'round_trip_ms' => $ms];
            return $ms > 250
                ? HealthResult::recommended("The database answered slowly ({$ms} ms for a trivial query).", $details)
                : HealthResult::good('The database is reachable.', $details);
        }, 'reliability');

        $h->check('core.update-stopped', 'Updates', static function () use ($cms): HealthResult {
            $stopped = (new \Kestello\Cms\Package\Update\UpdateJournal($cms->paths->storage()))->stopped();
            if ($stopped === null) {
                return HealthResult::good('No update stopped halfway.');
            }
            $details = ['owner' => $stopped['owner'], 'from' => $stopped['from'], 'to' => $stopped['to'], 'attempts' => $stopped['attempts']];

            return HealthResult::critical(
                "The update of {$stopped['owner']} from {$stopped['from']} to {$stopped['to']} stopped halfway and was not put back. Visitors see the maintenance page until it is.",
                $details,
                ['Open Updates', '/admin/system/updates'],
            );
        });
        $h->check('core.drivers', 'Infrastructure drivers', static function () use ($cms): HealthResult {
            $degraded = $cms->drivers->degraded();
            $details = [];
            foreach (array_keys($cms->drivers->subsystems()) as $s) {
                $details[$s] = isset($degraded[$s]) ? 'fallback' : 'ok';
            }
            if ($degraded === []) {
                return HealthResult::good('Every configured driver is working.', $details);
            }
            $lines = [];
            foreach ($degraded as $s => $info) {
                $lines[] = "{$s}: the {$info['driver']} driver failed ({$info['error']})";
            }
            return HealthResult::critical('Running on a fallback because a driver failed. ' . implode('; ', $lines), $details, ['Review drivers', '/admin/system/platform']);
        }, 'reliability');

        $h->check('core.scheduler', 'Scheduled tasks', static function () use ($cms): HealthResult {
            $beat = CoreTasks::cache($cms)->get(ScheduleRunner::HEARTBEAT_KEY);
            if (!is_array($beat)) {
                return HealthResult::critical('Scheduled tasks have never run. Add a cron job (* * * * * php ' . $cms->paths->root() . '/bin/cms schedule:run) or enable the web runner.', [], ['Scheduler', '/admin/system/schedule']);
            }
            $ago = time() - (int) $beat['at'];
            $details = ['last_run_seconds_ago' => $ago, 'via' => (string) ($beat['via'] ?? '')];
            return $ago > 3600
                ? HealthResult::critical("Scheduled tasks last ran {$ago} seconds ago. Cleanup, emails and plugin tasks are not running.", $details)
                : ($ago > 600 ? HealthResult::recommended("Scheduled tasks last ran {$ago} seconds ago; they should run every minute.", $details) : HealthResult::good('Scheduled tasks are running.', $details));
        }, 'reliability');

        $h->check('core.queue', 'Background jobs', static function () use ($cms): HealthResult {
            $backend = $cms->queue->backend();
            if ($backend instanceof SyncQueue) {
                return HealthResult::good('Jobs run immediately during requests (sync driver).', ['driver' => 'sync']);
            }
            $pending = 0;
            $oldest = null;
            foreach ($backend->stats() as $s) {
                $pending += $s['pending'];
                if ($s['oldest'] !== null) {
                    $oldest = min($oldest ?? PHP_INT_MAX, (int) $s['oldest']);
                }
            }
            $beat = CoreTasks::cache($cms)->get(Worker::HEARTBEAT_KEY);
            $beatAgo = is_array($beat) ? time() - (int) $beat['at'] : null;
            $failed = (int) DatabaseManager::getConnection()->table('failed_jobs')->count();
            $details = ['pending' => $pending, 'oldest_seconds' => $oldest === null ? null : time() - $oldest, 'worker_seen_seconds_ago' => $beatAgo, 'failed' => $failed];
            if ($oldest !== null && time() - $oldest > 600 && ($beatAgo === null || $beatAgo > 600)) {
                return HealthResult::critical("{$pending} job(s) are waiting and no worker has been seen for 10 minutes. Start one: php bin/cms queue:work (or let the scheduler process jobs).", $details, ['Queue', '/admin/system/queue']);
            }
            if ($failed > 0) {
                return HealthResult::recommended("{$failed} job(s) failed. Retry or remove them.", $details, ['Failed jobs', '/admin/system/queue']);
            }
            return HealthResult::good('Background jobs are being processed.', $details);
        }, 'reliability');

        $h->check('core.storage', 'Storage disks', static function () use ($cms): HealthResult {
            $details = [];
            $failed = [];
            foreach ($cms->storage->names() as $name) {
                try {
                    $disk = $cms->storage->disk($name);
                    if ($disk instanceof ReadOnlyDisk) {
                        iterator_to_array($disk->list(''), false);
                    } else {
                        $probe = '.kestello-health-' . bin2hex(random_bytes(4));
                        $disk->put($probe, 'ok');
                        $ok = $disk->get($probe) === 'ok';
                        $disk->delete($probe);
                        if (!$ok) {
                            throw new \RuntimeException('a test file did not read back correctly');
                        }
                    }
                    $details[$name] = 'ok';
                } catch (\Throwable $e) {
                    $details[$name] = 'failed';
                    $failed[] = "{$name} (" . $e->getMessage() . ')';
                }
            }
            return $failed === []
                ? HealthResult::good('Every storage disk can be written and read back.', $details)
                : HealthResult::critical('Storage problems: ' . implode('; ', $failed) . '.', $details);
        }, 'reliability');

        $h->check('core.mail', 'Email delivery', static function (): HealthResult {
            return (new Mailer())->isSmtpConfigured()
                ? HealthResult::good('Email is sent through SMTP.')
                : HealthResult::recommended('Email uses PHP mail(), which often lands in spam or is disabled by hosts. Configure SMTP in settings.', [], ['Mail settings', '/admin/settings']);
        }, 'configuration');

        $h->check('core.extensions', 'Plugins and themes', static function () use ($cms): HealthResult {
            $ext = $cms->extensions();
            if ($ext->isRecoveryMode()) {
                return HealthResult::critical('The site is in recovery mode: plugins are not loaded.');
            }
            $problems = [];
            foreach ($ext->suspensions()->all() as $owner => $info) {
                $problems[] = $owner . ' was suspended after an error' . (is_array($info) && isset($info['reason']) ? ': ' . $info['reason'] : '');
            }
            foreach ($ext->rejectedPlugins() as $slug => $reasons) {
                $problems[] = "plugin:{$slug} was not loaded: " . implode('; ', $reasons);
            }
            if ($ext->activeTheme() === null) {
                $problems[] = 'no theme could be loaded';
            }
            return $problems === []
                ? HealthResult::good(count($ext->activePlugins()) . ' active plugin(s) loaded without problems.')
                : HealthResult::critical(ucfirst(implode('. ', $problems)) . '.', [], ['Plugins', '/admin/system/plugins']);
        }, 'reliability');
    }

    /** localhost, 127.0.0.1, [::1], *.localhost and *.test (development addresses). */
    public static function isLocal(string $url): bool
    {
        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));

        return in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_ends_with($host, '.localhost') || str_ends_with($host, '.test');
    }

    /** "128M" → bytes; -1 stays unlimited. */
    public static function bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }
        $n = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $n * 1024 ** 3,
            'm' => $n * 1024 ** 2,
            'k' => $n * 1024,
            default => $n,
        };
    }
}
