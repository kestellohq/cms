<?php

declare(strict_types=1);

namespace Kestello\Cms\Seed;

/**
 * A site's starting content as files, read and checked for shape. Nothing
 * here touches the site: SiteSeeder checks the result against the site and
 * writes it.
 *
 *   seed/
 *     site.json          settings, the front page, menus, alt text for media
 *     pages/<key>.json   one page each: title, slug, blocks, and optionally
 *                        status, template, seo
 *     media/<file>       images and files, used in blocks as "@media:<file>"
 *
 * site.json, every part optional:
 *   { "settings":   { "general": { "site_name": "Acme" } },
 *     "front_page": "home",
 *     "menus":      [ { "name": "Main", "location": "primary", "items": [
 *                       { "page": "about" },
 *                       { "url": "https://docs.acme.test/", "label": "Docs" },
 *                       { "archive": "post", "label": "Blog" },
 *                       { "url": "/shop", "label": "Shop", "children": [ { "page": "chairs" } ] } ] } ],
 *     "media":      { "team.jpg": { "alt": "The team outside the shop" } } }
 *
 *   $plan = SeedFolder::read('/path/to/seed');   // throws SeedException naming the file
 */
final class SeedFolder
{
    private const string KEY = '/^[a-z0-9][a-z0-9-]{0,60}$/';
    private const array PAGE_FIELDS = ['title', 'slug', 'status', 'template', 'seo', 'blocks'];
    private const array STATUSES = ['published', 'draft'];

    /**
     * @return array{
     *     settings: array<string, array<string, scalar>>,
     *     front_page: ?string,
     *     menus: list<array{name: string, location: string, items: list<array<string, mixed>>}>,
     *     media: array<string, array{path: string, alt: string}>,
     *     pages: array<string, array{title: string, slug: string, status: string, template: ?string, seo: array<string, mixed>, blocks: list<mixed>}>
     * }
     * @throws SeedException
     */
    public static function read(string $folder): array
    {
        $root = realpath($folder);
        if ($root === false || !is_dir($root)) {
            throw new SeedException("No seed folder at {$folder}.");
        }

        $site = is_file($root . '/site.json') ? self::json($root . '/site.json', 'site.json') : [];
        self::only($site, ['settings', 'front_page', 'menus', 'media'], 'site.json');

        $pages = self::pages($root);
        $media = self::media($root, $site['media'] ?? [], 'site.json');
        self::mediaUsed($pages, $media);

        $front = $site['front_page'] ?? null;
        if ($front !== null && (!is_string($front) || !isset($pages[$front]))) {
            throw new SeedException('site.json: "front_page" must be the key of a page in pages/ (its file name without .json).');
        }

        return [
            'settings' => self::settings($site['settings'] ?? [], 'site.json'),
            'front_page' => $front,
            'menus' => self::menus($site['menus'] ?? [], $pages, 'site.json'),
            'media' => $media,
            'pages' => $pages,
        ];
    }

