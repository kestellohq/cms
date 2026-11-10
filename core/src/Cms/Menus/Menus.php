<?php

declare(strict_types=1);

namespace Kestello\Cms\Menus;

use Kestello\Cms\Content\ContentException;
use Kestello\Cms\Content\ContentRegistry;
use Kestello\Cms\Content\Entry;
use Kestello\Cms\I18n\Labels;
use Kestello\Framework\Database\Model;
use Kestello\Framework\Database\QueryBuilder;
use Kestello\Support\Translator;

/**
 * Menus: a named tree of links, stored as JSON, assigned to locations.
 *
 * An item is { kind, label?, target?, children? } plus, by kind:
 *   entry    ref: entry id     (title and path come from the entry; hidden unless published)
 *   term     ref: term id      (name and term page)
 *   archive  ref: content type (the type's archive, /blog)
 *   url      url: http(s), mailto:, tel:, /path or #anchor
 *
 * Links resolve when a page renders, so renamed or moved entries keep
 * working and unpublished or deleted ones simply drop out.
 *
 * @api
 */
final class Menus
{
    public const int MAX_ITEMS = 300;
    public const int MAX_DEPTH = 4;
    public const array KINDS = ['entry', 'term', 'archive', 'url'];

    public function __construct(private readonly ContentRegistry $content, private readonly MenuRegistry $registry) {}

    /** @return list<array{id: int, name: string, items: list<array<string, mixed>>, locations: list<string>, updated_at: ?string}> */
    public function all(): array
    {
        $locations = [];
        foreach (QueryBuilder::table('menu_locations')->get() as $row) {
            $locations[(int) $row['menu_id']][] = (string) $row['location'];
        }

        return array_map(static fn(array $m) => [
            'id' => (int) $m['id'], 'name' => (string) $m['name'], 'items' => self::decode($m['items']),
            'locations' => $locations[(int) $m['id']] ?? [], 'updated_at' => $m['updated_at'],
        ], QueryBuilder::table('menus')->orderBy('name')->get());
    }

    /** @return array{id: int, name: string, items: list<array<string, mixed>>, locations: list<string>, updated_at: ?string}|null */
    public function find(int $id): ?array
    {
        foreach ($this->all() as $menu) {
            if ($menu['id'] === $id) {
                return $menu;
            }
        }

        return null;
    }

    /**
     * Create (id null) or replace a menu. Returns its id.
     *
     * @param list<mixed> $items
     */
    public function save(?int $id, string $name, array $items): int
    {
        $errors = [];
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100) {
            $errors['name'][] = $name === '' ? 'Give the menu a name.' : 'Must be at most 100 characters.';
        }
        $count = 0;
        $clean = $this->clean($items, 1, 'items', $errors, $count);
        if ($errors !== []) {
            throw ContentException::invalid($errors);
        }
        $now = Model::freshTimestamp();
        $row = ['name' => $name, 'items' => json_encode($clean, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'updated_at' => $now];
        if ($id === null) {
            return (int) QueryBuilder::table('menus')->insert($row + ['created_at' => $now]);
        }
        if (QueryBuilder::table('menus')->where('id', $id)->update($row) === 0 && !QueryBuilder::table('menus')->where('id', $id)->exists()) {
            throw ContentException::conflict('That menu no longer exists.');
        }

        return $id;
    }

    public function delete(int $id): void
    {
        QueryBuilder::table('menus')->where('id', $id)->delete(); // locations cascade
    }

    /** @return array<string, ?int> location => menu id, for every declared location */
    public function assignments(): array
    {
        $out = array_fill_keys(array_keys($this->registry->locations()), null);
        foreach (QueryBuilder::table('menu_locations')->get() as $row) {
            if (array_key_exists((string) $row['location'], $out)) {
                $out[(string) $row['location']] = (int) $row['menu_id'];
            }
        }

        return $out;
    }

    /** Put a menu in a location (null empties it). */
    public function assign(string $location, ?int $menuId): void
    {
        if (!$this->registry->has($location)) {
            throw ContentException::invalid(['location' => ['The active theme has no such location.']]);
        }
        if ($menuId !== null && !QueryBuilder::table('menus')->where('id', $menuId)->exists()) {
            throw ContentException::invalid(['menu_id' => ['No such menu.']]);
        }
        QueryBuilder::table('menu_locations')->where('location', $location)->delete();
        if ($menuId !== null) {
            QueryBuilder::table('menu_locations')->insert(['location' => $location, 'menu_id' => $menuId]);
        }
    }

