<?php

declare(strict_types=1);

namespace Kestello\Contracts\Cache;

/**
 * Whole-page cache for guests. Pages are stored with tags (entry:12,
 * list:post, content, all); purging a tag drops every page that carries it.
 * Core binds Kestello\Cms\Cache\TaggedPageCache (over the cache store: files,
 * Redis, APCu…); a plugin can bind another (a CDN or Varnish purge).
 */
interface PageCache
{
    /** @return array{status: int, headers: array<string, string>, body: string, etag: string}|null */
    public function get(string $key): ?array;

    /**
     * @param array{status: int, headers: array<string, string>, body: string, etag: string} $page
     * @param list<string> $tags
     */
    public function put(string $key, array $page, array $tags, int $ttl): void;

    /** Drop every page carrying any of these tags. */
    public function purge(string ...$tags): void;

    /** Drop everything. */
    public function flush(): void;
}
