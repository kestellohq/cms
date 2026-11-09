<?php

declare(strict_types=1);

namespace Kestello\Cms\Import\WordPress;

use Kestello\Cms\Content\Term;
use Kestello\Cms\Content\Terms;
use Kestello\Support\Str;

/**
 * A category, tag or other term. Parents come first (staged by depth). A
 * term whose slug already exists here is used as it is, not duplicated.
 *
 * @internal
 */
final class TermStep
{
    public function __construct(private readonly Run $run) {}

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $p
     */
    public function process(array $item, array $p): Outcome
    {
        $content = $this->run->cms->content;
        $taxonomy = $content->getTaxonomy(Stager::TAXONOMIES[$p['taxonomy']] ?? (string) $p['taxonomy']);
        if ($taxonomy === null) {
            return Outcome::skipped(sprintf('This site has no taxonomy "%s".', $p['taxonomy']));
        }
        $terms = $this->run->cms->container->make(Terms::class);
        $key = (string) $item['source_key'];
        $checksum = SourceMap::checksum($p);
        $parentId = $taxonomy->isHierarchical() && $p['parent'] !== '' ? $this->run->map->targetId('term', $p['taxonomy'] . ':' . $p['parent']) : null;
        $input = [
            'name' => mb_substr(trim(html_entity_decode((string) $p['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8')), 0, 191),
            'slug' => (string) $p['slug'],
            'description' => trim((string) $p['description']) !== '' ? trim(strip_tags((string) $p['description'])) : null,
            'parent_id' => $parentId,
        ];

        $mapped = $this->run->map->get('term', $key);
        $term = $mapped === null ? null : Term::query()->find($mapped['target_id']);
        if ($term instanceof Term) {
            if ($mapped['target_type'] === SourceMap::FOUND) {
                return Outcome::matched((int) $term->getKey(), 'Already on this site; used as it is.');
            }
            if ($mapped['checksum'] === $checksum) {
                return Outcome::unchanged((int) $term->getKey());
            }
            if ($mapped['target_hash'] !== null && $mapped['target_hash'] !== self::hash($term)) {
                return Outcome::kept((int) $term->getKey(), 'Changed on this site since the last import; left as it is.');
            }
            $term = $terms->update($taxonomy, $term, $input, $this->run->principal);
            $this->run->map->put('term', $key, 'term', (int) $term->getKey(), $checksum, self::hash($term));

            return Outcome::updated((int) $term->getKey());
        }

        // already here (made by hand, or by another import): use it
        $id = Term::query()->where('taxonomy', $taxonomy->name)->whereIn('slug', array_values(array_unique([(string) $p['slug'], Str::slug((string) $p['slug'])])))->value('id');
        $existing = $id === null ? null : Term::query()->find((int) $id);
        if ($existing instanceof Term) {
            $this->run->map->put('term', $key, SourceMap::FOUND, (int) $existing->getKey(), $checksum, self::hash($existing));

            return Outcome::matched((int) $existing->getKey(), 'Already on this site; used as it is.');
        }

        $term = $terms->create($taxonomy, $input, $this->run->principal);
        $this->run->map->put('term', $key, 'term', (int) $term->getKey(), $checksum, self::hash($term));

        return Outcome::created((int) $term->getKey());
    }

    private static function hash(Term $term): string
    {
        return SourceMap::checksum([$term->name, $term->slug, $term->description, $term->parent_id]);
    }
}