    /**
     * The menus in every declared location, resolved for the public site.
     *
     * @return array<string, list<array<string, mixed>>> location => tree
     */
    public function forLocations(): array
    {
        $locations = $this->registry->locations();
        if ($locations === []) {
            return [];
        }
        $rows = QueryBuilder::table('menu_locations')->join('menus', 'menus.id', '=', 'menu_locations.menu_id')
            ->whereIn('menu_locations.location', array_keys($locations))->select('menu_locations.location', 'menus.items')->get();
        $trees = [];
        foreach ($rows as $row) {
            $trees[(string) $row['location']] = self::decode($row['items']);
        }
        $links = $this->links($trees);
        $out = array_fill_keys(array_keys($locations), []);
        foreach ($trees as $location => $items) {
            $out[$location] = $this->present($items, $links);
        }

        return $out;
    }

    /**
     * Resolve a stored tree for the editor: each item gets its target's
     * current title, url and whether it is live.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    public function describe(array $items): array
    {
        $links = $this->links([$items], includeHidden: true);
        $walk = function (array $items) use (&$walk, $links): array {
            return array_map(function (array $item) use (&$walk, $links): array {
                $link = $this->linkFor($item, $links);
                return ['resolved' => $link, 'children' => $walk($item['children'] ?? [])] + $item;
            }, $items);
        };

        return $walk($items);
    }

    // ── internals ────────────────────────────────────────────────────

    /**
     * @param list<mixed> $items
     * @param array<string, list<string>> $errors
     * @return list<array<string, mixed>>
     */
    private function clean(array $items, int $depth, string $path, array &$errors, int &$count): array
    {
        if (!array_is_list($items)) {
            $errors[$path][] = Translator::get('Must be a list of items.');
            return [];
        }
        $out = [];
        foreach ($items as $i => $item) {
            $key = "{$path}.{$i}";
            if (++$count > self::MAX_ITEMS) {
                $errors['items'][] = Translator::get('A menu can have at most {max} items.', ['max' => self::MAX_ITEMS]);
                return [];
            }
            if (!is_array($item)) {
                $errors[$key][] = Translator::get('Must be an item.');
                continue;
            }
            $kind = $item['kind'] ?? null;
            if (!in_array($kind, self::KINDS, true)) {
                $errors["{$key}.kind"][] = Translator::get('Must be one of: {list}.', ['list' => implode(', ', self::KINDS)]);
                continue;
            }
            $clean = ['kind' => $kind];
            $label = isset($item['label']) ? trim((string) $item['label']) : '';
            if (mb_strlen($label) > 100) {
                $errors["{$key}.label"][] = Translator::get('Must be at most 100 characters.');
            }
            if ($label !== '') {
                $clean['label'] = $label;
            }
            switch ($kind) {
                case 'entry':
                case 'term':
                    $ref = $item['ref'] ?? null;
                    if (!is_int($ref) || $ref < 1) {
                        $errors["{$key}.ref"][] = ($kind === 'entry' ? Translator::get('Pick a page or post.') : Translator::get('Pick a term.'));
                    } elseif (!QueryBuilder::table($kind === 'entry' ? 'entries' : 'terms')->where('id', $ref)->exists()) {
                        $errors["{$key}.ref"][] = Translator::get('It no longer exists.');
                    }
                    $clean['ref'] = $ref;
                    break;
                case 'archive':
                    $type = $this->content->get((string) ($item['ref'] ?? ''));
                    if ($type === null || !$type->hasArchive()) {
                        $errors["{$key}.ref"][] = Translator::get('Pick a content type with an archive.');
                    }
                    $clean['ref'] = (string) ($item['ref'] ?? '');
                    break;
                case 'url':
                    $url = trim((string) ($item['url'] ?? ''));
                    if (!self::safeUrl($url)) {
                        $errors["{$key}.url"][] = Translator::get('Use a web address (https://…), mailto:, tel:, a path starting with / or an #anchor.');
                    }
                    if ($label === '') {
                        $errors["{$key}.label"][] = Translator::get('Custom links need a label.');
                    }
                    $clean['url'] = $url;
                    break;
            }
            if (!empty($item['new_tab'])) {
                $clean['new_tab'] = true;
            }
            $children = $item['children'] ?? [];
            if ($children !== [] && $children !== null) {
                if ($depth >= self::MAX_DEPTH) {
                    $errors["{$key}.children"][] = Translator::get('Menus nest at most {max} levels.', ['max' => self::MAX_DEPTH]);
                } else {
                    $clean['children'] = $this->clean(is_array($children) ? $children : [null], $depth + 1, "{$key}.children", $errors, $count);
                }
            }
            $out[] = $clean;
        }

        return $out;
    }

