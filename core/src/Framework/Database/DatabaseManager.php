<?php

declare(strict_types=1);

namespace Kestello\Framework\Database;

use RuntimeException;

/**
 * Static accessor for the database connection.
 * Configured during application boot or installer.
 */
final class DatabaseManager
{
    private static ?Connection $connection = null;

    /** @var array<string, Connection|\Closure(): Connection> */
    private static array $named = [];

    /**
     * Set the active database connection.
     */
    public static function setConnection(Connection $connection): void
    {
        self::$connection = $connection;
    }

    /**
     * Get the active database connection.
     */
    public static function getConnection(): Connection
    {
        if (self::$connection === null) {
            throw new RuntimeException('No database connection has been configured.');
        }

        return self::$connection;
    }

    /**
     * Register an extra named connection (a plugin reading an external
     * database). A closure is called once, on first use.
     *
     * @param Connection|\Closure(): Connection $connection
     */
    public static function addConnection(string $name, Connection|\Closure $connection): void
    {
        if (preg_match('/^[a-z][a-z0-9_.-]{0,63}$/', $name) !== 1 || $name === 'default') {
            throw new \InvalidArgumentException("Invalid connection name [{$name}].");
        }
        self::$named[$name] = $connection;
    }

    /** The default connection (null or "default"), or a named one. */
    public static function connection(?string $name = null): Connection
    {
        if ($name === null || $name === 'default') {
            return self::getConnection();
        }
        $c = self::$named[$name] ?? throw new RuntimeException("No database connection named [{$name}].");
        if ($c instanceof \Closure) {
            $c = self::$named[$name] = $c();
        }

        return $c;
    }

    /**
     * Check if a connection has been configured.
     */
    public static function hasConnection(): bool
    {
        return self::$connection !== null;
    }

    /**
     * Resolve a database config for a site: SQLite paths relative to the
     * site root become absolute.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function resolveConfig(array $config, string $root): array
    {
        if (($config['driver'] ?? 'mysql') === 'sqlite') {
            $path = (string) ($config['database'] ?? '');
            if ($path !== '' && $path !== ':memory:' && !str_starts_with($path, '/') && preg_match('#^[A-Za-z]:[/\\\\]#', $path) !== 1) {
                $config['database'] = rtrim($root, '/') . '/' . $path;
            }
        }

        return $config;
    }

    /**
     * Reset the connection (for testing).
     */
    public static function reset(): void
    {
        self::$named = [];
        self::$connection = null;
    }
}
