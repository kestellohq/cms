<?php

declare(strict_types=1);

namespace Kestello\Cms\Platform;

use Kestello\Cms\Api\Schema;
use Kestello\Contracts\Cache\Store;
use Kestello\Contracts\Queue\Queue;
use Kestello\Contracts\Session\SessionStore;
use Kestello\Framework\Cache\ApcuStore;
use Kestello\Framework\Cache\ArrayStore;
use Kestello\Framework\Cache\DatabaseStore;
use Kestello\Framework\Cache\FileStore;
use Kestello\Framework\Cache\RedisStore;
use Kestello\Framework\Core\Paths;
use Kestello\Framework\Database\DatabaseManager;
use Kestello\Framework\Queue\DatabaseQueue;
use Kestello\Framework\Queue\RedisQueue;
use Kestello\Framework\Queue\SyncQueue;
use Kestello\Framework\Redis\RedisClient;
use Kestello\Framework\Session\DatabaseSessionStore;
use Kestello\Framework\Session\FileSessionStore;
use Kestello\Framework\Session\RedisSessionStore;
use RuntimeException;

/**
 * Core subsystems (cache, session) and their drivers. Every key is
 * prefixed per site ("kst:<site id>:" unless configured), so several
 * sites can share one Redis or database safely.
 */
final class CoreDrivers
{
    public static function register(DriverRegistry $registry, Paths $paths, int $sessionLifetime = 7200): void
    {
        $site = substr(hash('sha256', $paths->root()), 0, 8);
        $prefix = static fn(array $o, string $what): string => ($o['prefix'] ?? '') !== '' ? (string) $o['prefix'] : "kst:{$site}:{$what}:";

        // ── Cache ───────────────────────────────────────────────────
        $registry->subsystem(new Subsystem(
            name: 'cache',
            label: 'Cache',
            contract: Store::class,
            default: 'file',
            fallback: 'array',
            description: 'Stores computed values between requests. Also backs locks and rate limits.',
        ));

        $registry->register('cache', new DriverDefinition(
            name: 'file',
            label: 'Files',
            description: 'Files in storage/cache. Works everywhere; one server only.',
            schema: Schema::object([]), // the directory is deliberately not configurable
            factory: static fn() => new FileStore($paths->cache() . '/data'),
            health: static fn(Store $s) => self::probe($s),
        ));
        $registry->register('cache', new DriverDefinition(
            name: 'database',
            label: 'Database',
            description: 'The cache table. Shared by every server; no extra service.',
            schema: Schema::object(['prefix' => self::prefixSchema()]),
            factory: static fn(array $o) => new DatabaseStore(DatabaseManager::getConnection(), $prefix($o, 'c')),
            health: static fn(Store $s) => self::probe($s),
        ));
        $registry->register('cache', new DriverDefinition(
            name: 'redis',
            label: 'Redis / Valkey',
            description: 'Fast and shared. Redis, Valkey, KeyDB, Dragonfly or a managed service.',
            schema: self::redisSchema(),
            factory: static fn(array $o) => new RedisStore(self::redis($o), $prefix($o, 'c')),
            secrets: ['password'],
            health: static fn(Store $s) => self::probe($s),
        ));
        $registry->register('cache', new DriverDefinition(
            name: 'apcu',
            label: 'APCu (memory)',
            description: 'Shared memory of this server\'s PHP workers. Fastest; not shared with cron or other servers.',
            schema: Schema::object(['prefix' => self::prefixSchema()]),
            factory: static function (array $o) use ($prefix): Store {
                if (!ApcuStore::available()) {
                    throw new RuntimeException('APCu is installed but disabled (apc.enabled / apc.enable_cli).');
                }
                return new ApcuStore($prefix($o, 'c'));
            },
            requires: ['ext' => ['apcu']],
            health: static fn(Store $s) => self::probe($s),
        ));
        $registry->register('cache', new DriverDefinition(
            name: 'array',
            label: 'None (per request)',
            description: 'Nothing is kept between requests. For development, and the safe fallback.',
            schema: Schema::object([]),
            factory: static fn() => new ArrayStore(),
        ));

        // ── Sessions ────────────────────────────────────────────────
        $registry->subsystem(new Subsystem(
            name: 'session',
            label: 'Sessions',
            contract: SessionStore::class,
            default: 'database',
            fallback: 'file',
            description: 'Where sign-in sessions are kept. Database or Redis enable the device list and remote sign-out.',
        ));

        $registry->register('session', new DriverDefinition(
            name: 'database',
            label: 'Database',
            description: 'The sessions table, with device list and revocation.',
            schema: Schema::object([]),
            factory: static fn() => new DatabaseSessionStore(DatabaseManager::getConnection(), $sessionLifetime),
            health: static fn(SessionStore $s) => self::probeSession($s),
        ));
        $registry->register('session', new DriverDefinition(
            name: 'redis',
            label: 'Redis / Valkey',
            description: 'Sessions in Redis, with device list and revocation.',
            schema: self::redisSchema(),
            factory: static fn(array $o) => new RedisSessionStore(self::redis($o), $prefix($o, 's'), $sessionLifetime),
            secrets: ['password'],
            health: static fn(SessionStore $s) => self::probeSession($s),
        ));
        $registry->register('session', new DriverDefinition(
            name: 'file',
            label: 'Files',
            description: 'Files in storage/sessions. No device list.',
            schema: Schema::object([]),
            factory: static fn() => new FileSessionStore($paths->sessions(), $sessionLifetime),
            health: static fn(SessionStore $s) => self::probeSession($s),
        ));

        self::registerQueue($registry, $prefix);
    }

