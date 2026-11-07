<?php

declare(strict_types=1);

namespace Kestello\Cms\Platform;

use Kestello\Contracts\Security\SecretStore;
use Kestello\Support\Translator;
use Throwable;

/**
 * Effective platform configuration from four layers (highest wins):
 *
 *   env      KESTELLO_<SUBSYSTEM>_DRIVER, KESTELLO_<SUBSYSTEM>_<OPTION>
 *   file     config/platform.php          ['cache' => ['driver' => 'redis', 'host' => …]]
 *   admin    settings table, compiled to storage/framework/platform.php
 *   default  core/config/platform.php, then the driver schema's defaults
 *
 * A layer that names a different driver than the winning one contributes
 * nothing (its options belong to that other driver). Layers without a
 * "driver" key (typical for env) apply their options to whichever driver wins.
 *
 * Keys set by env or file are locked: the admin can see but not change them.
 */
final class PlatformConfig
{
    public const array LAYERS = ['default', 'admin', 'file', 'env'];

    /** @var array<string, array{driver: string, options: array<string, mixed>}>|null */
    private ?array $admin = null;

    /** @var array<string, array<string, mixed>>|null */
    private ?array $coreDefaults = null;

    /** @var array<string, mixed>|null */
    private ?array $siteFile = null;

    /**
     * @param array<string, string> $env usually getenv()
     */
    public function __construct(
        private readonly DriverRegistry $registry,
        private readonly PlatformStore $store,
        private readonly ?SecretStore $secrets,
        private readonly string $coreConfigFile,
        private readonly string $siteConfigFile,
        private readonly string $compiledFile,
        private readonly array $env = [],
    ) {}

    // ── Effective values ────────────────────────────────────────────

    /**
     * @return array{
     *     driver: string,
     *     driverSource: string,
     *     options: array<string, mixed>,
     *     sources: array<string, string>,
     *     errors: array<string, list<string>>
     * } options are validated and decrypted
     */
    public function effective(string $subsystem): array
    {
        $definition = $this->registry->getSubsystem($subsystem);
        $layers = $this->layers($subsystem);

        $driver = $definition->default;
        $driverSource = 'default';
        foreach (self::LAYERS as $name) {
            if (isset($layers[$name]['driver'])) {
                $driver = (string) $layers[$name]['driver'];
                $driverSource = $name;
            }
        }

        $driverDefinition = $this->registry->driver($subsystem, $driver);
        $schemaKeys = $driverDefinition !== null ? array_keys($driverDefinition->schema->properties()) : [];

        $options = [];
        $sources = [];
        foreach (self::LAYERS as $name) {
            $layer = $layers[$name] ?? [];
            if (isset($layer['driver']) && $layer['driver'] !== $driver) {
                continue;
            }
            foreach ($layer['options'] ?? [] as $key => $value) {
                if (in_array($key, $schemaKeys, true)) {
                    $options[$key] = $value;
                    $sources[$key] = $name;
                }
            }
        }

        $errors = [];
        if ($driverDefinition === null) {
            $errors['driver'][] = Translator::get('Unknown driver "{driver}".', ['driver' => $driver]);
        } else {
            foreach ($driverDefinition->secrets as $secret) {
                if (($sources[$secret] ?? null) === 'admin' && is_string($options[$secret] ?? null)) {
                    try {
                        $options[$secret] = $this->open($subsystem, $secret, $options[$secret]);
                    } catch (Throwable $e) {
                        unset($options[$secret], $sources[$secret]);
                        $errors[$secret][] = $e->getMessage();
                    }
                }
            }

            [$clean, $validation] = $driverDefinition->schema->validate($options, coerce: true);
            $errors += $validation;
            $options = $validation === [] ? array_filter((array) $clean, static fn($v) => $v !== null) : $options;
            foreach (array_keys($options) as $key) {
                $sources[$key] ??= 'default';
            }
        }

        return compact('driver', 'driverSource', 'options', 'sources', 'errors');
    }

    /**
     * For DriverRegistry::resolve(). Throws when the config is invalid so the
     * registry falls back.
     *
     * @return array{driver: string, options: array<string, mixed>}
     */
    public function resolve(string $subsystem): array
    {
        $effective = $this->effective($subsystem);
        if ($effective['errors'] !== []) {
            throw new PlatformException("Invalid [{$subsystem}] configuration.", $effective['errors']);
        }

        return ['driver' => $effective['driver'], 'options' => $effective['options']];
    }

    /**
     * Raw layer values for a subsystem (admin secrets still sealed).
     *
     * @return array<string, array{driver?: string, options?: array<string, mixed>}>
     */
    public function layers(string $subsystem): array
    {
        return [
            'default' => self::split($this->coreDefaults()[$subsystem] ?? []),
            'admin' => $this->admin()[$subsystem] ?? [],
            'file' => self::split((array) ($this->siteFile()[$subsystem] ?? [])),
            'env' => $this->envLayer($subsystem),
        ];
    }

    // ── Locks ───────────────────────────────────────────────────────