    public static function safeUrl(string $url): bool
    {
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            return false;
        }
        if (str_starts_with($url, '#') || (str_starts_with($url, '/') && !str_starts_with($url, '//'))) {
            return true;
        }
        if (preg_match('/^(mailto|tel):[^\s]+$/i', $url) === 1) {
            return true;
        }

        return preg_match('#^https?://[^/\s]+#i', $url) === 1 && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Load every entry and term the trees point at, in two queries.
     *
     * @param list<list<array<string, mixed>>> $trees
     * @return array{entry: array<int, array{label: string, url: ?string, live: bool}>, term: array<int, array{label: string, url: ?string, live: bool}>}
     */
    private function links(array $trees, bool $includeHidden = false): array
    {
        $ids = ['entry' => [], 'term' => []];
        $collect = static function (array $items) use (&$collect, &$ids): void {
            foreach ($items as $item) {
                if (isset($ids[$item['kind'] ?? '']) && is_int($item['ref'] ?? null)) {
                    $ids[$item['kind']][] = $item['ref'];
                }
                $collect($item['children'] ?? []);
            }
        };
        foreach ($trees as $items) {
            $collect($items);
        }
        $out = ['entry' => [], 'term' => []];
        if ($ids['entry'] !== []) {
            // a cached page shows these titles and addresses: it must go when one of them changes
            \Kestello\Cms\Cache\PageTags::add(...array_map(static fn($id) => 'entry:' . (int) $id, array_unique($ids['entry'])));
            foreach (Entry::query()->whereIn('id', array_unique($ids['entry']))->get() as $e) {
                if (!$e instanceof Entry) {
                    continue;
                }
                $type = $this->content->get((string) $e->type);
                $out['entry'][(int) $e->getKey()] = ['label' => (string) $e->title, 'url' => $type === null ? null : $e->url($type), 'live' => $e->status === 'published' && $type !== null && $type->getPath() !== null && (!$type->isHierarchical() || $e->publicAncestors() !== null), 'type_label' => $type === null ? '' : Labels::text($type->singular(), Labels::domain($type->owner))];
            }
        }
        if ($ids['term'] !== []) {
            \Kestello\Cms\Cache\PageTags::add('terms');
            // a term only drafts use has no public page yet
            $inUse = [];
            foreach (\Kestello\Cms\Content\Term::onlyInUse(\Kestello\Cms\Content\Term::query()->whereIn('terms.id', array_unique($ids['term'])))->get() as $used) {
                $inUse[(int) $used->getKey()] = true;
            }
            foreach (QueryBuilder::table('terms')->whereIn('id', array_unique($ids['term']))->get() as $t) {
                $tax = $this->content->getTaxonomy((string) $t['taxonomy']);
                $url = $tax?->getPath() === null ? null : $tax->getPath() . '/' . $t['slug'];
                $out['term'][(int) $t['id']] = ['label' => (string) $t['name'], 'url' => $url, 'live' => $url !== null && isset($inUse[(int) $t['id']]), 'type_label' => $tax === null ? '' : Labels::text($tax->singular(), Labels::domain($tax->owner))];
            }
        }

        return $out;
    }

    /** @return array{label: string, url: ?string, live: bool, type_label?: string} type_label: what the item is (Page, Category), for the editor */
    private function linkFor(array $item, array $links): array
    {
        return match ($item['kind']) {
            'entry', 'term' => $links[$item['kind']][$item['ref']] ?? ['label' => '', 'url' => null, 'live' => false],
            'archive' => ($type = $this->content->get((string) $item['ref'])) !== null && $type->hasArchive()
                ? ['label' => $type->plural(), 'url' => (string) $type->getPath(), 'live' => true] : ['label' => '', 'url' => null, 'live' => false],
            default => ['label' => '', 'url' => (string) ($item['url'] ?? ''), 'live' => true],
        };
    }

    /** @return list<array<string, mixed>> */
    private function present(array $items, array $links): array
    {
        $out = [];
        foreach ($items as $item) {
            $link = $this->linkFor($item, $links);
            if (!$link['live'] || $link['url'] === null) {
                continue;
            }
            $children = $this->present($item['children'] ?? [], $links);
            $url = $link['url'];
            $out[] = [
                'label' => ($item['label'] ?? '') !== '' ? $item['label'] : $link['label'],
                'url' => $url,
                'target' => !empty($item['new_tab']) ? '_blank' : null,
                'children' => $children,
            ];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private static function decode(mixed $json): array
    {
        $items = is_string($json) ? json_decode($json, true) : null;

        return is_array($items) ? $items : [];
    }
}
