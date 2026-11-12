<?php

declare(strict_types=1);

namespace Kestello\Cms\Menus;

use Kestello\Cms\Content\ContentException;
use Kestello\Cms\Content\ContentRegistry;
use Kestello\Cms\Content\Entry;
use Kestello\Cms\I18n\ContentLanguages;
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
 * working and unpublished or deleted ones drop out.
 *
 * On a site in several languages each language can put its own menu in a
 * location; one that hasn't uses the main language's. Either way an entry
 * item links to the entry's translation in the page's language when one is
 * published, else to the original under that language's prefix (which
 * shows it marked as not translated yet), and archives and terms get the
 * prefix too.
 *
 * @api
 */
final class Menus
{
    public const int MAX_ITEMS = 300;
    public const int MAX_DEPTH = 4;
    public const array KINDS = ['entry', 'term', 'archive', 'url'];

    public function __construct(private readonly ContentRegistry $content, private readonly MenuRegistry $registry) {}

    /**
     * Every menu, with where it shows: locations in the main language, and
     * placements in every language ('' is the main one).
     *
     * @return list<array{id: int, name: string, items: list<array<string, mixed>>, locations: list<string>, placements: list<array{location: string, locale: string}>, updated_at: ?string}>
     */
    public function all(): array
    {
        $placements = [];
        foreach (QueryBuilder::table('menu_locations')->orderBy('location')->orderBy('locale')->get() as $row) {
            $placements[(int) $row['menu_id']][] = ['location' => (string) $row['location'], 'locale' => (string) $row['locale']];
        }

        return array_map(static fn(array $m) => [
            'id' => (int) $m['id'], 'name' => (string) $m['name'], 'items' => self::decode($m['items']),
            'locations' => array_values(array_column(array_filter($placements[(int) $m['id']] ?? [], static fn(array $p) => $p['locale'] === ''), 'location')),
            'placements' => $placements[(int) $m['id']] ?? [], 'updated_at' => $m['updated_at'],
        ], QueryBuilder::table('menus')->orderBy('name')->get());
    }

    /** @return array{id: int, name: string, items: list<array<string, mixed>>, locations: list<string>, placements: list<array{location: string, locale: string}>, updated_at: ?string}|null */
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

    /**
     * The menu each declared location has in one language: '' for the main
     * one. A language's own choices only: null where it uses the main
     * language's menu.
     *
     * @return array<string, ?int> location => menu id
     */
    public function assignments(string $locale = ''): array
    {
        $out = array_fill_keys(array_keys($this->registry->locations()), null);
        foreach (QueryBuilder::table('menu_locations')->where('locale', $locale)->get() as $row) {
            if (array_key_exists((string) $row['location'], $out)) {
                $out[(string) $row['location']] = (int) $row['menu_id'];
            }
        }

        return $out;
    }

    /**
     * Put a menu in a location, in one language ('' = the main one). Null
     * empties it in the main language, and in another language makes it use
     * the main language's menu again.
     */
    public function assign(string $location, ?int $menuId, string $locale = ''): void
    {
        if (!$this->registry->has($location)) {
            throw ContentException::invalid(['location' => ['The active theme has no such location.']]);
        }
        if ($locale !== '' && !ContentLanguages::has($locale)) {
            throw ContentException::invalid(['locale' => [Translator::get('The site has no such language.')]]);
        }
        if ($menuId !== null && !QueryBuilder::table('menus')->where('id', $menuId)->exists()) {
            throw ContentException::invalid(['menu_id' => ['No such menu.']]);
        }
        QueryBuilder::table('menu_locations')->where('location', $location)->where('locale', $locale)->delete();
        if ($menuId !== null) {
            QueryBuilder::table('menu_locations')->insert(['location' => $location, 'locale' => $locale, 'menu_id' => $menuId]);
        }
    }

