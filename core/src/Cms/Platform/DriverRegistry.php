<?php

declare(strict_types=1);

namespace Kestello\Cms\Platform;

use Closure;
use InvalidArgumentException;
use Kestello\Framework\Core\Container;
use Kestello\Framework\Log\Log;
use Throwable;

/**
 * Every swappable infrastructure driver: $cms->drivers.
 *
 *   $cms->drivers->register('cache', new DriverDefinition(name: 'acme-memcached', ...));
 *   $cache = $cms->drivers->resolve('cache');   // lazy, built from the effective config
 *
 * Plugins may only register driver (and subsystem) names equal to their
 * slug or starting with "<slug>-", and never replace an existing one.
 *
 * @api
 */
final class DriverRegistry
{
    private const string NAME = '/^[a-z][a-z0-9-]{0,39}$/';

    /** @var array<string, array{subsystem: Subsystem, owner: string}> */
    private array $subsystems = [];

    /** @var array<string, array<string, array{driver: DriverDefinition, owner: string}>> */
    private array $drivers = [];

    /** @var array<string, object> resolved instances */
    private array $instances = [];

    /** @var array<string, array{driver: string, error: string}> subsystems running on their fallback */
    private array $degraded = [];

    /** @var (Closure(string): array{driver: string, options: array<string, mixed>})|null */
    private ?Closure $configResolver = null;

    /** @var Closure(string): void */
    private Closure $logger;

    public function __construct(private readonly Container $container, ?Closure $logger = null)
    {
        $this->logger = $logger ?? static fn(string $m) => Log::warning($m);
    }

    /** @param Closure(string): array{driver: string, options: array<string, mixed>} $resolver */
    public function setConfigResolver(Closure $resolver): void
    {
        $this->configResolver = $resolver;
        $this->instances = [];
    }

    // ── Registration ────────────────────────────────────────────────

    public function subsystem(Subsystem $subsystem, ?string $owner = null): void
    {
        $owner = self::registrant($owner);
        $this->assertName($subsystem->name, $owner, 'subsystem');
        if (isset($this->subsystems[$subsystem->name])) {
            throw new InvalidArgumentException("Platform subsystem [{$subsystem->name}] is already registered.");
        }
        if (!interface_exists($subsystem->contract) && !class_exists($subsystem->contract)) {
            throw new InvalidArgumentException("Subsystem [{$subsystem->name}] contract [{$subsystem->contract}] does not exist.");
        }

        $this->subsystems[$subsystem->name] = ['subsystem' => $subsystem, 'owner' => $owner];
        $this->drivers[$subsystem->name] ??= [];
    }

    public function register(string $subsystem, DriverDefinition $driver, ?string $owner = null): void
    {
        $owner = self::registrant($owner);
        if (!isset($this->subsystems[$subsystem])) {
            throw new InvalidArgumentException("Unknown platform subsystem [{$subsystem}].");
        }
        $this->assertName($driver->name, $owner, 'driver');
        if (isset($this->drivers[$subsystem][$driver->name])) {
            throw new InvalidArgumentException("Driver [{$driver->name}] for [{$subsystem}] is already registered by {$this->drivers[$subsystem][$driver->name]['owner']}.");
        }

        $this->drivers[$subsystem][$driver->name] = ['driver' => $driver, 'owner' => $owner];
    }

    /**
     * Who is registering: the running extension's slug ("plugin:acme" registers as "acme"), whatever it
     * passes, so a plugin can't claim core's names. Core code (and tests) may name an owner explicitly.
     */
    private static function registrant(?string $given): string
    {
        $current = \Kestello\Framework\Core\ExecutionContext::current();
        if ($current !== 'core') {
            return str_contains($current, ':') ? substr($current, strpos($current, ':') + 1) : $current;
        }

        return $given ?? 'core';
    }

    private function assertName(string $name, string $owner, string $what): void
    {
        if (preg_match(self::NAME, $name) !== 1) {
            throw new InvalidArgumentException("Invalid platform {$what} name [{$name}] (lowercase letters, digits and dashes).");
        }
        if ($owner !== 'core' && $name !== $owner && !str_starts_with($name, $owner . '-')) {
            throw new InvalidArgumentException("Plugin \"{$owner}\" may only register {$what} names \"{$owner}\" or \"{$owner}-…\", not [{$name}].");
        }
    }

    // ── Lookup ──────────────────────────────────────────────────────

    /** @return array<string, Subsystem> */
    public function subsystems(): array
    {
        return array_map(static fn(array $s) => $s['subsystem'], $this->subsystems);
    }

    public function hasSubsystem(string $name): bool
    {
        return isset($this->subsystems[$name]);
    }

