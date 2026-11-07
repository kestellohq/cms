<?php

declare(strict_types=1);

namespace Kestello\Framework\Storage;

use Closure;
use Kestello\Contracts\Storage\Disk;
use Kestello\Contracts\Storage\StorageException;
use Kestello\Framework\Core\ExecutionContext;
use Kestello\Framework\Http\Client\HttpClient;
use Kestello\Framework\Storage\S3\S3Disk;

/**
 * Named disks ($cms->storage->disk('media')).
 *
 * Disks come from config/storage.php ("disks" => [name => [driver, …]]),
 * with core defaults for media, public, private and backups. Plugins add
 * disks with define('acme-reports', [...]) (site config still wins, so the
 * admin can point them at S3) and drivers with extend('bunny', factory).
 *
 * Drivers: local, s3, scoped (disk + prefix), readonly (disk).
 *
 * @api
 */
final class StorageManager
{
    /** @var array<string, array<string, mixed>> */
    private array $defined = [];

    /** @var array<string, array<string, mixed>> site config (wins over defined) */
    private array $configured = [];

    /** @var array<string, array{factory: Closure, owner: string}> */
    private array $drivers = [];

    /** @var array<string, Disk> */
    private array $resolved = [];

    /** @var array<string, true> */
    private array $resolving = [];

    private string $root;
    private ?Closure $http = null;
    private ?Closure $signer = null;
    private ?Closure $cache = null;

    public function __construct(?string $root = null)
    {
        $this->root = rtrim($root ?? sys_get_temp_dir() . '/kestello-storage', '/');
        $this->registerCoreDrivers();
        $this->defineCoreDisks();
    }

    /**
     * @internal Kernel wiring.
     * @param array<string, array<string, mixed>> $disks site config
     * @param (Closure(): HttpClient)|null        $http
     * @param (Closure(string $disk, string $path, int $seconds, array<string, string> $options): string)|null $signer temporary URLs for local disks
     * @param (Closure(): ?\Kestello\Contracts\Cache\Store)|null $cache for the cached driver
     */
    public function configure(string $root, array $disks, ?Closure $http = null, ?Closure $signer = null, ?Closure $cache = null): void
    {
        $this->cache = $cache;
        $this->root = rtrim($root, '/');
        $this->configured = $disks;
        $this->http = $http;
        $this->signer = $signer;
        $this->resolved = [];
        $this->defineCoreDisks();
    }

    /** The storage directory (storage/app): local disks and temporary files live under it. */
    public function root(): string
    {
        return $this->root;
    }

    /**
     * Declare a disk with its default configuration. Plugin disk names must
     * start with the plugin slug ("acme-reports").
     *
     * @param array<string, mixed> $config
     */
    /** @var list<string> installed plugin slugs, so "acme" can't name a disk "acme-seo-…" */
    private static array $packageSlugs = [];

    /** @param list<string> $slugs */
    public static function packageSlugs(array $slugs): void
    {
        self::$packageSlugs = $slugs;
    }

    public function define(string $name, array $config): void
    {
        self::checkName($name);
        $owner = ExecutionContext::current();
        if ($owner !== 'core') {
            $slug = explode(':', $owner, 2)[1] ?? '';
            if (!str_starts_with($name, $slug . '-') && $name !== $slug) {
                throw new StorageException("{$owner} disk names must start with \"{$slug}-\", got \"{$name}\".");
            }
            foreach (self::$packageSlugs as $other) {
                if (strlen($other) > strlen($slug) && str_starts_with($other, $slug . '-') && ($name === $other || str_starts_with($name, $other . '-'))) {
                    throw new StorageException("Disk \"{$name}\" belongs to the plugin \"{$other}\".");
                }
            }
            if (isset($this->defined[$name]) && ($this->defined[$name]['_owner'] ?? 'core') !== $owner) {
                throw new StorageException("Disk \"{$name}\" is already defined.");
            }
        }
        $this->defined[$name] = $config + ['_owner' => $owner];
        unset($this->resolved[$name]);
    }

    /**
     * Add a driver. The factory receives the disk config and this manager.
     *
     * @param Closure(array<string, mixed> $config, StorageManager $manager): Disk $factory
     */
    public function extend(string $driver, Closure $factory): void
    {
        $owner = ExecutionContext::current();
        if (isset($this->drivers[$driver]) && $this->drivers[$driver]['owner'] !== $owner) {
            throw new StorageException("Storage driver \"{$driver}\" is already registered.");
        }
        $this->drivers[$driver] = ['factory' => $factory, 'owner' => $owner];
    }

    public function disk(string $name = 'media'): Disk
    {
        if (isset($this->resolved[$name])) {
            return $this->resolved[$name];
        }
        if (isset($this->resolving[$name])) {
            throw new StorageException("Disk \"{$name}\" refers to itself.");
        }
        $config = $this->config($name) ?? throw new StorageException("Unknown disk \"{$name}\".");
        $driver = (string) ($config['driver'] ?? 'local');
        $entry = $this->drivers[$driver] ?? throw new StorageException("Disk \"{$name}\" uses unknown driver \"{$driver}\".");

        $this->resolving[$name] = true;
        try {
            $disk = ExecutionContext::run($entry['owner'], fn() => ($entry['factory'])($config + ['_name' => $name], $this));
        } finally {
            unset($this->resolving[$name]);
        }
        if (!$disk instanceof Disk) {
            throw new StorageException("Driver \"{$driver}\" did not return a disk.");
        }

        return $this->resolved[$name] = $disk;
    }