    /**
     * The menus in every declared location, resolved for the public site
     * in the language of the page being answered.
     *
     * @return array<string, list<array<string, mixed>>> location => tree
     */
    public function forLocations(): array
    {
        $locations = $this->registry->locations();
        if ($locations === []) {
            return [];
        }
        $language = ContentLanguages::current();
        $rows = QueryBuilder::table('menu_locations')->join('menus', 'menus.id', '=', 'menu_locations.menu_id')
            ->whereIn('menu_locations.location', array_keys($locations))->whereIn('menu_locations.locale', array_values(array_unique(['', $language])))
            ->select('menu_locations.location', 'menu_locations.locale', 'menus.items')->get();
        $trees = [];
        foreach ($rows as $row) {
            if ((string) $row['locale'] === $language || !isset($trees[(string) $row['location']])) {     // the language's own menu wins
                $trees[(string) $row['location']] = self::decode($row['items']);
            }
        }
        $links = $this->links($trees, language: $language);
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
        // a backslash too: browsers read /\host as //host, another site
        if (str_starts_with($url, '#') || (str_starts_with($url, '/') && !str_starts_with($url, '//') && !str_contains($url, '\\'))) {
            return true;
        }
        if (preg_match('/^(mailto|tel):[^\s]+$/i', $url) === 1) {
            return true;
        }

        return preg_match('#^https?://[^/\s]+#i', $url) === 1 && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Load every entry and term the trees point at, in a few queries, as
     * links in $language ('' = the main one, and what the editor sees).
     *
     * @param list<list<array<string, mixed>>> $trees
     * @return array{entry: array<int, array{label: string, url: ?string, live: bool}>, term: array<int, array{label: string, url: ?string, live: bool}>, language: string}
     */
    private function links(array $trees, bool $includeHidden = false, string $language = ''): array
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
        $out = ['entry' => [], 'term' => [], 'language' => $language];
        if ($ids['entry'] !== []) {
            // a cached page shows these titles and addresses: it must go when one of them changes
            \Kestello\Cms\Cache\PageTags::add(...array_map(static fn($id) => 'entry:' . (int) $id, array_unique($ids['entry'])));
            $q = Entry::query();
            $q->whereIn('id', array_values(array_unique($ids['entry'])));
            $entries = array_filter($q->get()->all(), static fn($e) => $e instanceof Entry);
            $translations = $this->translationsIn($entries, $language);
            foreach ($entries as $e) {
                $link = $this->entryLink($e);
                $translation = $translations[(string) $e->translation_group] ?? null;
                if ($translation !== null && ($shown = $this->entryLink($translation))['live']) {
                    $link = $shown;
                } elseif ($language !== '' && $e->language() === '' && $link['url'] !== null) {
                    $link['url'] = ContentLanguages::localize($link['url'], $language);     // the original, marked as not translated yet
                }
                $out['entry'][(int) $e->getKey()] = $link;
            }
        }
        if ($ids['term'] !== []) {
            \Kestello\Cms\Cache\PageTags::add('terms');
            // a term only drafts use has no public page yet
            $inUse = [];
            foreach (\Kestello\Cms\Content\Term::onlyInUse(\Kestello\Cms\Content\Term::query()->whereIn('terms.id', array_unique($ids['term'])))->get() as $used) {
                $inUse[(int) $used->getKey()] = true;
            }
            $rows = QueryBuilder::table('terms')->whereIn('id', array_unique($ids['term']))->get();
            // in another language, a term's translation names the link (its page lists that language's entries)
            $translated = [];
            $groups = array_values(array_unique(array_filter(array_map(static fn(array $t) => (string) ($t['translation_group'] ?? ''), $rows))));
            if ($language !== '' && $groups !== []) {
                foreach (QueryBuilder::table('terms')->whereIn('translation_group', $groups)->where('locale', $language)->get() as $t) {
                    $translated[(string) $t['translation_group']] = $t;
                }
            }
            foreach ($rows as $t) {
                $tax = $this->content->getTaxonomy((string) $t['taxonomy']);
                $live = isset($inUse[(int) $t['id']]);
                $shown = (string) $t['locale'] === $language ? $t : ($translated[(string) ($t['translation_group'] ?? '')] ?? $t);
                $url = $tax?->getPath() === null ? null : ContentLanguages::localize($tax->getPath() . '/' . $shown['slug'], $language);
                $out['term'][(int) $t['id']] = ['label' => (string) $shown['name'], 'url' => $url, 'live' => $url !== null && $live, 'type_label' => $tax === null ? '' : Labels::text($tax->singular(), Labels::domain($tax->owner))];
            }
        }

        return $out;
    }

    /**
     * @param array{entry: array<int, array<string, mixed>>, term: array<int, array<string, mixed>>, language: string} $links
     * @return array{label: string, url: ?string, live: bool, type_label?: string} type_label: what the item is (Page, Category), for the editor
     */
    private function linkFor(array $item, array $links): array
    {
        return match ($item['kind']) {
            'entry', 'term' => $links[$item['kind']][$item['ref']] ?? ['label' => '', 'url' => null, 'live' => false],
            'archive' => ($type = $this->content->get((string) $item['ref'])) !== null && $type->hasArchive()
                ? ['label' => $type->plural(), 'url' => ContentLanguages::localize((string) $type->getPath(), $links['language']), 'live' => true] : ['label' => '', 'url' => null, 'live' => false],
            default => ['label' => '', 'url' => (string) ($item['url'] ?? ''), 'live' => true],
        };
    }

    /** @return array{label: string, url: ?string, live: bool, type_label: string} */
    private function entryLink(Entry $e): array
    {
        $type = $this->content->get((string) $e->type);

        return [
            'label' => (string) $e->title, 'url' => $type === null ? null : $e->url($type),
            'live' => $e->status === 'published' && $type !== null && $type->getPath() !== null && (!$type->isHierarchical() || $e->publicAncestors() !== null),
            'type_label' => $type === null ? '' : Labels::text($type->singular(), Labels::domain($type->owner)),
        ];
    }

    /**
     * The published translations into $language of entries in another
     * language, by translation group, in one query.
     *
     * @param array<int, Entry> $entries
     * @return array<string, Entry>
     */
    private function translationsIn(array $entries, string $language): array
    {
        $groups = [];
        foreach ($entries as $e) {
            if ($language !== '' && $e->language() !== $language && (string) $e->translation_group !== '') {
                $groups[] = (string) $e->translation_group;
            }
        }
        if ($groups === []) {
            return [];
        }
        $q = Entry::query();
        $q->whereIn('translation_group', array_values(array_unique($groups)))->where('locale', $language)->where('status', 'published');
        $out = [];
        foreach ($q->get() as $t) {
            if ($t instanceof Entry) {
                \Kestello\Cms\Cache\PageTags::add('entry:' . (int) $t->getKey());
                $out[(string) $t->translation_group] = $t;
            }
        }

        return $out;
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