    public function getSubsystem(string $name): Subsystem
    {
        return $this->subsystems[$name]['subsystem'] ?? throw new InvalidArgumentException("Unknown platform subsystem [{$name}].");
    }

    /** @return array<string, DriverDefinition> */
    public function drivers(string $subsystem): array
    {
        return array_map(static fn(array $d) => $d['driver'], $this->drivers[$subsystem] ?? []);
    }

    public function driver(string $subsystem, string $name): ?DriverDefinition
    {
        return $this->drivers[$subsystem][$name]['driver'] ?? null;
    }

    public function owner(string $subsystem, string $driver): ?string
    {
        return $this->drivers[$subsystem][$driver]['owner'] ?? null;
    }

    // ── Building ────────────────────────────────────────────────────

    /**
     * Build a driver from validated options (no caching).
     *
     * @param array<string, mixed> $options
     */
    public function make(string $subsystem, string $driver, array $options): object
    {
        $definition = $this->driver($subsystem, $driver)
            ?? throw new PlatformException("Unknown driver [{$driver}] for [{$subsystem}].");

        if (($reason = $definition->unavailableReason()) !== null) {
            throw new PlatformException("Driver [{$driver}] is not available: {$reason}");
        }

        unset($options['clients']);
        foreach ($definition->endpoints as $endpoint) {
            $url = $options[$endpoint] ?? null;
            if (is_string($url) && $url !== '') {
                try {
                    $options['clients'][$endpoint] = new \Kestello\Framework\Http\Client\ServiceClient($this->container->make(\Kestello\Framework\Http\Client\HttpClient::class), $url);
                } catch (\InvalidArgumentException $e) {
                    throw new PlatformException($e->getMessage(), previous: $e);
                }
            }
        }
        $instance = ($definition->factory)($options, $this->container);
        $contract = $this->getSubsystem($subsystem)->contract;
        if (!$instance instanceof $contract) {
            throw new PlatformException("Driver [{$driver}] for [{$subsystem}] must return a {$contract}.");
        }

        return $instance;
    }

    /** Run a driver instance's health check. Throws PlatformException when unhealthy. */
    public function check(string $subsystem, string $driver, object $instance): void
    {
        $health = $this->driver($subsystem, $driver)?->health;
        if ($health === null) {
            return;
        }

        try {
            $result = $health($instance);
        } catch (Throwable $e) {
            throw new PlatformException("Health check failed: {$e->getMessage()}", previous: $e);
        }
        if ($result === false) {
            throw new PlatformException('Health check failed.');
        }
    }

    /**
     * The active driver instance for a subsystem, built on first use from the
     * effective config. If it can't be built, the subsystem's fallback driver
     * (with default options) is used and the failure is logged.
     */
    public function resolve(string $subsystem): object
    {
        if (isset($this->instances[$subsystem])) {
            return $this->instances[$subsystem];
        }

        $definition = $this->getSubsystem($subsystem);

        try {
            ['driver' => $driver, 'options' => $options] = $this->configResolver !== null
                ? ($this->configResolver)($subsystem)
                : ['driver' => $definition->default, 'options' => $this->defaults($subsystem, $definition->default)];

            return $this->instances[$subsystem] = $this->make($subsystem, $driver, $options);
        } catch (Throwable $e) {
            $fallback = $definition->fallback;
            if ($fallback === null || $fallback === ($driver ?? null)) {
                throw $e instanceof PlatformException ? $e : new PlatformException("Cannot start [{$subsystem}]: {$e->getMessage()}", previous: $e);
            }

            ($this->logger)("platform: {$subsystem} driver failed ({$e->getMessage()}); using fallback \"{$fallback}\".");
            $this->degraded[$subsystem] = ['driver' => $fallback, 'error' => $e->getMessage()];

            return $this->instances[$subsystem] = $this->make($subsystem, $fallback, $this->defaults($subsystem, $fallback));
        }
    }

    /** Drop the cached instance (after a config change). */
    public function forget(?string $subsystem = null): void
    {
        if ($subsystem === null) {
            $this->instances = [];
            $this->degraded = [];
        } else {
            unset($this->instances[$subsystem], $this->degraded[$subsystem]);
        }
    }

    /** @return array<string, array{driver: string, error: string}> */
    public function degraded(): array
    {
        return $this->degraded;
    }

    /** @return array<string, mixed> the driver's schema defaults */
    public function defaults(string $subsystem, string $driver): array
    {
        $definition = $this->driver($subsystem, $driver);
        if ($definition === null) {
            return [];
        }

        [$clean] = $definition->schema->validate([]);

        return array_filter((array) $clean, static fn($v) => $v !== null);
    }
}
