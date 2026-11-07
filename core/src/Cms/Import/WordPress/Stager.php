<?php

declare(strict_types=1);

namespace Kestello\Cms\Import\WordPress;

use Kestello\Cms\Content\ContentRegistry;
use Kestello\Cms\Import\ImportException;
use Kestello\Framework\Database\DatabaseManager;
use Kestello\Framework\Database\QueryBuilder;

/**
 * Reads an export once and writes every record into import_items, each
 * already marked as something to import or as skipped with the reason. The
 * run that follows only works through the table, so it can stop and resume
 * anywhere, and the review screen can say exactly what will happen.
 *
 * @internal
 */
final class Stager
{
    /** WordPress post statuses that are imported, and what they become. */
    public const array STATUSES = ['publish' => 'published', 'future' => 'published', 'draft' => 'draft', 'pending' => 'pending', 'private' => 'private'];

    /** WordPress taxonomies with a different name here. */
    public const array TAXONOMIES = ['category' => 'category', 'post_tag' => 'tag'];

    /** Post types that hold the WordPress theme's own settings, not content. */
    private const array THEME_TYPES = [
        'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_global_styles', 'wp_template',
        'wp_template_part', 'wp_navigation', 'wp_font_family', 'wp_font_face', 'wp_changeset',
    ];

    private const int BATCH = 100;

    private int $importId = 0;
    private OldSite $site;
    /** @var list<array<string, mixed>> */
    private array $rows = [];
    /** @var array<string, array<string, mixed>> declared terms by taxonomy:slug */
    private array $terms = [];
    /** @var array<string, string> terms used by items but not declared, taxonomy:slug → name */
    private array $usedTerms = [];
    /** @var array<int, int> entry id → parent id */
    private array $parents = [];
    /** @var array<string, array{login: string, email: string, name: string, posts: int}> */
    private array $authors = [];
    /** @var array<string, string> nav_menu slug → name */
    private array $menus = [];
    /** @var array<string, list<array<string, mixed>>> menu slug → items */
    private array $menuItems = [];
    /** @var array<string, string> upload key → address, for attachments in the export */
    private array $attachmentKeys = [];
    /** @var array<string, array{url: string, label: string}> upload key → address found in content */
    private array $contentFiles = [];
    /** @var array<string, int> */
    private array $counts = [];
    private int $comments = 0;

    public function __construct(private readonly ContentRegistry $content) {}

