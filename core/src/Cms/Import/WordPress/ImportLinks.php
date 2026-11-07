<?php

declare(strict_types=1);

namespace Kestello\Cms\Import\WordPress;

use Kestello\Cms\Content\ContentRegistry;
use Kestello\Cms\Content\Entry;
use Kestello\Cms\Content\Term;
use Kestello\Cms\Media\Media;
use Kestello\Framework\Database\QueryBuilder;

/**
 * Links for a running import: old addresses are looked up among the staged
 * items, then in the source map, to find what they became on this site.
 *
 * An address of an item that is in the export but not imported yet (a post
 * linking to a later one) is left as it is and the conversion is marked
 * with needsLinks(), so the entry is converted again once everything exists.
 *
 * @internal
 */
final class ImportLinks implements Links
{
    private bool $unresolved = false;
    /** @var array<string, ?array<string, mixed>> */
    private array $items = [];
    /** @var array<int, ?string> */
    private array $mediaUrls = [];

    public function __construct(
        private readonly int $importId,
        private readonly OldSite $site,
        private readonly SourceMap $map,
        private readonly ContentRegistry $content,
    ) {}

    /** Start a new conversion. */
    public function reset(): void
    {
        $this->unresolved = false;
    }

    /** True when the last conversion met an address of an item not imported yet. */
    public function needsLinks(): bool
    {
        return $this->unresolved;
    }

    public function url(string $url): string
    {
        $url = trim($url);
        if (!$this->site->owns($url)) {
            return $url;
        }
        $absolute = str_starts_with($url, '/') && !str_starts_with($url, '//') ? rtrim($this->site->url, '/') . $url : $url;
        $fragment = (string) parse_url($url, PHP_URL_FRAGMENT);
        $fragment = $fragment === '' ? '' : '#' . $fragment;

        $fileKey = $this->site->fileKey($url);
        if ($fileKey !== null) {
            $media = $this->mediaByFileKey($fileKey);
            return $media !== null ? ($this->mediaUrl($media) ?? $absolute) : $absolute;
        }

        $item = null;
        $id = $this->site->queryId($url);
        if ($id !== null) {
            $item = $this->item('id', (string) $id);
        } elseif (($path = $this->site->path($url)) !== null) {
            $item = $this->item('path', $path);
        } elseif ($this->site->isHome($url) && (string) parse_url($url, PHP_URL_QUERY) === '') {
            return '/' . $fragment;     // the old home page is the new one
        }
        if ($item === null) {
            return $absolute;
        }
        $target = $this->target($item);
        if ($target === null) {
            if ($item['state'] === 'pending') {
                $this->unresolved = true;
            }
            return $absolute;
        }

        return $target . ($target[0] === '/' ? $fragment : '');
    }

    public function media(int $attachment, string $url): ?int
    {
        if ($attachment > 0) {
            $id = $this->existingMedia($this->map->targetId('attachment', (string) $attachment));
            if ($id !== null) {
                return $id;
            }
        }
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        $key = $this->site->fileKey($url);

        return $key === null ? null : $this->mediaByFileKey($key);
    }

    public function reusable(int $id): ?string
    {
        $item = $this->item('reusable', (string) $id);
        if ($item === null) {
            return null;
        }
        $payload = json_decode((string) $item['payload'], true);

        return is_array($payload) && is_string($payload['content'] ?? null) ? $payload['content'] : null;
    }

    /**
     * The new address for a staged item, or null when it has none (yet).
     *
     * @param array<string, mixed> $item
     */
    private function target(array $item): ?string
    {
        $kind = (string) $item['kind'];
        if ($kind === 'attachment') {
            $media = $this->existingMedia($this->map->targetId('attachment', (string) $item['source_key']));
            return $media === null ? null : $this->mediaUrl($media);
        }
        if ($kind === 'entry') {
            $id = $this->map->targetId('entry', (string) $item['source_key']);
            $entry = $id === null ? null : Entry::query()->find($id);
            return $entry instanceof Entry ? $entry->path() : null;
        }
        if ($kind === 'redirect') {   // a category or tag archive
            $payload = json_decode((string) $item['payload'], true);
            $termKey = is_array($payload) ? (string) ($payload['key'] ?? '') : '';
            $id = $this->map->targetId('term', $termKey);
            $term = $id === null ? null : Term::query()->find($id);
            if (!$term instanceof Term) {
                return null;
            }
            $taxonomy = $this->content->getTaxonomy((string) $term->taxonomy);
            return $taxonomy === null ? null : $term->path($taxonomy);
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function item(string $by, string $value): ?array
    {
        $cacheKey = $by . "\0" . $value;
        if (!array_key_exists($cacheKey, $this->items)) {
            $q = QueryBuilder::table('import_items')->where('import_id', $this->importId);
            match ($by) {
                'id' => $q->whereIn('kind', ['entry', 'attachment'])->where('source_key', $value),
                'reusable' => $q->where('kind', 'reusable')->where('source_key', $value),
                default => $q->where('link_path', mb_substr($value, 0, 500))->whereIn('kind', ['entry', 'attachment', 'redirect']),
            };
            // an entry wins over an attachment page, which wins over an archive
            $rank = ['entry' => 0, 'attachment' => 1, 'redirect' => 2];
            $rows = $q->limit(10)->get();
            usort($rows, static fn(array $a, array $b) => [$rank[$a['kind']] ?? 9, $a['id']] <=> [$rank[$b['kind']] ?? 9, $b['id']]);
            $this->items[$cacheKey] = $rows[0] ?? null;
        }

        return $this->items[$cacheKey];
    }

    private function mediaByFileKey(string $key): ?int
    {
        $cacheKey = "file\0" . $key;
        if (!array_key_exists($cacheKey, $this->items)) {
            $this->items[$cacheKey] = QueryBuilder::table('import_items')->where('import_id', $this->importId)->where('file_key', mb_substr($key, 0, 500))->whereIn('kind', ['attachment', 'file'])->orderBy('id')->first();
        }
        $item = $this->items[$cacheKey];
        if ($item === null) {
            // imported by an earlier import of this site
            return $this->existingMedia($this->map->targetId('file', $key));
        }

        return $this->existingMedia($this->map->targetId((string) $item['kind'], (string) $item['source_key']));
    }

    private function existingMedia(?int $id): ?int
    {
        if ($id === null) {
            return null;
        }
        if (!array_key_exists($id, $this->mediaUrls)) {
            $media = Media::query()->find($id);
            $this->mediaUrls[$id] = $media instanceof Media ? ($media->url() ?? '') : null;
        }

        return $this->mediaUrls[$id] === null ? null : $id;
    }

    private function mediaUrl(int $id): ?string
    {
        $url = $this->mediaUrls[$id] ?? null;

        return $url === null || $url === '' ? null : $url;
    }
}
