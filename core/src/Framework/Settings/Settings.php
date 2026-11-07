<?php

declare(strict_types=1);

namespace Kestello\Framework\Settings;

use Kestello\Framework\Database\DatabaseManager;
use Kestello\Framework\Database\QueryBuilder;

/**
 * Key-value settings manager backed by the database settings table.
 *
 * Loads all settings on first access and caches them for the request.
 * Changes are written to the database immediately.
 */
final class Settings
{
    private static ?self $instance = null;

    /** @var array<string, string|null> Cached settings: key => value */
    private array $cache = [];

    private bool $loaded = false;

    /**
     * Get the singleton instance.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get a setting value.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $this->ensureLoaded();

        if (!array_key_exists($key, $this->cache)) {
            return $default;
        }

        $value = $this->cache[$key];
        return $value !== null ? $value : $default;
    }

    /**
     * Set a setting value (immediately persisted).
     */
    public function set(string $key, ?string $value, string $group = 'general'): void
    {
        $this->ensureLoaded();

        $now = date('Y-m-d H:i:s');

        $existing = QueryBuilder::table('settings')->where('key', $key)->first();

        if ($existing !== null) {
            QueryBuilder::table('settings')
                ->where('key', $key)
                ->update(['value' => $value, 'group' => $group, 'updated_at' => $now]);
        } else {
            QueryBuilder::table('settings')->insert([
                'key' => $key,
                'value' => $value,
                'group' => $group,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $this->cache[$key] = $value;
    }

    /**
     * Set multiple settings at once.
     *
     * @param array<string, string|null> $values Key => value pairs.
     */
    public function setMany(array $values, string $group = 'general'): void
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $group);
        }
    }

    /**
     * Get all settings for a group.
     *
     * @return array<string, string|null>
     */
    public function group(string $group): array
    {
        $this->ensureLoaded();

        $rows = QueryBuilder::table('settings')
            ->where('group', $group)
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[$row['key']] = $row['value'];
        }

        return $result;
    }

    /**
     * Delete a setting.
     */
    public function delete(string $key): void
    {
        QueryBuilder::table('settings')->where('key', $key)->delete();
        unset($this->cache[$key]);
    }

    /**
     * Check if a setting exists and has a non-empty value.
     */
    public function has(string $key): bool
    {
        $value = $this->get($key);
        return $value !== null && $value !== '';
    }

    /**
     * Reload settings from the database.
     */
    public function reload(): void
    {
        $this->loaded = false;
        $this->cache = [];
        $this->ensureLoaded();
    }

    /**
     * Reset the singleton (for testing).
     */
    public static function destroy(): void
    {
        self::$instance = null;
    }

    private function ensureLoaded(): void
    {
        if ($this->loaded) {
            return;
        }

        if (!DatabaseManager::hasConnection()) {
            $this->loaded = true;
            return;
        }

        $rows = QueryBuilder::table('settings')->get();

        foreach ($rows as $row) {
            $this->cache[$row['key']] = $row['value'];
        }

        $this->loaded = true;
    }
}