    /** @param (\Closure(array<string, mixed>, string): string)|null $prefix */
    public static function registerQueue(DriverRegistry $registry, ?\Closure $prefix = null): void
    {
        $prefix ??= static fn(array $o, string $what): string => ($o['prefix'] ?? '') !== '' ? (string) $o['prefix'] : "kst:{$what}:";
        $registry->subsystem(new Subsystem(
            name: 'queue',
            label: 'Background jobs',
            contract: Queue::class,
            default: 'database',
            fallback: 'database',
            description: 'Where background jobs wait for a worker (or the cron/web runner).',
        ));
        $registry->register('queue', new DriverDefinition(
            name: 'database',
            label: 'Database',
            description: 'The jobs table. Works everywhere, with any number of workers.',
            schema: Schema::object([]),
            factory: static fn() => new DatabaseQueue(DatabaseManager::getConnection()),
            health: static fn(Queue $q) => $q->stats(),
        ));
        $registry->register('queue', new DriverDefinition(
            name: 'redis',
            label: 'Redis / Valkey',
            description: 'Jobs in Redis. Fast, shared by every worker; needs a Redis that persists to disk (appendonly) so queued jobs survive a restart.',
            schema: self::redisSchema(),
            // Redis can go away after the driver was built; jobs then go to the database, never lost.
            factory: static fn(array $o) => new \Kestello\Framework\Queue\FailoverQueue(new RedisQueue(self::redis($o), $prefix($o, 'q')), new DatabaseQueue(DatabaseManager::getConnection())),
            secrets: ['password'],
            health: static fn(Queue $q) => ($q instanceof \Kestello\Framework\Queue\FailoverQueue ? $q->primary() : $q)->stats(),   // Redis itself, not the fallback
        ));
        $registry->register('queue', new DriverDefinition(
            name: 'sync',
            label: 'Run immediately',
            description: 'Jobs run inside the request that pushes them. For development; slows pages down.',
            schema: Schema::object([]),
            factory: static fn() => new SyncQueue(),
        ));
    }

    /** Site search: the database engine here; Meilisearch and Typesense come as plugins. */
    public static function registerSearch(DriverRegistry $registry): void
    {
        $registry->subsystem(new Subsystem(
            name: 'search',
            label: 'Search',
            contract: \Kestello\Contracts\Search\Engine::class,
            default: 'database',
            fallback: 'database',
            description: 'Answers site search. Switching engines rebuilds the index in the background.',
        ));
        $registry->register('search', new DriverDefinition(
            name: 'database',
            label: 'Database',
            description: 'Full-text search in the site database (MySQL, Postgres or SQLite). No extra service; fine for thousands of entries.',
            schema: Schema::object([
                'language' => Schema::string()->max(40)->pattern('[a-z_]+')->default('simple')
                    ->description('Postgres only: the text search configuration ("english", "german"…) for word stemming. "simple" matches words as written.'),
            ]),
            factory: static fn(array $o) => new \Kestello\Cms\Search\DatabaseEngine(null, (string) ($o['language'] ?? 'simple')),
            health: static fn(\Kestello\Contracts\Search\Engine $e) => $e->ping(),
        ));
    }

    public static function redisSchema(): Schema
    {
        return Schema::object([
            'url' => Schema::string()->max(500)->optional()->description('redis://user:pass@host:6379/0 or rediss://… (overrides the fields below)'),
            'host' => Schema::string()->max(255)->default('127.0.0.1'),
            'port' => Schema::int()->min(1)->max(65535)->default(6379),
            'username' => Schema::string()->max(100)->optional(),
            'password' => Schema::string()->max(500)->optional(),
            'database' => Schema::int()->min(0)->max(15)->default(0),
            'tls' => Schema::bool()->default(false),
            'timeout' => Schema::number()->min(0.1)->max(30)->default(2.0),
            'prefix' => self::prefixSchema(),
        ]);
    }

    private static function prefixSchema(): Schema
    {
        return Schema::string()->max(60)->pattern('[A-Za-z0-9:_.-]*')->default('')
            ->description('Key prefix. Empty = derived from this site, so sites sharing a server never collide.');
    }

    /** @param array<string, mixed> $o */
    private static function redis(array $o): RedisClient
    {
        unset($o['prefix']);

        return RedisClient::fromConfig(array_filter($o, static fn($v) => $v !== null && $v !== ''));
    }

    /** Write, read back and delete a probe key. */
    public static function probe(Store $cache): void
    {
        $key = 'probe:' . bin2hex(random_bytes(6));
        $value = bin2hex(random_bytes(8));

        if (!$cache->put($key, $value, 60) || $cache->get($key) !== $value) {
            throw new RuntimeException('Could not write and read back a test value.');
        }
        $cache->forget($key);
    }

    public static function probeSession(SessionStore $store): void
    {
        $id = 'probe' . bin2hex(random_bytes(16));
        if (!$store->write($id, 'probe|b:1;') || $store->read($id) !== 'probe|b:1;') {
            throw new RuntimeException('Could not write and read back a test session.');
        }
        $store->destroy($id);
    }
}