    /**
     * @return array<string, mixed> the summary stored on the import
     * @throws ImportException
     */
    public function stage(int $importId, string $file): array
    {
        $this->importId = $importId;
        $siteInfo = null;
        foreach ((new WxrReader($file))->records() as $record) {
            match ($record['kind']) {
                'site' => $siteInfo = $this->site($record),
                'author' => $this->author($record),
                'term' => $this->term($record),
                default => $this->item($record),
            };
        }
        if ($siteInfo === null) {
            throw new ImportException('This file has no WordPress site in it.');
        }
        $this->finishTerms();
        $this->finishMenus();
        $this->finishFiles();
        $this->flush();
        $this->setDepths();

        return [
            'site' => $siteInfo,
            'authors' => array_values($this->authors),
            'counts' => $this->counts,
            'comments' => $this->comments,
        ];
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, string>
     */
    private function site(array $r): array
    {
        if (preg_match('#^https?://[^/\s]+#i', (string) $r['url']) !== 1) {
            throw new ImportException('This file has no WordPress site address (wp:base_site_url). Make the export again in WordPress under Tools → Export.');
        }
        $this->site = new OldSite((string) $r['url']);

        return ['title' => (string) $r['title'], 'url' => (string) $r['url'], 'version' => (string) $r['version'], 'language' => (string) $r['language']];
    }

    /**
     * @param array<string, mixed> $r
     */
    private function author(array $r): void
    {
        $login = (string) $r['login'];
        if ($login !== '') {
            $this->authors[$login] = ['login' => $login, 'email' => (string) $r['email'], 'name' => (string) $r['name'], 'posts' => 0];
        }
    }

    /**
     * @param array<string, mixed> $r
     */
    private function term(array $r): void
    {
        $taxonomy = (string) $r['taxonomy'];
        if ($taxonomy === 'nav_menu') {
            $this->menus[(string) $r['slug']] = (string) $r['name'];
            return;
        }
        $this->terms[$taxonomy . ':' . $r['slug']] = $r;
    }

    /**
     * @param array<string, mixed> $r
     */
    private function item(array $r): void
    {
        $type = (string) $r['type'];
        $id = (int) $r['id'];
        $this->comments += (int) $r['comments'];
        if ($id < 1) {
            return;
        }
        if ($type === 'attachment') {
            $this->attachment($r);
            return;
        }
        if ($type === 'nav_menu_item') {
            $this->menuItem($r);
            return;
        }
        $label = self::label((string) $r['title'], $type . ' ' . $id);
        if ($type === 'wp_block') {
            $this->add('reusable', (string) $id, $id, $label, ['content' => $r['content']], skipped: 'A reusable block: copied into the posts that use it.');
            return;
        }
        if ($type === 'revision' || in_array($type, self::THEME_TYPES, true)) {
            $this->count('skipped:' . $type);
            return;    // not content anyone wrote as a page or post: not even listed
        }

        $kestelloType = $this->content->get($type);
        $reason = match (true) {
            $kestelloType === null => sprintf('This site has no content type "%s".', $type),
            $r['status'] === 'trash' => 'In the WordPress trash.',
            $r['status'] === 'auto-draft' => 'An empty draft WordPress made by itself.',
            !isset(self::STATUSES[$r['status']]) => sprintf('Its status "%s" has no match here.', $r['status']),
            default => null,
        };
        $this->count('entry:' . $type);
        $author = (string) $r['author'];
        if ($reason === null && isset($this->authors[$author])) {
            $this->authors[$author]['posts']++;
        }

        $terms = [];
        foreach ($r['terms'] as $t) {
            $taxonomy = self::TAXONOMIES[$t['taxonomy']] ?? $t['taxonomy'];
            if ($kestelloType !== null && $this->content->getTaxonomy($taxonomy)?->appliesTo($kestelloType->name) === true && $t['slug'] !== '') {
                $terms[] = ['taxonomy' => $t['taxonomy'], 'slug' => $t['slug'], 'name' => $t['name']];
                $this->usedTerms[$t['taxonomy'] . ':' . $t['slug']] ??= $t['name'];
            }
        }
        $linkPath = $this->site->path((string) $r['link']);
        $payload = [
            'type' => $type,
            'title' => (string) $r['title'],
            'slug' => (string) $r['slug'],
            'content' => (string) $r['content'],
            'excerpt' => (string) $r['excerpt'],
            'status' => (string) $r['status'],
            'date' => (string) $r['date'],
            'date_gmt' => (string) $r['date_gmt'],
            'modified_gmt' => (string) $r['modified_gmt'],
            'author' => $author,
            'parent' => (int) $r['parent'],
            'menu_order' => (int) $r['menu_order'],
            'protected' => (string) $r['password'] !== '',
            'terms' => $terms,
            'thumbnail' => (int) ($r['meta']['_thumbnail_id'] ?? 0),
            'link' => (string) $r['link'],
        ];
        $this->add('entry', (string) $id, $id, $label, $payload, linkPath: $linkPath, skipped: $reason);
        if ($reason !== null) {
            return;
        }
        if ((int) $r['parent'] > 0) {
            $this->parents[$id] = (int) $r['parent'];
        }
        if ($linkPath !== null) {
            $this->add('redirect', 'entry:' . $id, $id, $linkPath, ['from' => $linkPath, 'kind' => 'entry', 'key' => (string) $id]);
        }
        $this->collectFiles((string) $r['content'], $label);
    }

    /**
     * @param array<string, mixed> $r
     */
    private function attachment(array $r): void
    {
        $id = (int) $r['id'];
        $url = (string) $r['attachment_url'];
        $key = $this->site->fileKey($url) ?? (isset($r['meta']['_wp_attached_file']) ? OldSite::uploadKey('/wp-content/uploads/' . $r['meta']['_wp_attached_file']) : null);
        $filename = rawurldecode(basename((string) parse_url($url, PHP_URL_PATH)));
        $label = self::label((string) $r['title'], $filename !== '' ? $filename : 'attachment ' . $id);
        $this->count('attachment');
        $payload = [
            'url' => $url,
            'title' => (string) $r['title'],
            'alt' => trim((string) ($r['meta']['_wp_attachment_image_alt'] ?? '')),
            'caption' => trim(strip_tags((string) $r['excerpt'])),
            'filename' => $filename,
        ];
        $reason = preg_match('#^https?://#i', $url) !== 1 ? 'The export does not say where this file is.' : null;
        $this->add('attachment', (string) $id, $id, $label, $payload, linkPath: $this->site->path((string) $r['link']), fileKey: $key, skipped: $reason);
        if ($key !== null) {
            $this->attachmentKeys[$key] = $url;
        }
        $from = $reason === null ? $this->site->path($url) : null;
        if ($from !== null) {
            $this->add('redirect', 'attachment:' . $id, $id, $from, ['from' => $from, 'kind' => 'attachment', 'key' => (string) $id]);
        }
    }

    /**
     * @param array<string, mixed> $r
     */
    private function menuItem(array $r): void
    {
        $menu = '';
        foreach ($r['terms'] as $t) {
            if ($t['taxonomy'] === 'nav_menu') {
                $menu = $t['slug'];
            }
        }
        if ($menu === '') {
            return;
        }
        $m = $r['meta'];
        $this->menuItems[$menu][] = [
            'id' => (int) $r['id'],
            'title' => (string) $r['title'],
            'order' => (int) $r['menu_order'],
            'parent' => (int) ($m['_menu_item_menu_item_parent'] ?? 0),
            'type' => (string) ($m['_menu_item_type'] ?? 'custom'),
            'object' => (string) ($m['_menu_item_object'] ?? ''),
            'object_id' => (int) ($m['_menu_item_object_id'] ?? 0),
            'url' => (string) ($m['_menu_item_url'] ?? ''),
            'new_tab' => ($m['_menu_item_target'] ?? '') === '_blank',
        ];
    }

    /** Images and files from the old site's uploads folder named in content. */
    private function collectFiles(string $content, string $label): void
    {
        if (!str_contains($content, '/wp-content/uploads/')) {
            return;
        }
        preg_match_all('~(?:https?:)?(?://[^\s"\'<>()\[\]]+)?/wp-content/uploads/[^\s"\'<>()\[\]?#]+~i', $content, $m);
        foreach ($m[0] as $url) {
            $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $key = $this->site->fileKey($url);
            if ($key !== null) {
                $this->contentFiles[$key] ??= ['url' => str_starts_with($url, '/') && !str_starts_with($url, '//') ? $this->site->url . $url : $url, 'label' => $label];
            }
        }
    }

    private function finishTerms(): void
    {
        foreach ($this->usedTerms as $key => $name) {
            if (!isset($this->terms[$key])) {
                [$taxonomy, $slug] = explode(':', $key, 2);
                $this->terms[$key] = ['taxonomy' => $taxonomy, 'slug' => $slug, 'name' => $name, 'parent' => '', 'description' => '', 'id' => 0];
            }
        }
        foreach ($this->terms as $key => $t) {
            $taxonomy = (string) $t['taxonomy'];
            $here = self::TAXONOMIES[$taxonomy] ?? $taxonomy;
            $reason = match (true) {
                $taxonomy === 'post_format' => 'Post formats are a WordPress theme feature.',
                $this->content->getTaxonomy($here) === null => sprintf('This site has no taxonomy "%s".', $taxonomy),
                default => null,
            };
            $this->count('term:' . $here);
            $depth = 0;
            $path = [(string) $t['slug']];
            for ($p = (string) $t['parent'], $guard = 0; $p !== '' && $guard < 20 && isset($this->terms[$taxonomy . ':' . $p]); $p = (string) $this->terms[$taxonomy . ':' . $p]['parent'], $guard++) {
                $depth++;
                array_unshift($path, $p);
            }
            $payload = ['taxonomy' => $taxonomy, 'slug' => (string) $t['slug'], 'name' => (string) $t['name'], 'parent' => (string) $t['parent'], 'description' => (string) $t['description']];
            $this->add('term', $key, (int) $t['id'], (string) $t['name'], $payload, depth: $depth, skipped: $reason);
            // WordPress's default archive addresses; a site with custom bases needs its own redirects
            $base = ['category' => '/category/', 'post_tag' => '/tag/'][$taxonomy] ?? null;
            if ($reason === null && $base !== null) {
                $from = $base . implode('/', $path);
                $this->add('redirect', 'term:' . $key, (int) $t['id'], $from, ['from' => $from, 'kind' => 'term', 'key' => $key], linkPath: $from);
            }
        }
    }

    private function finishMenus(): void
    {
        foreach ($this->menuItems + array_fill_keys(array_keys($this->menus), []) as $slug => $items) {
            if ($items === []) {
                continue;     // an empty menu
            }
            usort($items, static fn(array $a, array $b) => [$a['order'], $a['id']] <=> [$b['order'], $b['id']]);
            $this->count('menu');
            $this->add('menu', (string) $slug, 0, $this->menus[$slug] ?? (string) $slug, ['slug' => (string) $slug, 'name' => $this->menus[$slug] ?? (string) $slug, 'items' => $items]);
        }
    }

    private function finishFiles(): void
    {
        foreach ($this->contentFiles as $key => $file) {
            if (isset($this->attachmentKeys[$key])) {
                continue;
            }
            $this->count('file');
            $filename = rawurldecode(basename((string) parse_url($file['url'], PHP_URL_PATH)));
            $this->add('file', $key, 0, $filename, ['url' => $file['url'], 'original' => self::original($file['url']), 'filename' => $filename, 'used_in' => $file['label']], fileKey: $key);
        }
    }

    /** The address of the full-size file for a sized copy's address. */
    public static function original(string $url): string
    {
        return (string) preg_replace('/-\d+x\d+(\.[A-Za-z0-9]+)(?=$|[?#])/', '$1', $url);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function add(string $kind, string $key, int $sourceId, string $label, array $payload, ?string $linkPath = null, ?string $fileKey = null, int $depth = 0, ?string $skipped = null): void
    {
        $this->rows[] = [
            'import_id' => $this->importId,
            'kind' => $kind,
            'source_key' => mb_substr($key, 0, 191),
            'source_id' => $sourceId,
            'depth' => $depth,
            'label' => mb_substr($label, 0, 255),
            'link_path' => $linkPath === null ? null : mb_substr($linkPath, 0, 500),
            'file_key' => $fileKey === null ? null : mb_substr($fileKey, 0, 500),
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
            'state' => $skipped === null ? 'pending' : 'done',
            'result' => $skipped === null ? null : 'skipped',
            'message' => $skipped,
        ];
        if (count($this->rows) >= self::BATCH) {
            $this->flush();
        }
    }

    private function flush(): void
    {
        if ($this->rows === []) {
            return;
        }
        $rows = $this->rows;
        $this->rows = [];
        DatabaseManager::getConnection()->transaction(static fn() => QueryBuilder::table('import_items')->insertMany($rows));
    }

    /** Pages under other pages go after them. */
    private function setDepths(): void
    {
        $depths = [];
        foreach (array_keys($this->parents) as $id) {
            $depth = 0;
            for ($p = $this->parents[$id] ?? 0, $seen = [$id => true]; $p > 0 && !isset($seen[$p]) && $depth < 50; $p = $this->parents[$p] ?? 0) {
                $seen[$p] = true;
                $depth++;
            }
            $depths[$depth][] = (string) $id;
        }
        foreach ($depths as $depth => $ids) {
            foreach (array_chunk($ids, 500) as $chunk) {
                QueryBuilder::table('import_items')->where('import_id', $this->importId)->where('kind', 'entry')->whereIn('source_key', $chunk)->update(['depth' => $depth]);
            }
        }
    }

    private function count(string $key): void
    {
        $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;
    }

    private static function label(string $title, string $fallback): string
    {
        $title = trim(html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $title !== '' ? $title : $fallback;
    }
}