    /** The host has pinned the whole platform config (read-only admin screen). */
    public function isLocked(): bool
    {
        $env = strtolower((string) ($this->env['KESTELLO_PLATFORM_LOCKED'] ?? ''));

        return in_array($env, ['1', 'true', 'yes', 'on'], true) || ($this->siteFile()['admin_editable'] ?? true) === false;
    }

    /**
     * Option names (and "driver") that env or file pin for this subsystem.
     *
     * @return list<string>
     */
    public function lockedKeys(string $subsystem): array
    {
        $effective = $this->effective($subsystem);
        $locked = in_array($effective['driverSource'], ['env', 'file'], true) ? ['driver'] : [];

        foreach ($effective['sources'] as $key => $source) {
            if (in_array($source, ['env', 'file'], true)) {
                $locked[] = $key;
            }
        }

        return $locked;
    }

    // ── Admin layer ─────────────────────────────────────────────────

    /** @return array{driver: string, options: array<string, mixed>}|null */
    public function adminValue(string $subsystem): ?array
    {
        return $this->admin()[$subsystem] ?? null;
    }

    /** @param array{driver: string, options: array<string, mixed>}|null $value secrets already sealed */
    public function saveAdmin(string $subsystem, ?array $value): void
    {
        $this->store->save($subsystem, $value);
        $this->compile();
    }

    public function seal(string $subsystem, string $option, string $plaintext): string
    {
        return $this->requireSecrets()->seal($plaintext, "platform.{$subsystem}.{$option}");
    }

    private function open(string $subsystem, string $option, string $sealed): string
    {
        return $this->requireSecrets()->open($sealed, "platform.{$subsystem}.{$option}");
    }

    private function requireSecrets(): SecretStore
    {
        return $this->secrets ?? throw new PlatformException('No app key is configured, so secrets cannot be stored. Run "php bin/cms key:generate".');
    }

    /**
     * Rebuild the compiled file from the store. Returns false (and keeps
     * using an empty admin layer) when the store can't be read.
     */
    public function compile(): bool
    {
        try {
            $values = $this->store->load();
        } catch (Throwable $e) {
            error_log('[kestello] platform: cannot read runtime settings: ' . $e->getMessage());
            $this->admin = [];
            return false;
        }

        $this->admin = $values;
        $dir = dirname($this->compiledFile);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return true; // read-only filesystem: works, just without the cache file
        }

        $php = "<?php\n\n// Compiled platform settings. Rebuilt automatically; do not edit.\n\nreturn " . var_export($values, true) . ";\n";
        $tmp = $this->compiledFile . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $php, LOCK_EX) !== false) {
            @chmod($tmp, 0640);
            @rename($tmp, $this->compiledFile);
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($this->compiledFile, true);
            }
        }

        return true;
    }

    /** @return array<string, array{driver: string, options: array<string, mixed>}> */
    private function admin(): array
    {
        if ($this->admin !== null) {
            return $this->admin;
        }

        if (is_file($this->compiledFile) && is_readable($this->compiledFile)) {
            try {
                $values = require $this->compiledFile;
                if (is_array($values)) {
                    return $this->admin = $values;
                }
            } catch (Throwable) {
                // corrupt: rebuild below
            }
        }

        $this->compile();

        return $this->admin ?? [];
    }

    // ── Other layers ────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function coreDefaults(): array
    {
        return $this->coreDefaults ??= self::requireArray($this->coreConfigFile);
    }

    /** @return array<string, mixed> */
    private function siteFile(): array
    {
        return $this->siteFile ??= self::requireArray($this->siteConfigFile);
    }

    /** @return array{driver?: string, options?: array<string, mixed>} */
    private function envLayer(string $subsystem): array
    {
        $prefix = 'KESTELLO_' . strtoupper(str_replace('-', '_', $subsystem)) . '_';
        $layer = [];

        if (isset($this->env[$prefix . 'DRIVER']) && $this->env[$prefix . 'DRIVER'] !== '') {
            $layer['driver'] = $this->env[$prefix . 'DRIVER'];
        }

        $driver = $this->registry->driver($subsystem, $layer['driver'] ?? '') ?? null;
        $candidates = $driver !== null ? [$driver] : $this->registry->drivers($subsystem);
        foreach ($candidates as $candidate) {
            foreach (array_keys($candidate->schema->properties()) as $option) {
                $name = $prefix . strtoupper($option);
                if (isset($this->env[$name])) {
                    $layer['options'][$option] = $this->env[$name];
                }
            }
        }

        return $layer;
    }

    /**
     * Normalize a file-layer entry: ['driver' => 'x', 'host' => …] or
     * ['driver' => 'x', 'options' => [...]].
     *
     * @param array<string, mixed> $entry
     * @return array{driver?: string, options?: array<string, mixed>}
     */
    private static function split(array $entry): array
    {
        $out = [];
        if (isset($entry['driver'])) {
            $out['driver'] = (string) $entry['driver'];
        }
        $options = isset($entry['options']) && is_array($entry['options']) ? $entry['options'] : $entry;
        unset($options['driver'], $options['options']);
        if ($options !== []) {
            $out['options'] = $options;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private static function requireArray(string $file): array
    {
        if (!is_file($file) || !is_readable($file)) {
            return [];
        }
        $data = require $file;

        return is_array($data) ? $data : [];
    }
}
