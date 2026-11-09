<?php

declare(strict_types=1);

namespace Kestello\Cms\Search;

use Closure;
use InvalidArgumentException;
use Kestello\Cms\Cms;
use Kestello\Cms\Content\ContentType;
use Kestello\Cms\Content\Entry;
use Kestello\Cms\Content\Field;
use Kestello\Cms\Media\MediaPresenter;
use Kestello\Contracts\Search\Engine;
use Kestello\Contracts\Search\SearchDocument;
use Kestello\Contracts\Search\SearchHit;
use Kestello\Contracts\Search\SearchQuery;
use Kestello\Contracts\Search\SearchResults;
use Kestello\Framework\Core\ExecutionContext;
use Kestello\Framework\Queue\SyncQueue;
use Kestello\Support\Html;
use Throwable;

/**
 * $cms->search: what the site's search knows and how it answers.
 *
 * Only what visitors may see is indexed: published entries of searchable
 * types (public ones by default), their non-internal text fields, block
 * text and term names. Entries are kept in sync through model events, so
 * every write path (editor, API, scheduled publishing, trash) is covered.
 *
 * Plugins add collections (products, events…) with collection(); their
 * documents are rebuilt by search:reindex like entries.
 *
 * @api
 */
final class SearchManager
{
    public const string CONTENT = 'content';
    private const int CHUNK = 100;

    /** @var array<string, array{label: string, all: Closure(): iterable<SearchDocument>, owner: string}> */
    private array $collections = [];

    public function __construct(private readonly Cms $cms) {}

    public function engine(): Engine
    {
        return $this->cms->drivers->resolve('search');
    }

