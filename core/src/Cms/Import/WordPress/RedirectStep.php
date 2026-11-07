<?php

declare(strict_types=1);

namespace Kestello\Cms\Import\WordPress;

use Kestello\Cms\Content\Entry;
use Kestello\Cms\Content\Term;
use Kestello\Cms\Media\Media;
use Kestello\Cms\Redirects\Redirects;
use Kestello\Framework\Database\QueryBuilder;

/**
 * A permanent redirect from an old address to where the item lives now, so
 * links and search results keep working. Redirects only answer addresses
 * nothing on this site answers, so they never hide a page.
 *
 * @internal
 */
final class RedirectStep
{
    public function __construct(private readonly Run $run) {}

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $p
     */
    public function process(array $item, array $p): Outcome
    {
        if ($this->run->option('redirects', true) === false) {
            return Outcome::skipped('Redirects were turned off for this import.');
        }
        $from = Redirects::normalize((string) $p['from']);
        $to = $this->target((string) $p['kind'], (string) $p['key']);
        if ($from === null || $from === '/') {
            return Outcome::skipped('The old address is the home page.');
        }
        if ($to === null) {
            return Outcome::skipped('What it pointed to was not imported or is not published.');
        }
        if (Redirects::normalize($to) === $from) {
            return Outcome::skipped('The address is the same here.');
        }

        $redirects = $this->run->cms->container->make(Redirects::class);
        $existing = QueryBuilder::table('redirects')->where('match', 'exact')->where('source', $from)->first();
        $entryId = $p['kind'] === 'entry' ? $this->run->map->targetId('entry', (string) $p['key']) : null;
        $input = ['source' => $from, 'target' => $to, 'status' => 301, 'match' => 'exact', 'note' => 'Imported from WordPress'];
        if ($existing !== null) {
            if ((string) $existing['target'] === $to) {
                return Outcome::unchanged((int) $existing['id']);
            }
            if ($existing['origin'] !== 'import') {
                return Outcome::kept((int) $existing['id'], 'This site already redirects this address somewhere else.');
            }
            $mapped = $this->run->map->get('redirect', $from);
            if ($mapped !== null && $mapped['target_hash'] !== null && $mapped['target_hash'] !== self::hash($existing)) {
                return Outcome::kept((int) $existing['id'], 'Changed on this site since the last import; left as it is.');
            }
            $rule = $redirects->save($input, (int) $existing['id'], 'import', $entryId);
            $this->remember($from, $input, (int) $rule['id']);

            return Outcome::updated((int) $rule['id']);
        }
        $rule = $redirects->save($input, null, 'import', $entryId);
        $this->remember($from, $input, (int) $rule['id']);

        return Outcome::created((int) $rule['id']);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function remember(string $from, array $input, int $id): void
    {
        $row = QueryBuilder::table('redirects')->where('id', $id)->first();
        $this->run->map->put('redirect', $from, 'redirect', $id, SourceMap::checksum($input), $row === null ? null : self::hash($row));
    }

    /**
     * What an editor can change about a rule, to notice their edits.
     *
     * @param array<string, mixed> $row
     */
    private static function hash(array $row): string
    {
        return SourceMap::checksum([(string) $row['target'], (int) $row['status'], (string) $row['match']]);
    }

    private function target(string $kind, string $key): ?string
    {
        $id = $this->run->map->targetId($kind, $key);
        if ($id === null) {
            return null;
        }
        if ($kind === 'entry') {
            $entry = Entry::query()->find($id);
            return $entry instanceof Entry && $entry->status === 'published' ? $entry->path() : null;
        }
        if ($kind === 'term') {
            $term = Term::query()->find($id);
            $taxonomy = $term instanceof Term ? $this->run->cms->content->getTaxonomy((string) $term->taxonomy) : null;
            return $taxonomy === null || !$term instanceof Term ? null : $term->path($taxonomy);
        }
        $media = Media::query()->find($id);

        return $media instanceof Media ? $media->url() : null;
    }
}
