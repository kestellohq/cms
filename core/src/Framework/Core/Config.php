<?php

declare(strict_types=1);

namespace Kestello\Framework\Core;

/**
 * Configuration manager with dot-notation access and lazy-loading.
 *
 * Config files live in a directory (default: project-root/config) as plain PHP
 * files that return an array.  The filename (without .php) becomes the
 * top-level key:
 *
 *     config/database.php  ->  Config::get('database.host')
 */
final class Config
{
    /** @var array<string, mixed> Merged configuration values. */
    private array $items = [];

    /** @var array<string, true> Config groups that have already been loaded. */
    private array $loaded = [];

    /**
     * @param string $configPath Absolute path to the configuration directory.
     */
    /** @var list<string> Config directories, lowest priority first. */
    private readonly array $paths;

    /**
     * @param string|list<string> $paths One directory, or several in priority
     *                                   order (lowest first). Later directories
     *                                   override earlier ones key by key, so core
     *                                   defaults can sit under site config.
     */
    public function __construct(string|array $paths)
    {
        $this->paths = array_values((array) $paths);
    }

    /**
     * Retrieve a configuration value using dot-notation.
     *
     * @param string $key     Dot-separated key (e.g. "database.host").
     * @param mixed  $default Returned when the key is not found.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $this->ensureGroupLoaded($key);

        $segments = explode('.', $key);
        $value = $this->items;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Set a configuration value at runtime using dot-notation.
     */
    public function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $target = &$this->items;

        foreach ($segments as $segment) {
            if (!isset($target[$segment]) || !is_array($target[$segment])) {
                $target[$segment] = [];
            }
            $target = &$target[$segment];
        }

        $target = $value;
        unset($target);
    }

    /**
     * Determine whether a configuration key exists.
     */
    public function has(string $key): bool
    {
        $this->ensureGroupLoaded($key);

        $segments = explode('.', $key);
        $value = $this->items;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return false;
            }
            $value = $value[$segment];
        }

        return true;
    }

    /**
     * Return the entire configuration array.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->items;
    }

    // ------------------------------------------------------------------
    // Lazy-loading
    // ------------------------------------------------------------------

    /**
     * Ensure the top-level config group for the given key has been loaded.
     */
    private function ensureGroupLoaded(string $key): void
    {
        $group = explode('.', $key, 2)[0];

        if (isset($this->loaded[$group])) {
            return;
        }

        foreach ($this->paths as $path) {
            $file = $path . '/' . $group . '.php';

            if (!is_file($file)) {
                continue;
            }

            $data = require $file;

            if (is_array($data)) {
                $this->items[$group] = array_replace_recursive($this->items[$group] ?? [], $data);
            }
        }

        $this->loaded[$group] = true;
    }
}
