<?php

declare(strict_types=1);

namespace Kestello\Cms\Platform;

use Closure;
use Throwable;

/**
 * Inspect, test and change platform drivers. Used by the admin API and the
 * platform:* CLI commands.
 *
 * Every change is validated against the driver schema and connection-tested
 * before it is saved. Secrets are sealed on write and never returned.
 */
final class PlatformManager
{
    /** @param (Closure(object): void)|null $dispatch event dispatcher */
    public function __construct(
        private readonly DriverRegistry $registry,
        private readonly PlatformConfig $config,
        private readonly ?Closure $dispatch = null,
    ) {}

    public function registry(): DriverRegistry
    {
        return $this->registry;
    }

    public function config(): PlatformConfig
    {
        return $this->config;
    }

    /**
     * Safe description of a subsystem (no secret values).
     *
     * @return array<string, mixed>
     */
    public function describe(string $subsystem): array
    {
        $definition = $this->registry->getSubsystem($subsystem);
        $effective = $this->config->effective($subsystem);
        $active = $this->registry->driver($subsystem, $effective['driver']);

        $options = [];
        foreach ($effective['options'] as $key => $value) {
            $options[$key] = $active?->isSecret($key) ? ['set' => $value !== '' && $value !== null] : $value;
        }
        foreach ($active?->secrets ?? [] as $secret) {
            $options[$secret] ??= ['set' => false];
        }

        $drivers = [];
        foreach ($this->registry->drivers($subsystem) as $name => $driver) {
            $drivers[] = [
                'name' => $name,
                'label' => $driver->label,
                'description' => $driver->description,
                'owner' => $this->registry->owner($subsystem, $name),
                'available' => $driver->unavailableReason() === null,
                'unavailableReason' => $driver->unavailableReason(),
                'secrets' => $driver->secrets,
                'schema' => $driver->schema->toOpenApi(),
            ];
        }

        return [
            'name' => $subsystem,
            'label' => $definition->label,
            'description' => $definition->description,
            'driver' => $effective['driver'],
            'driverSource' => $effective['driverSource'],
            'options' => $options,
            'sources' => $effective['sources'],
            'errors' => $effective['errors'],
            'locked' => $this->config->isLocked(),
            'lockedKeys' => $this->config->lockedKeys($subsystem),
            'degraded' => $this->registry->degraded()[$subsystem] ?? null,
            'drivers' => $drivers,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function describeAll(): array
    {
        return array_map(fn(string $s) => $this->describe($s), array_keys($this->registry->subsystems()));
    }

    /**
     * Build the proposed configuration and run its health check.
     *
     * @param array<string, mixed> $options blank/missing secrets reuse the stored ones
     * @return array{ok: bool, message: string, ms: int, errors: array<string, list<string>>}
     */
    public function test(string $subsystem, string $driver, array $options = []): array
    {
        $started = hrtime(true);

        try {
            $clean = $this->prepare($subsystem, $driver, $options)['plain'];
            $instance = $this->registry->make($subsystem, $driver, $clean);
            $this->registry->check($subsystem, $driver, $instance);

            return ['ok' => true, 'message' => 'Connection test passed.', 'ms' => self::ms($started), 'errors' => []];
        } catch (PlatformException $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'ms' => self::ms($started), 'errors' => $e->errors];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'ms' => self::ms($started), 'errors' => []];
        }
    }