    /**
     * @return array<string, array{title: string, slug: string, status: string, template: ?string, seo: array<string, mixed>, blocks: list<mixed>}>
     */
    private static function pages(string $root): array
    {
        $pages = [];
        $slugs = [];
        $files = is_dir($root . '/pages') ? (glob($root . '/pages/*.json') ?: []) : [];
        sort($files);
        foreach ($files as $file) {
            $key = basename($file, '.json');
            $where = "pages/{$key}.json";
            if (preg_match(self::KEY, $key) !== 1) {
                throw new SeedException("{$where}: name the file with lowercase letters, digits and dashes, such as about-us.json.");
            }
            $page = self::json($file, $where);
            self::only($page, self::PAGE_FIELDS, $where);

            $title = $page['title'] ?? null;
            if (!is_string($title) || trim($title) === '') {
                throw new SeedException("{$where}: \"title\" is required.");
            }
            $slug = $page['slug'] ?? $key;
            if (!is_string($slug) || preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug) !== 1) {
                throw new SeedException("{$where}: \"slug\" must be lowercase letters, digits and dashes, such as about-us.");
            }
            if (isset($slugs[$slug])) {
                throw new SeedException("{$where}: the slug \"{$slug}\" is also used by pages/{$slugs[$slug]}.json.");
            }
            $slugs[$slug] = $key;
            $status = $page['status'] ?? 'published';
            if (!in_array($status, self::STATUSES, true)) {
                throw new SeedException("{$where}: \"status\" must be published or draft.");
            }
            $template = $page['template'] ?? null;
            if ($template !== null && (!is_string($template) || preg_match(self::KEY, $template) !== 1)) {
                throw new SeedException("{$where}: \"template\" must be a template's name, such as landing.");
            }
            $seo = $page['seo'] ?? [];
            if (!is_array($seo) || ($seo !== [] && array_is_list($seo))) {
                throw new SeedException("{$where}: \"seo\" must be an object, such as {\"description\": \"…\"}.");
            }
            $blocks = $page['blocks'] ?? [];
            if (!is_array($blocks) || !array_is_list($blocks)) {
                throw new SeedException("{$where}: \"blocks\" must be a list of blocks.");
            }

            $pages[$key] = [
                'title' => $title, 'slug' => $slug, 'status' => $status, 'template' => $template,
                'seo' => $seo, 'blocks' => $blocks,
            ];
        }

