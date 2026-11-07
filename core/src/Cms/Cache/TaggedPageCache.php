<?php

declare(strict_types=1);

namespace Kestello\Cms\Cache;

use Kestello\Contracts\Cache\PageCache;
use Kestello\Contracts\Cache\Store;

/**
 * Tags as versions: each tag has a version in the store, a page remembers
 * the versions of its tags when cached, and a page whose tag moved on is a
 * miss. Purging is one write per tag whatever the number of pages, and it
 * works on any store (nothing needs to list keys). A tag version that was
 * evicted reads as "0", which never matches a purged one, so eviction can
 * only cause misses, never stale pages.
 */
final class TaggedPageCache implements PageCache
{
    private const string PREFIX = 'pagecache:';

    public function __construct(private readonly Store $store) {}

    public function get(string $key): ?array
    {
        $entry = $this->store->get(self::PREFIX . 'page:' . $key);
        if (!is_array($entry) || !is_array($entry['versions'] ?? null) || !is_array($entry['page'] ?? null)) {
            return null;
        }
        $current = $this->versions(array_keys($entry['versions']));
        foreach ($entry['versions'] as $tag => $version) {
            if (($current[$tag] ?? '0') !== $version) {
                return null;
            }
        }

        return $entry['page'];
    }

    public function put(string $key, array $page, array $tags, int $ttl): void
    {
        $tags = array_values(array_unique([...$tags, 'all']));
        $versions = $this->versions($tags);
        // Purged while it was rendering: it may hold the old content, so don't keep it.
        $started = PageTags::startedAt();
        foreach ($versions as $version) {
            $at = (float) (explode('.', $version, 2)[1] ?? 0);
            if ($started > 0 && $at >= $started) {
                return;
            }
        }
        $this->store->put(self::PREFIX . 'page:' . $key, ['page' => $page, 'versions' => $versions], max(1, $ttl));
    }

    public function purge(string ...$tags): void
    {
        foreach (array_unique($tags) as $tag) {
            // "<random>.<time>": the time tells put() whether a render started before this purge
            $this->store->put(self::PREFIX . 'tag:' . $tag, bin2hex(random_bytes(6)) . '.' . sprintf('%.6F', microtime(true)));
        }
    }

    public function flush(): void
    {
        $this->purge('all');
    }

    /** @param list<string> $tags @return array<string, string> */
    private function versions(array $tags): array
    {
        $keys = array_map(static fn(string $t) => self::PREFIX . 'tag:' . $t, $tags);
        $found = $this->store->many($keys);
        $out = [];
        foreach ($tags as $i => $tag) {
            $v = $found[$keys[$i]] ?? null;
            $out[$tag] = is_string($v) && $v !== '' ? $v : '0';
        }

        return $out;
    }
}