    /**
     * Another collection rebuilt with search:reindex.
     *
     * @param Closure(): iterable<SearchDocument> $all every document it should hold
     */
    public function collection(string $name, string $label, Closure $all): void
    {
        if ($name === self::CONTENT || preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $name) !== 1) {
            throw new InvalidArgumentException("Invalid search collection [{$name}].");
        }
        $this->collections[$name] = ['label' => $label, 'all' => $all, 'owner' => ExecutionContext::current()];
    }

    /** @return array<string, string> name => label */
    public function collections(): array
    {
        return [self::CONTENT => 'Content'] + array_map(static fn(array $c) => $c['label'], $this->collections);
    }

    // ── entries → documents ─────────────────────────────────────────

    /** The document for an entry, or null when visitors can't see it. */
    public function document(Entry $entry, ?ContentType $type = null): ?SearchDocument
    {
        $type ??= $entry->contentType();
        if ($type === null || !$type->isSearchable() || $entry->trashed() || $entry->status !== 'published' || $entry->published_at === null) {
            return null;
        }
        if ($type->isHierarchical() && $entry->publicAncestors() === null) {
            return null;        // under a draft, private or trashed parent: visitors get "not found"
        }
        $data = (array) ($entry->data ?? []);
        $text = [];
        if ($type->hasExcerpt() && (string) $entry->excerpt !== '') {
            $text[] = (string) $entry->excerpt;
        }
        foreach ($type->getFields() as $field) {
            $text = [...$text, ...$this->fieldText($field, $data[$field->name] ?? null)];
        }
        $tags = ['type:' . $type->name];
        if ($entry->author_id !== null) {
            $tags[] = 'author:' . (int) $entry->author_id;
        }
        foreach ($entry->terms as $term) {
            $taxonomy = (string) ($term->pivot['taxonomy'] ?? $term->taxonomy);
            if (preg_match('/^[a-z0-9_-]{1,32}$/', $taxonomy) === 1 && preg_match('/^[A-Za-z0-9_.-]{1,64}$/', (string) $term->slug) === 1) {
                $tags[] = $taxonomy . ':' . $term->slug;
            }
            $text[] = (string) $term->name;
        }
        $body = trim(preg_replace('/\s+/u', ' ', implode("\n", array_filter($text, static fn($t) => $t !== ''))) ?? '');
        $excerpt = (string) $entry->excerpt !== '' ? (string) $entry->excerpt : mb_substr($body, 0, 240);
        $publishedAt = $entry->published_at instanceof \DateTimeInterface ? $entry->published_at->getTimestamp() : (int) strtotime((string) $entry->published_at);

        return new SearchDocument(
            self::CONTENT,
            'entry:' . $entry->getKey(),
            (string) $entry->title,
            $body,
            $entry->path($type),
            $type->name,
            $publishedAt,
            $tags,
            ['excerpt' => mb_substr($excerpt, 0, 300), 'image' => $this->imageOf($type, $data)],
        );
    }

    /** @return list<string> */
    private function fieldText(Field $field, mixed $value): array
    {
        if ($field->isInternal() || $value === null || $value === '' || $value === []) {
            return [];
        }
        $wanted = $field->getOption('searchable');
        $textual = in_array($field->type, ['text', 'textarea', 'rich_text', 'blocks', 'group', 'repeater', 'select', 'multi_select'], true);
        if ($wanted === false || (!$textual && $wanted !== true)) {
            return [];       // emails, links, numbers, colours… stay out unless a field opts in
        }

        return match ($field->type) {
            'rich_text' => [Html::toText((string) $value)],
            'blocks' => $this->blockText($value),
            'group' => is_array($value) ? array_merge([], ...array_map(fn(Field $c) => $this->fieldText($c, $value[$c->name] ?? null), $field->getChildren())) : [],
            'repeater' => is_array($value) ? array_merge([], ...array_map(fn($row) => is_array($row) ? array_merge([], ...array_map(fn(Field $c) => $this->fieldText($c, $row[$c->name] ?? null), $field->getChildren())) : [], array_slice($value, 0, 200))) : [],
            'select', 'multi_select' => array_values(array_filter(array_map(static fn($v) => is_scalar($v) ? (string) ($field->getOption('choices', [])[$v] ?? $v) : '', (array) $value))),
            default => is_scalar($value) ? [(string) $value] : [],
        };
    }

    /** The text every visitor reads in a block tree, as one string (a search description for block-built pages). */
    public function visibleBlockText(mixed $tree): string
    {
        return trim(preg_replace('/\s+/u', ' ', implode(' ', $this->visibleBlockTexts($tree))) ?? '');
    }

    /**
     * The same text, one piece per field in reading order (a heading, a
     * paragraph, a button label), for callers that join it themselves.
     *
     * @return list<string>
     */
    public function visibleBlockTexts(mixed $tree): array
    {
        return array_values(array_filter($this->blockText($tree), static fn($t) => $t !== ''));
    }

    /** Text a visitor reads in a block tree: text-like props, rich text, list items; never bindings. @return list<string> */
    private function blockText(mixed $tree): array
    {
        if (is_string($tree)) {
            return [Html::toText($tree)];        // HTML in a field changed from rich text to blocks
        }
        $out = [];
        foreach (is_array($tree) ? array_slice($tree, 0, 1000) : [] as $node) {
            $def = is_array($node) ? $this->cms->blocks->get((string) ($node['type'] ?? '')) : null;
            if ($def === null) {
                continue;
            }
            // The index is public: skip blocks (and their children) that not
            // every visitor sees, now and always: members-only, role-limited,
            // or shown only within a time window.
            $v = (array) ($node['visibility'] ?? []);
            if (($v['auth'] ?? null) === 'user' || isset($v['roles']) || isset($v['from']) || isset($v['until'])) {
                continue;
            }
            $props = (array) ($node['props'] ?? []);
            foreach ($def->getFields() as $field) {
                $v = $props[$field->name] ?? null;
                if (is_array($v) && isset($v['bind'])) {
                    continue;
                }
                if (in_array($field->type, ['text', 'textarea', 'rich_text', 'repeater', 'group'], true) && !in_array($field->name, ['alt', 'custom', 'empty'], true)
                    && $field->getOption('searchable') !== false) {
                    $out = [...$out, ...$this->fieldText($field, $v)];
                }
            }
            $out = [...$out, ...$this->blockText($node['children'] ?? [])];
        }

        return $out;
    }

    /** @param array<string, mixed> $data */
    private function imageOf(ContentType $type, array $data): ?string
    {
        foreach ($type->getFields() as $field) {
            if ($field->type === 'media' && !$field->isInternal() && is_numeric($data[$field->name] ?? null)) {
                $media = MediaPresenter::many([(int) $data[$field->name]])[(int) $data[$field->name]] ?? null;
                if ($media !== null && $media['kind'] === 'image') {
                    return $media['sizes']->thumb['src'] ?? $media['url'];
                }
            }
        }

        return null;
    }

    // ── keeping the index in sync ──────────────────────────────────

    /**
     * Bring entries' documents up to date. The database engine is updated
     * right away (same database, same transaction); other engines through a
     * background job, so a slow or offline service never blocks saving.
     *
     * @param list<int> $ids
     */
    public function sync(array $ids): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id) => $id > 0)));
        if ($ids === []) {
            return;
        }
        $syncQueue = $this->cms->queue->backend() instanceof SyncQueue;
        try {
            if ($syncQueue || $this->engine() instanceof DatabaseEngine) {
                $this->syncNow($ids);
                return;
            }
        } catch (Throwable $e) {
            // never let search break saving: log it, and retry through the queue when there is a real one
            error_log('[search] index update failed: ' . $e->getMessage());
            if ($syncQueue) {
                return;
            }
        }
        $this->cms->queue->push(new IndexEntries($ids));
    }

    /** @param list<int> $ids @throws \Kestello\Contracts\Search\SearchException */
    public function syncNow(array $ids): void
    {
        $engine = $this->engine();
        $index = [];
        $remove = [];
        $found = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            foreach (Entry::query()->withTrashed()->whereIn('id', $chunk)->with('terms')->get()->all() as $entry) {
                $found[(int) $entry->getKey()] = true;
                $doc = $this->document($entry);
                $doc !== null ? $index[] = $doc : $remove[] = 'entry:' . $entry->getKey();
            }
        }
        foreach ($ids as $id) {
            if (!isset($found[$id])) {
                $remove[] = 'entry:' . $id;       // deleted for good
            }
        }
        $engine->index($index);
        $engine->remove(self::CONTENT, $remove);
    }

    /**
     * Rebuild collections from scratch (search:reindex, a new engine).
     *
     * @param list<string>|null $only collection names; null: all
     * @param (Closure(string, int): void)|null $progress collection, documents so far
     * @return array<string, int> documents per collection
     */
    public function reindex(?array $only = null, ?Closure $progress = null): array
    {
        $engine = $this->engine();
        $counts = [];
        foreach (array_keys($this->collections()) as $name) {
            if ($only !== null && !in_array($name, $only, true)) {
                continue;
            }
            $engine->flush($name);
            $counts[$name] = 0;
            $batch = [];
            $flush = function () use (&$batch, &$counts, $name, $engine, $progress): void {
                $engine->index($batch);
                $counts[$name] += count($batch);
                $batch = [];
                $progress?->__invoke($name, $counts[$name]);
            };
            $source = $name === self::CONTENT ? $this->allEntries() : ($this->collections[$name]['all'])();
            foreach ($source as $doc) {
                if ($doc instanceof SearchDocument && $doc->collection === $name) {
                    $batch[] = $doc;
                    if (count($batch) >= self::CHUNK) {
                        $flush();
                    }
                }
            }
            $flush();
        }

        return $counts;
    }

    /** @return \Generator<SearchDocument> */
    private function allEntries(): \Generator
    {
        $types = array_values(array_map(static fn(ContentType $t) => $t->name, array_filter($this->cms->content->types(), static fn(ContentType $t) => $t->isSearchable())));
        if ($types === []) {
            return;
        }
        $last = 0;
        do {
            $rows = Entry::query()->whereIn('type', $types)->where('status', 'published')->where('id', '>', $last)->with('terms')->orderBy('id')->limit(self::CHUNK)->get()->all();
            foreach ($rows as $entry) {
                $last = (int) $entry->getKey();
                $doc = $this->document($entry);
                if ($doc !== null) {
                    yield $doc;
                }
            }
        } while (count($rows) === self::CHUNK);
    }

    // ── answering visitors ──────────────────────────────────────────

    /**
     * Search what the site shows publicly.
     *
     * @param array{types?: list<string>, page?: int, per_page?: int} $options
     * @return array{query: string, hits: list<array<string, mixed>>, total: int, page: int, last_page: int, facets: list<array{value: string, label: string, count: int}>, engine: string}
     */
    public function search(string $text, array $options = []): array
    {
        $searchable = [];
        foreach ($this->cms->content->types() as $type) {
            if ($type->isSearchable()) {
                $searchable[$type->name] = $type;
            }
        }
        $types = array_values(array_intersect($options['types'] ?? [], array_keys($searchable)));
        $query = new SearchQuery(self::CONTENT, $text, array_map(static fn($t) => 'type:' . $t, $types), (int) ($options['page'] ?? 1), (int) ($options['per_page'] ?? 10), ['type']);
        $results = $this->query($query);
        $terms = $query->terms();

        return [
            'query' => $query->text,
            'hits' => array_map(fn(SearchHit $h) => [
                'id' => $h->id, 'title' => $h->title, 'url' => $h->url,
                'type' => $h->type, 'type_label' => isset($searchable[$h->type]) ? $searchable[$h->type]->singular() : $h->type,
                'published_at' => $h->publishedAt !== null ? gmdate('Y-m-d\TH:i:s\Z', $h->publishedAt) : null,
                'image' => is_string($h->meta['image'] ?? null) ? $h->meta['image'] : null,
                'title_parts' => self::highlight($h->title, $terms),
                'snippet' => self::highlight(self::snippet($h->body !== '' ? $h->body : (string) ($h->meta['excerpt'] ?? ''), $terms), $terms),
            ], array_values(array_filter($results->hits, static fn(SearchHit $h) => isset($searchable[$h->type])))),
            'total' => $results->total,
            'page' => $results->page,
            'last_page' => $results->lastPage(),
            'facets' => array_values(array_filter(array_map(
                static fn($value, $count) => isset($searchable[$value])
                ? ['value' => (string) $value, 'label' => $searchable[$value]->plural(), 'count' => $count] : null,
                array_keys($results->facets['type'] ?? []),
                $results->facets['type'] ?? [],
            ))),
            'engine' => $results->engine,
        ];
    }

    /** Ask the engine; when it fails, answer from the database engine (and say so in the log). */
    public function query(SearchQuery $query): SearchResults
    {
        try {
            return $this->engine()->search($query);
        } catch (Throwable $e) {
            $engine = $this->safeEngineName();
            if ($engine === 'database') {
                error_log('[search] ' . $e->getMessage());

                return new SearchResults([], 0, $query->page, $query->perPage, [], 'database');
            }
            error_log("[search] {$engine} failed, answering from the database: " . $e->getMessage());

            return (new DatabaseEngine())->search($query);
        }
    }

    private function safeEngineName(): string
    {
        try {
            return $this->engine()->name();
        } catch (Throwable) {
            return '?';
        }
    }

    /** About 200 characters around the first matching word, cut at word boundaries. @param list<string> $terms */
    public static function snippet(string $text, array $terms, int $length = 200): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if (mb_strlen($text) <= $length) {
            return $text;
        }
        $lower = mb_strtolower($text);
        $at = null;
        foreach ($terms as $term) {
            if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($term, '/') . '/u', $lower, $m, PREG_OFFSET_CAPTURE) === 1) {
                $pos = mb_strlen(substr($lower, 0, $m[0][1]));
                $at = $at === null ? $pos : min($at, $pos);
            }
        }
        $start = max(0, ($at ?? 0) - intdiv($length, 3));
        $piece = mb_substr($text, $start, $length);
        if ($start > 0) {
            $piece = '…' . ltrim(preg_replace('/^\S*\s/u', '', $piece) ?? $piece);
        }
        if ($start + $length < mb_strlen($text)) {
            $piece = rtrim(preg_replace('/\s\S*$/u', '', $piece) ?? $piece) . '…';
        }

        return $piece;
    }

    /**
     * Split text into plain and matching parts, so pages can mark matches
     * without rendering HTML: [{text, match}].
     *
     * @param list<string> $terms
     * @return list<array{text: string, match: bool}>
     */
    public static function highlight(string $text, array $terms): array
    {
        if ($text === '' || $terms === []) {
            return $text === '' ? [] : [['text' => $text, 'match' => false]];
        }
        usort($terms, static fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        $pattern = '/(?<![\p{L}\p{N}])(?:' . implode('|', array_map(static fn($t) => preg_quote($t, '/'), $terms)) . ')[\p{L}\p{N}]*/iu';
        $parts = preg_split($pattern, $text, -1, PREG_SPLIT_OFFSET_CAPTURE) ?: [[$text, 0]];
        preg_match_all($pattern, $text, $matches);
        $out = [];
        foreach ($parts as $i => [$plain]) {
            if ($plain !== '') {
                $out[] = ['text' => $plain, 'match' => false];
            }
            if (isset($matches[0][$i])) {
                $out[] = ['text' => $matches[0][$i], 'match' => true];
            }
        }

        return $out;
    }
}