        return $pages;
    }

    /**
     * @return array<string, array<string, scalar>>
     */
    private static function settings(mixed $groups, string $where): array
    {
        if (!is_array($groups) || ($groups !== [] && array_is_list($groups))) {
            throw new SeedException("{$where}: \"settings\" must be an object of groups, such as {\"general\": {\"site_name\": \"Acme\"}}.");
        }
        $out = [];
        foreach ($groups as $group => $values) {
            if (!is_array($values) || ($values !== [] && array_is_list($values))) {
                throw new SeedException("{$where}: settings \"{$group}\" must be an object of names and values.");
            }
            foreach ($values as $name => $value) {
                if (!is_scalar($value)) {
                    throw new SeedException("{$where}: the setting {$group}.{$name} must be text, a number or true/false.");
                }
                $out[(string) $group][(string) $name] = $value;
            }
        }
        if (isset($out['reading']['front_page'])) {
            throw new SeedException("{$where}: set the front page with \"front_page\" (a page's key), not settings.reading.front_page.");
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $pages
     * @return list<array{name: string, location: string, items: list<array<string, mixed>>}>
     */
    private static function menus(mixed $menus, array $pages, string $where): array
    {
        if (!is_array($menus) || !array_is_list($menus)) {
            throw new SeedException("{$where}: \"menus\" must be a list.");
        }
        $out = [];
        foreach ($menus as $i => $menu) {
            $at = "{$where}: menus[{$i}]";
            if (!is_array($menu) || !is_string($menu['name'] ?? null) || trim($menu['name']) === '') {
                throw new SeedException("{$at} needs a \"name\".");
            }
            self::only($menu, ['name', 'location', 'items'], $at);
            $location = $menu['location'] ?? '';
            if (!is_string($location)) {
                throw new SeedException("{$at}: \"location\" must be a menu location of the theme, such as primary.");
            }
            $out[] = ['name' => $menu['name'], 'location' => $location, 'items' => self::menuItems($menu['items'] ?? [], $pages, "{$at}.items")];
        }

        return $out;
    }

    /**
     * A menu's items, and the items under each item ("children"), as deep as
     * Menus allows (it names a menu that nests deeper when it is saved).
     *
     * @param array<string, mixed> $pages
     * @return list<array<string, mixed>>
     */
    private static function menuItems(mixed $items, array $pages, string $at): array
    {
        if (!is_array($items) || !array_is_list($items)) {
            throw new SeedException("{$at} must be a list.");
        }
        $out = [];
        foreach ($items as $j => $item) {
            $out[] = self::menuItem($item, $pages, "{$at}[{$j}]");
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $pages
     * @return array<string, mixed>
     */
    private static function menuItem(mixed $item, array $pages, string $at): array
    {
        if (!is_array($item)) {
            throw new SeedException("{$at} must be an object.");
        }
        $label = $item['label'] ?? '';
        if (!is_string($label)) {
            throw new SeedException("{$at}: \"label\" must be text.");
        }
        $children = array_key_exists('children', $item) ? ['children' => self::menuItems($item['children'], $pages, "{$at}.children")] : [];
        if (isset($item['page'])) {
            self::only($item, ['page', 'label', 'children'], $at);
            if (!is_string($item['page']) || !isset($pages[$item['page']])) {
                throw new SeedException("{$at}: \"page\" must be the key of a page in pages/.");
            }
            return ['page' => $item['page'], 'label' => $label] + $children;
        }
        if (isset($item['url'])) {
            self::only($item, ['url', 'label', 'children'], $at);
            if (!is_string($item['url']) || $item['url'] === '' || $label === '') {
                throw new SeedException("{$at}: a link needs a \"url\" and a \"label\".");
            }
            return ['url' => $item['url'], 'label' => $label] + $children;
        }
        if (isset($item['archive'])) {
            self::only($item, ['archive', 'label', 'children'], $at);
            if (!is_string($item['archive'])) {
                throw new SeedException("{$at}: \"archive\" must be a content type's name, such as post.");
            }
            return ['archive' => $item['archive'], 'label' => $label] + $children;
        }

        throw new SeedException("{$at}: say what it links to with \"page\", \"url\" or \"archive\".");
    }

    /**
     * Every file in media/, with its alt text from site.json.
     *
     * @return array<string, array{path: string, alt: string}>
     */
    private static function media(string $root, mixed $details, string $where): array
    {
        if (!is_array($details) || ($details !== [] && array_is_list($details))) {
            throw new SeedException("{$where}: \"media\" must be an object of file names, such as {\"team.jpg\": {\"alt\": \"…\"}}.");
        }
        $out = [];
        $files = is_dir($root . '/media') ? (glob($root . '/media/*') ?: []) : [];
        sort($files);
        foreach ($files as $path) {
            if (is_file($path)) {
                $entry = $details[basename($path)] ?? [];
                $alt = is_array($entry) ? ($entry['alt'] ?? '') : '';
                $out[basename($path)] = ['path' => $path, 'alt' => is_string($alt) ? $alt : ''];
            }
        }
        foreach (array_keys($details) as $name) {
            if (!isset($out[$name])) {
                throw new SeedException("{$where}: media \"{$name}\" has alt text but there is no media/{$name}.");
            }
        }

        return $out;
    }

    /**
     * Every "@media:<file>" in the pages (blocks and seo) names a file in media/.
     *
     * @param array<string, array{blocks: list<mixed>, seo: array<string, mixed>}> $pages
     * @param array<string, mixed> $media
     */
    private static function mediaUsed(array $pages, array $media): void
    {
        foreach ($pages as $key => $page) {
            foreach (self::references([$page['blocks'], $page['seo']]) as $name) {
                if (!isset($media[$name])) {
                    throw new SeedException("pages/{$key}.json: \"@media:{$name}\" names a file that is not in media/.");
                }
            }
        }
    }

    /**
     * The file names a block tree refers to with "@media:<file>".
     *
     * @return list<string>
     */
    public static function references(mixed $tree): array
    {
        $found = [];
        array_walk_recursive($tree, static function (mixed $value) use (&$found): void {
            if (is_string($value) && str_starts_with($value, '@media:')) {
                $found[] = substr($value, 7);
            }
        });

        return array_values(array_unique($found));
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(string $file, string $where): array
    {
        try {
            $data = json_decode((string) file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new SeedException("{$where}: not valid JSON ({$e->getMessage()}).");
        }
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new SeedException("{$where}: must be a JSON object.");
        }

        return $data;
    }

    /**
     * @param array<array-key, mixed> $data
     * @param list<string> $known
     */
    private static function only(array $data, array $known, string $where): void
    {
        $unknown = array_diff(array_map('strval', array_keys($data)), $known);
        if ($unknown !== []) {
            throw new SeedException("{$where}: unknown \"" . implode('", "', $unknown) . '"; use ' . implode(', ', $known) . '.');
        }
    }
}
