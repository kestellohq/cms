<?php

declare(strict_types=1);

namespace Kestello\Cms\Import\WordPress;

use Kestello\Cms\Menus\Menus;
use Kestello\Framework\Database\QueryBuilder;

/**
 * A navigation menu. Links to imported pages, posts and terms follow them
 * when they move; custom links point at the new addresses. Created without a
 * location: the export does not say where the old theme showed it.
 *
 * @internal
 */
final class MenuStep
{
    public function __construct(private readonly Run $run) {}

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $p
     */
    public function process(array $item, array $p): Outcome
    {
        $menus = $this->run->cms->container->make(Menus::class);
        $key = (string) $item['source_key'];
        $left = 0;
        $tree = $this->tree((array) $p['items'], $left);
        // what its links point to here counts too: a link left out because its page failed comes back on Try again
        $checksum = SourceMap::checksum([$p, $tree]);
        $mapped = $this->run->map->get('menu', $key);
        $existing = $mapped === null ? null : $menus->find($mapped['target_id']);
        if ($existing !== null) {
            if ($mapped['checksum'] === $checksum) {
                return Outcome::unchanged((int) $existing['id']);
            }
            if ($mapped['target_hash'] !== null && $mapped['target_hash'] !== SourceMap::checksum([$existing['name'], $existing['items']])) {
                return Outcome::kept((int) $existing['id'], 'Changed on this site since the last import; left as it is.');
            }
        }

        $name = mb_substr(trim((string) $p['name']) !== '' ? trim((string) $p['name']) : $key, 0, 100);
        $id = $menus->save($existing === null ? null : (int) $existing['id'], $name, $tree);
        $saved = $menus->find($id);
        $this->run->map->put('menu', $key, 'menu', $id, $checksum, $saved === null ? null : SourceMap::checksum([$saved['name'], $saved['items']]));
        $message = $left > 0 ? sprintf('%d of its links went to things that were not imported and were left out.', $left) : null;

        return $existing === null ? Outcome::created($id, $message) : Outcome::updated($id, $message);
    }

    /**
     * Nest the flat WordPress items; children of a dropped item move up to its place.
     *
     * @param list<array<string, mixed>> $items in menu order
     * @return list<array<string, mixed>>
     */
    private function tree(array $items, int &$left): array
    {
        $children = [];
        $ids = array_flip(array_map(static fn(array $i) => (int) $i['id'], $items));
        foreach ($items as $i) {
            $parent = (int) $i['parent'];
            $children[isset($ids[$parent]) ? $parent : 0][] = $i;
        }

        return $this->level($children, 0, 1, $left);
    }

    /**
     * @param array<int, list<array<string, mixed>>> $children
     * @return list<array<string, mixed>>
     */
    private function level(array $children, int $parent, int $depth, int &$left): array
    {
        $out = [];
        foreach ($children[$parent] ?? [] as $i) {
            $nested = $this->level($children, (int) $i['id'], $depth + 1, $left);
            $link = $this->link($i);
            if ($link === null) {
                $left++;
                array_push($out, ...$nested);
                continue;
            }
            if ($nested !== []) {
                if ($depth < Menus::MAX_DEPTH) {
                    $link['children'] = $nested;
                } else {
                    $out[] = $link;
                    array_push($out, ...$nested);      // deeper than menus go: alongside instead
                    continue;
                }
            }
            $out[] = $link;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $i
     * @return array<string, mixed>|null
     */
    private function link(array $i): ?array
    {
        $label = trim(html_entity_decode(strip_tags((string) $i['title']), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $link = match ((string) $i['type']) {
            'post_type' => ($id = $this->run->map->targetId('entry', (string) $i['object_id'])) === null ? null : ['kind' => 'entry', 'ref' => $id],
            'taxonomy' => ($id = $this->termId((string) $i['object'], (int) $i['object_id'])) === null ? null : ['kind' => 'term', 'ref' => $id],
            'post_type_archive' => $this->run->cms->content->get((string) $i['object'])?->hasArchive() === true ? ['kind' => 'archive', 'ref' => (string) $i['object']] : null,
            default => $this->custom((string) $i['url'], $label),
        };
        if ($link === null) {
            return null;
        }
        if ($label !== '' && !isset($link['label'])) {
            $link['label'] = mb_substr($label, 0, 100);
        }
        if (!empty($i['new_tab'])) {
            $link['new_tab'] = true;
        }

        return $link;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function custom(string $url, string $label): ?array
    {
        $url = $this->run->links->url(trim($url));
        if ($url === '' || !Menus::safeUrl($url)) {
            return null;
        }

        return ['kind' => 'url', 'url' => $url, 'label' => mb_substr($label !== '' ? $label : $url, 0, 100)];
    }

    private function termId(string $taxonomy, int $wordpressId): ?int
    {
        $key = QueryBuilder::table('import_items')->where('import_id', $this->run->importId)->where('kind', 'term')->where('source_id', $wordpressId)
            ->where('source_key', 'like', $taxonomy . ':%')->value('source_key');

        return $key === null ? null : $this->run->map->targetId('term', (string) $key);
    }
}