    /**
     * Validate, test, then save a subsystem's admin configuration.
     *
     * @param array<string, mixed> $options only admin-editable options; locked keys are rejected
     * @throws PlatformException
     */
    public function update(string $subsystem, string $driver, array $options, ?int $userId = null, bool $skipTest = false): void
    {
        $this->registry->getSubsystem($subsystem);

        if ($this->config->isLocked()) {
            throw new PlatformException('Platform settings are locked by the host (config/platform.php or KESTELLO_PLATFORM_LOCKED).');
        }

        $locked = $this->config->lockedKeys($subsystem);
        $previous = $this->config->effective($subsystem);
        $conflicts = [];
        if (in_array('driver', $locked, true) && $driver !== $previous['driver']) {
            $conflicts['driver'][] = 'Set by ' . self::sourceLabel($previous['driverSource']) . '.';
        }
        foreach ($options as $key => $_) {
            if (in_array($key, $locked, true)) {
                $conflicts[$key][] = 'Set by ' . self::sourceLabel($previous['sources'][$key] ?? 'env') . '; it cannot be changed here.';
            }
        }
        if ($conflicts !== []) {
            throw new PlatformException('Some settings are pinned by the host.', $conflicts);
        }

        $prepared = $this->prepare($subsystem, $driver, $options);

        if (!$skipTest) {
            $result = $this->test($subsystem, $driver, $options);
            if (!$result['ok']) {
                throw new PlatformException('Not saved: ' . $result['message'], $result['errors']);
            }
        }

        $before = $this->config->adminValue($subsystem);
        $this->config->saveAdmin($subsystem, ['driver' => $driver, 'options' => $prepared['stored']]);
        $this->registry->forget($subsystem);

        $changed = [];
        foreach (array_unique([...array_keys($prepared['stored']), ...array_keys($before['options'] ?? [])]) as $key) {
            if (($prepared['stored'][$key] ?? null) !== ($before['options'][$key] ?? null)) {
                $changed[] = $key;
            }
        }

        $this->emit(new PlatformChanged($subsystem, $previous['driver'], $driver, $changed, $userId));
    }

    /** Remove the admin configuration (back to file/env/defaults). */
    public function reset(string $subsystem, ?int $userId = null): void
    {
        $previous = $this->config->effective($subsystem)['driver'];
        $this->config->saveAdmin($subsystem, null);
        $this->registry->forget($subsystem);

        $this->emit(new PlatformChanged($subsystem, $previous, $this->config->effective($subsystem)['driver'], [], $userId, reset: true));
    }

    /**
     * Validate options for a driver. Secrets left blank keep the stored value
     * (when the driver is unchanged). Options pinned by env/file are taken
     * from those layers for testing but not stored.
     *
     * @param array<string, mixed> $options
     * @return array{plain: array<string, mixed>, stored: array<string, mixed>}
     */
    private function prepare(string $subsystem, string $driver, array $options): array
    {
        $definition = $this->registry->driver($subsystem, $driver)
            ?? throw new PlatformException("Unknown driver [{$driver}] for [{$subsystem}].", ['driver' => ['Unknown driver.']]);

        $effective = $this->config->effective($subsystem);
        $sameDriver = $effective['driver'] === $driver;

        $kept = [];
        $admin = $this->config->adminValue($subsystem);
        foreach ($definition->secrets as $secret) {
            if (($options[$secret] ?? '') === '' && $sameDriver && isset($effective['options'][$secret])) {
                $options[$secret] = $effective['options'][$secret];
                if (($effective['sources'][$secret] ?? '') === 'admin' && is_string($admin['options'][$secret] ?? null)) {
                    $kept[$secret] = $admin['options'][$secret];
                }
            } elseif (($options[$secret] ?? null) === '') {
                unset($options[$secret]);
            }
        }

        // Pinned values take part in validation and the test, not in storage.
        $pinned = [];
        if ($sameDriver) {
            foreach ($effective['sources'] as $key => $source) {
                if (in_array($source, ['env', 'file'], true)) {
                    $pinned[$key] = $effective['options'][$key];
                }
            }
        }

        [$clean, $errors] = $definition->schema->validate($pinned + $options, coerce: true);
        if ($errors !== []) {
            throw new PlatformException('Invalid settings.', $errors);
        }
        $plain = array_filter((array) $clean, static fn($v) => $v !== null);

        $stored = [];
        foreach ($plain as $key => $value) {
            if (array_key_exists($key, $pinned) || !array_key_exists($key, $options)) {
                continue; // pinned, or just a schema default
            }
            $stored[$key] = match (true) {
                isset($kept[$key]) => $kept[$key],
                $definition->isSecret($key) => $this->config->seal($subsystem, $key, (string) $value),
                default => $value,
            };
        }

        return ['plain' => $plain, 'stored' => $stored];
    }

    private function emit(object $event): void
    {
        if ($this->dispatch !== null) {
            ($this->dispatch)($event);
        }
    }

    private static function sourceLabel(string $source): string
    {
        return match ($source) {
            'env' => 'an environment variable',
            'file' => 'config/platform.php',
            default => $source,
        };
    }

    private static function ms(int|float $started): int
    {
        return (int) round((hrtime(true) - $started) / 1e6);
    }
}