    /**
     * Effective config of a disk (site config over defined defaults).
     *
     * @return array<string, mixed>|null
     */
    public function config(string $name): ?array
    {
        $defined = $this->defined[$name] ?? null;
        $site = $this->configured[$name] ?? null;
        if ($defined === null && $site === null) {
            return null;
        }
        // A site entry that names a driver replaces the defaults; otherwise it overrides keys.
        $config = isset($site['driver']) && ($site['driver'] !== ($defined['driver'] ?? 'local')) ? $site : array_replace($defined ?? [], $site ?? []);
        $config['_owner'] = $defined['_owner'] ?? 'core';

        return $config;
    }

    /** @return list<string> */
    public function names(): array
    {
        $names = array_unique([...array_keys($this->defined), ...array_keys($this->configured)]);
        sort($names);

        return $names;
    }

    /** @return list<string> */
    public function drivers(): array
    {
        return array_keys($this->drivers);
    }

    /** Local disks whose URL is a path on this site ("/files"), served by PHP. @return array<string, string> name => url path */
    public function servedLocally(): array
    {
        $out = [];
        foreach ($this->names() as $name) {
            $c = $this->config($name);
            $url = (string) ($c['url'] ?? '');
            if (($c['driver'] ?? 'local') === 'local' && str_starts_with($url, '/') && !str_starts_with($url, '//') && ($c['serve'] ?? true)) {
                $out[$name] = rtrim($url, '/');
            }
        }

        return $out;
    }

    private function defineCoreDisks(): void
    {
        $core = [
            'media' => ['driver' => 'local', 'root' => 'media', 'url' => '/uploads', 'visibility' => 'public'],
            'public' => ['driver' => 'local', 'root' => 'public', 'url' => '/files', 'visibility' => 'public'],
            'private' => ['driver' => 'local', 'root' => 'private', 'visibility' => 'private'],
            'backups' => ['driver' => 'local', 'root' => 'backups', 'visibility' => 'private'],
        ];
        foreach ($core as $name => $config) {
            $this->defined[$name] = $config + ['_owner' => 'core'];
        }
    }

    private function registerCoreDrivers(): void
    {
        $this->drivers['local'] = ['owner' => 'core', 'factory' => function (array $c): Disk {
            $root = (string) ($c['root'] ?? $c['_name']);
            if (!str_starts_with($root, '/')) {
                $root = $this->root . '/' . StoragePath::file($root);
            }
            $name = (string) $c['_name'];
            $signer = $this->signer === null ? null : fn(string $path, int $seconds, array $options) => ($this->signer)($name, $path, $seconds, $options);

            return new LocalDisk($root, isset($c['url']) ? (string) $c['url'] : null, (string) ($c['visibility'] ?? 'private'), $signer);
        }];
        $this->drivers['s3'] = ['owner' => 'core', 'factory' => function (array $c): Disk {
            return new S3Disk($c, $this->http !== null ? ($this->http)() : new HttpClient());
        }];
        $this->drivers['scoped'] = ['owner' => 'core', 'factory' => function (array $c, self $m): Disk {
            return new ScopedDisk($m->disk((string) ($c['disk'] ?? throw new StorageException('A scoped disk needs "disk".'))), (string) ($c['prefix'] ?? ''));
        }];
        $this->drivers['readonly'] = ['owner' => 'core', 'factory' => function (array $c, self $m): Disk {
            return new ReadOnlyDisk($m->disk((string) ($c['disk'] ?? throw new StorageException('A readonly disk needs "disk".'))));
        }];
        $this->drivers['mirror'] = ['owner' => 'core', 'factory' => function (array $c, self $m): Disk {
            $disks = array_values((array) ($c['disks'] ?? []));
            if (count($disks) !== 2 || !is_string($disks[0]) || !is_string($disks[1]) || $disks[0] === $disks[1]) {
                throw new StorageException('A mirror disk needs "disks": two different disk names, the one read from first.');
            }

            return new MirrorDisk($m->disk($disks[0]), $m->disk($disks[1]));
        }];
        $this->drivers['cached'] = ['owner' => 'core', 'factory' => function (array $c, self $m): Disk {
            $inner = $m->disk((string) ($c['disk'] ?? throw new StorageException('A cached disk needs "disk".')));
            $store = $this->cache !== null ? ($this->cache)() : null;

            // without a cache store the disk simply isn't cached
            return $store === null ? $inner : new CachedDisk($inner, $store, (string) $c['_name'], max(1, min(86400, (int) ($c['ttl'] ?? 3600))));
        }];
    }

    private static function checkName(string $name): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $name) !== 1) {
            throw new StorageException("Invalid disk name \"{$name}\".");
        }
    }
}
