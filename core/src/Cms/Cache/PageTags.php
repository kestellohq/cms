<?php

declare(strict_types=1);

namespace Kestello\Cms\Cache;

/**
 * What the page being rendered depends on, collected while it renders:
 *   PageTags::add('entry:12', 'list:post');
 * A page that adds no tags is assumed to depend on any content ("content").
 * PageTags::uncacheable() keeps the current response out of the cache
 * (something personal or time-based ended up in it).
 */
final class PageTags
{
    /** @var array<string, true> */
    private static array $tags = [];

    private static bool $uncacheable = false;

    private static float $startedAt = 0.0;

    private static ?int $expires = null;

    /**
     * The page changes by itself at $timestamp (a block shown from or until
     * then): it is cached until that moment at most.
     */
    public static function until(int $timestamp): void
    {
        self::$expires = self::$expires === null ? $timestamp : min(self::$expires, $timestamp);
    }

    /** @internal seconds the page may be cached, at most $ttl; 0 = not at all */
    public static function ttl(int $ttl): int
    {
        return self::$expires === null ? $ttl : max(0, min($ttl, self::$expires - time()));
    }

    /** @internal when rendering started (a purge after this makes the result stale) */
    public static function startedAt(): float
    {
        return self::$startedAt;
    }

    public static function add(string ...$tags): void
    {
        foreach ($tags as $tag) {
            if (preg_match('/^[a-z0-9][a-z0-9:._-]{0,100}$/', $tag) === 1) {
                self::$tags[$tag] = true;
            }
        }
    }

    public static function uncacheable(): void
    {
        self::$uncacheable = true;
    }

    /** @internal */
    public static function isUncacheable(): bool
    {
        return self::$uncacheable;
    }

    /** @internal @return list<string> */
    public static function all(): array
    {
        return array_keys(self::$tags);
    }

    /** @internal a new request */
    public static function reset(): void
    {
        self::$tags = [];
        self::$uncacheable = false;
        self::$expires = null;
        self::$startedAt = microtime(true);
    }
}
