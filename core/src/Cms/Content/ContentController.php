<?php

declare(strict_types=1);

namespace Kestello\Cms\Content;

use Kestello\Cms\Cms;
use Kestello\Cms\I18n\ContentLanguages;
use Kestello\Cms\View\Page;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Core\Response;
use Kestello\Framework\Database\Orm\Caster;
use Kestello\Support\Html;

/**
 * Public pages for entries. Themes override the
 * "@core/content/Entry" and "@core/content/Archive" pages by shipping
 * pages with the same names. Head tags come from the "seo" prop
 * (Site\Seo); feeds and the sitemap live in Site\Discovery.
 */
final class ContentController
{
    /** The id of the Entry content slot added after a template that has none (the canvas uses the same). */
    public const CONTENT_FALLBACK = 'kbodyfallback';

    /**
     * An entry's page. In another language (/es/…) an address with no entry
     * in that language shows the main language's entry at the same address,
     * marked as not translated (and not for search engines), unless it has a
     * translation, whose own address it then redirects to.
     */
    public static function show(Cms $cms, Request $request, string $typeName, string $path): Response
    {
        $type = $cms->content->get($typeName);
        if ($type === null) {
            return Response::notFound();
        }
        $entries = $cms->container->make(Entries::class);
        $language = ContentLanguages::current();
        $entry = $entries->findPublic($type, $path);
        $missing = false;
        if ($entry === null && $language !== '' && ($original = $entries->findPublic($type, $path, '')) !== null) {
            $translated = $original->translation($language);
            if ($translated !== null) {
                return Response::redirect((string) $translated->url($type) . self::query($request), 301);
            }
            [$entry, $missing] = [$original, true];
        }
        if ($entry === null) {
            return Response::notFound();
        }
        if ($entry->isFrontPage()) {
            return Response::redirect(ContentLanguages::localize('/', $language), 301);      // one address per page: the home page lives at / (or /es)
        }
        $canonical = (string) $entry->path($type);
        $asked = ContentLanguages::localize(rtrim((string) $type->getPath(), '/') . '/' . trim($path, '/'), $missing ? '' : $language);
        if ($canonical !== $asked) {
            // an older address pattern or date: send it to the current one
            return Response::redirect(ContentLanguages::localize($canonical, $missing ? $language : '') . self::query($request), 301);
        }

        return self::render($cms, $request, $type, $entry, $canonical, missing: $missing);
    }

    /** "?page=2", or '' without a query string. */
    private static function query(Request $request): string
    {
        return $request->query !== [] ? '?' . http_build_query($request->query) : '';
    }

    /** $path in the language of the page being answered: "/blog" is "/es/blog" on a Spanish page. */
    private static function here(string $path): string
    {
        return ContentLanguages::localize($path, ContentLanguages::current());
    }

    /**
     * /preview/<token>: the entry as it is saved, whatever its status, for
     * the holder of a preview link (Previews). Never cached or indexed.
     */
    public static function preview(Cms $cms, Request $request, #[\SensitiveParameter] string $token): Response
    {
        $previews = $cms->container->make(Previews::class);
        $viewer = \Kestello\Cms\Auth\Auth::getInstance()?->user();
        $link = $previews->resolve($token, isset($viewer['id']) ? (int) $viewer['id'] : null);
        $entry = $link === null ? null : $previews->entryFor($link);
        $type = $entry === null ? null : $cms->content->get((string) $entry->type);
        if ($link === null || $entry === null || $type === null || $type->getPath() === null) {
            return Page::render('@core/errors/Error', \Kestello\Cms\Frontend\ErrorPage::props(
                404,
                heading: \Kestello\Support\Translator::get('Preview not available'),
                message: \Kestello\Support\Translator::get('This preview link has expired or was revoked, or the page was deleted. Ask whoever sent it for a new link.'),
            ), 404)
                ->withHeader('Cache-Control', 'private, no-store')->withHeader('X-Robots-Tag', 'noindex')->withHeader('Referrer-Policy', 'no-referrer');
        }
        $label = match ((string) $entry->status) {
            'published' => 'published',
            'scheduled' => 'scheduled for ' . Caster::toDate($entry->getRawAttribute('published_at'))->format('j M Y, H:i') . ' UTC',
            'private' => 'private',
            'pending' => 'waiting for review',
            default => 'a draft',
        };
        $banner = '<div role="status" style="position:sticky;top:0;z-index:2147483000;display:flex;gap:12px;justify-content:center;align-items:center;flex-wrap:wrap;'
            . 'padding:8px 16px;background:#1f2328;color:#fff;font:500 14px/1.4 system-ui,sans-serif">'
            . '<span>Preview. This ' . htmlspecialchars(strtolower($type->singular()), ENT_QUOTES) . ' is ' . htmlspecialchars($label, ENT_QUOTES)
            . ($link['kind'] === Previews::EDITOR ? ', shown with your unsaved changes.' : '.') . '</span></div>';
        $cms->filters->add('page.body_open', static fn(string $html): string => $banner . $html, -1000);

        return self::render($cms, $request, $type, $entry, (string) $entry->path($type), preview: true)
            ->withHeader('Cache-Control', 'private, no-store')->withHeader('X-Robots-Tag', 'noindex')->withHeader('Referrer-Policy', 'no-referrer');
    }

    /** The page chosen as the home page (Settings, Reading), or 0 for the theme's own home page. */
    public static function frontPageId(): int
    {
        $id = \Kestello\Framework\Settings\Settings::getInstance()?->get('front_page');

        return is_numeric($id) ? (int) $id : 0;
    }

    /**
     * The chosen home page, rendered at / (and its translation at /es; the
     * original, marked as not translated, when it has none), or null when
     * none is set or it isn't published.
     */
    public static function home(Cms $cms, Request $request): ?Response
    {
        $id = self::frontPageId();
        if ($id < 1) {
            return null;
        }
        $entry = Entry::query()->where('id', $id)->published()->first();
        $type = $entry === null ? null : $cms->content->get((string) $entry->type);
        if ($entry === null || $type === null || $type->getPath() === null) {
            return null;
        }
        $language = ContentLanguages::current();
        $translated = $language === '' ? $entry : $entry->translation($language);

        return self::render($cms, $request, $type, $translated ?? $entry, $translated === null ? '/' : ContentLanguages::localize('/', $language), missing: $translated === null);
    }

    /**
     * The entry editor's canvas: the entry laid out as the site shows it,
     * for someone editing it. The editor then sends the unsaved body
     * through POST /content/{type}/preview-blocks and the canvas swaps it in.
     *
     * @param array<string, mixed> $design what the canvas script needs (field, kind)
     */
    public static function canvas(Cms $cms, Request $request, ContentType $type, Entry $entry, array $design): Response
    {
        return self::render($cms, $request, $type, $entry, (string) ($entry->exists() ? $entry->path($type) : ''), preview: true, design: ['canvas' => true, 'kind' => 'entry'] + $design)
            ->withHeader('Cache-Control', 'private, no-store')->withHeader('X-Robots-Tag', 'noindex');
    }

    /**
     * @param array<string, mixed>|null $design set on the editor's canvas
     * @param bool $missing the entry stands in for a translation the page's language doesn't have yet
     */
    private static function render(Cms $cms, Request $request, ContentType $type, Entry $entry, string $canonical, bool $preview = false, ?array $design = null, bool $missing = false): Response
    {
        $terms = [];
        foreach ($entry->exists() ? (new Terms())->summaries([(int) $entry->getKey()])[(int) $entry->getKey()] ?? [] : [] as $taxName => $list) {
            $tax = $cms->content->getTaxonomy($taxName);
            if ($tax !== null) {
                $terms[] = ['taxonomy' => $tax->name, 'label' => $tax->plural(), 'terms' => array_map(static fn(array $t) => $t + ['path' => $tax->getPath() === null ? null : self::here($tax->getPath() . '/' . $t['slug'])], $list)];
            }
        }

        // Tagged with its parents too: moving, renaming or unpublishing one changes this page's address or visibility.
        if (!$preview) {
            \Kestello\Cms\Cache\PageTags::add('entry:' . $entry->getKey(), 'terms', ...array_map(static fn(int $id) => 'entry:' . $id, $entry->publicAncestors() ?? []));
            \Kestello\Cms\I18n\PageLanguages::forEntry($entry, $type);
        }
        $ctx = new \Kestello\Cms\Blocks\BlockContext($entry, $type, $request, \Kestello\Cms\Auth\Auth::getInstance()?->user());
        $presented = self::present($entry, $type, full: true, blocks: $ctx);
        $preload = [];
        foreach ($presented['blocks'] as $name) {
            $preload = [...$preload, ...\Kestello\Cms\Blocks\BlockRegistry::components($presented['data']->{$name} ?? [])];
        }

        $seo = \Kestello\Cms\Site\Seo::entry($entry, $type, $presented, $canonical)
            + ($type->hasArchive() ? ['feed' => ['title' => $type->plural(), 'url' => $type->getPath() . '/feed']] : []);
        if ($preview || $missing) {
            $seo['noindex'] = true;      // a stand-in in the wrong language is not this language's page
        }
        $language = $missing ? ['translation_missing' => true] : [];

        // a theme template (Appearance → Templates) takes over from the Vue page when there is one
        $templates = $cms->container->has(\Kestello\Cms\Design\ThemeTemplates::class) ? $cms->container->make(\Kestello\Cms\Design\ThemeTemplates::class) : null;
        $drafts = self::wantsDrafts($cms, $request);
        $template = null;
        try {
            $template = $templates?->templateFor($entry, $type, $drafts);
        } catch (\Throwable $e) {
            error_log('[kestello] template lookup failed: ' . $e->getMessage());     // e.g. not migrated yet
        }
        if ($template !== null) {
            $tree = $cms->blocks->prepare($template['data']['blocks'] ?? [], $ctx->forTemplate($drafts));
            // a template without a Entry content block would drop the entry's own blocks: they go after it
            // (the editor's canvas does the same, and tells the editor why; see app/canvas/tree.js)
            if (!\Kestello\Cms\Blocks\BlockRegistry::contains($tree, 'core/entry-content')) {
                $tree = [...$tree, ...$cms->blocks->prepare([['type' => 'core/entry-content', 'id' => self::CONTENT_FALLBACK, 'props' => [], 'children' => []]], $ctx->forTemplate($drafts))];
            }
            $response = Page::render($template['data']['layout'] === 'bare' ? '@core/content/TemplateBare' : '@core/content/Template', [
                'title' => $entry->title,
                'entry' => ['id' => $presented['id'], 'title' => $presented['title'], 'path' => $presented['path'], 'published_at' => $presented['published_at']],
                'template' => ['key' => $template['key'], 'blocks' => $tree],
                'seo' => $seo,
            ] + $language + ($design === null ? [] : ['design' => $design]), preload: \Kestello\Cms\Blocks\BlockRegistry::components($tree));

            return $drafts ? $response->withHeader('Cache-Control', 'private, no-store')->withHeader('X-Robots-Tag', 'noindex') : $response;
        }

        return Page::render('@core/content/Entry', [
            'title' => $entry->title,
            'terms' => $terms,
            'entry' => $presented,
            'type' => ['name' => $type->name, 'singular' => $type->singular(), 'plural' => $type->plural(), 'archive' => $type->hasArchive() ? self::here((string) $type->getPath()) : null],
            'seo' => $seo,
        ] + $language + ($design === null ? [] : ['design' => $design]), preload: array_values(array_unique($preload)));
    }

    /** ?design=draft from someone who may design: unpublished templates, parts and patterns. */
    private static function wantsDrafts(Cms $cms, Request $request): bool
    {
        if (($request->query['design'] ?? null) !== 'draft') {
            return false;
        }
        try {
            return $cms->auth->authenticate($request)->can('theme.design');
        } catch (\Throwable) {
            return false;
        }
    }

    public static function archive(Cms $cms, Request $request, string $typeName): Response
    {
        $type = $cms->content->get($typeName);
        if ($type === null) {
            return Response::notFound();
        }
        $page = max(1, min(10000, (int) ($request->query['page'] ?? 1)));
        $result = Entry::query()->ofType($type->name)->inLanguage()->published()->with('author')
            ->orderByDesc('published_at')->orderByDesc('id')->paginate(10, $page);
        if ($page > $result['last_page']) {
            return Response::notFound();
        }

        return Page::render('@core/content/Archive', [
            'title' => $type->plural(),
            'type' => ['name' => $type->name, 'singular' => $type->singular(), 'plural' => $type->plural(), 'description' => $type->getDescription(), 'path' => self::here((string) $type->getPath())],
            'entries' => self::presentList($result['data']->all(), [$type->name => $type]),
            'pagination' => ['page' => $result['current_page'], 'last' => $result['last_page'], 'total' => $result['total']],
            'seo' => self::listSeo((string) $type->getPath(), $page, $type->getDescription(), ['title' => $type->plural(), 'url' => $type->getPath() . '/feed']),
        ]);
    }

    /**
     * A term's page: its published entries in the page's language, newest
     * first (all types the taxonomy applies to). The term is the one written
     * in that language, else the main language's; entries tagged with any
     * translation of it count. An address with the main language's slug
     * redirects to the translation's.
     */
    public static function term(Cms $cms, Request $request, string $taxName, string $slug): Response
    {
        $tax = $cms->content->getTaxonomy($taxName);
        $language = ContentLanguages::current();
        $term = $tax === null ? null : Term::findPublic($tax->name, $slug, $language);
        if ($term === null) {
            return Response::notFound();
        }
        if ($term->language() !== $language && ($translation = $term->translation($language)) !== null && $translation->slug !== $term->slug) {
            return Response::redirect(self::here((string) $translation->path($tax)), 301);
        }
        $types = array_values(array_filter(array_map($cms->content->get(...), $tax->getTypes()), static fn($t) => $t !== null && $t->getPath() !== null));
        $byName = array_combine(array_map(static fn(ContentType $t) => $t->name, $types), $types);
        $page = max(1, min(10000, (int) ($request->query['page'] ?? 1)));
        $result = Entry::query()->where('entries.locale', ContentLanguages::current())->whereIn('type', array_keys($byName) ?: ['-'])->published()->with('author')
            ->whereExists(static fn(\Kestello\Framework\Database\QueryBuilder $q) => $q->from('entry_term')->whereColumn('entry_term.entry_id', '=', 'entries.id')->whereIn('entry_term.term_id', $term->groupIds()))
            ->orderByDesc('published_at')->orderByDesc('id')->paginate(10, $page);
        if (($page > $result['last_page'] && $page > 1) || $result['total'] === 0) {
            return Response::notFound();    // a term only drafts use has no public page yet
        }
        \Kestello\Cms\I18n\PageLanguages::forTerm($term, $tax);

        return Page::render('@core/content/Archive', [
            'title' => $term->name,
            'type' => ['name' => $tax->name, 'singular' => $tax->singular(), 'plural' => $term->name, 'description' => (string) ($term->description ?? ''), 'path' => self::here((string) $term->path($tax))],
            'term' => ['id' => $term->getKey(), 'name' => $term->name, 'taxonomy' => ['name' => $tax->name, 'singular' => $tax->singular()]],
            'entries' => self::presentList($result['data']->all(), $byName),
            'pagination' => ['page' => $result['current_page'], 'last' => max(1, $result['last_page']), 'total' => $result['total']],
            'seo' => self::listSeo($term->path($tax), $page, (string) ($term->description ?? ''), ['title' => $term->name, 'url' => $term->path($tax) . '/feed']),
        ]);
    }

    /** /blog/2026 and /blog/2026/11: a dated type's entries from that year or month (site timezone). */
    public static function dated(Cms $cms, Request $request, string $typeName, string $year, ?string $month = null): Response
    {
        $type = $cms->content->get($typeName);
        $y = (int) $year;
        $m = $month === null ? null : (int) $month;
        if ($type === null || !Permalinks::supports($type) || $y < 1970 || $y > 2200 || ($m !== null && ($m < 1 || $m > 12))) {
            return Response::notFound();
        }
        // An entry whose address is exactly this one ("/blog/2026/12" for the post "12" under {year}/{slug}) wins over the archive.
        $entry = self::show($cms, $request, $typeName, $year . ($month !== null ? '/' . $month : ''));
        if ($entry->statusCode() === 200) {
            return $entry;
        }
        $start = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $y, $m ?? 1), Permalinks::timezone());
        $end = $start->modify($m === null ? '+1 year' : '+1 month');
        $utc = new \DateTimeZone('UTC');
        $page = max(1, min(10000, (int) ($request->query['page'] ?? 1)));
        $result = Entry::query()->ofType($type->name)->inLanguage()->published()->with('author')
            ->where('published_at', '>=', $start->setTimezone($utc)->format('Y-m-d H:i:s'))
            ->where('published_at', '<', $end->setTimezone($utc)->format('Y-m-d H:i:s'))
            ->orderByDesc('published_at')->orderByDesc('id')->paginate(10, $page);
        if ($result['total'] === 0 || $page > $result['last_page']) {
            return Response::notFound();       // no endless empty months for crawlers
        }
        $path = $type->getPath() . '/' . $year . ($month !== null ? '/' . $month : '');
        $title = $m === null ? (string) $y : $start->format('F Y');

        return Page::render('@core/content/Archive', [
            'title' => "{$type->plural()}: {$title}",
            'type' => ['name' => $type->name, 'singular' => $type->singular(), 'plural' => $title, 'description' => '', 'path' => self::here($path)],
            'date' => ['year' => $y, 'month' => $m, 'archive' => $type->getPath(), 'label' => $type->plural()],
            'entries' => self::presentList($result['data']->all(), [$type->name => $type]),
            'pagination' => ['page' => $result['current_page'], 'last' => $result['last_page'], 'total' => $result['total']],
            'seo' => self::listSeo($path, $page, '', ['title' => $type->plural(), 'url' => $type->getPath() . '/feed']),
        ]);
    }

    /** /author/ana: everything a person published in types with an archive. Unknown or silent authors are a 404. */
    public static function author(Cms $cms, Request $request, string $username): Response
    {
        $types = [];
        foreach ($cms->content->types() as $t) {
            if ($t->hasArchive()) {
                $types[$t->name] = $t;
            }
        }
        $user = $types === [] ? null : \Kestello\Framework\Database\QueryBuilder::table('users')->where('username', $username)->first();
        if ($user === null) {
            return Response::notFound();
        }
        $page = max(1, min(10000, (int) ($request->query['page'] ?? 1)));
        $result = Entry::query()->where('entries.locale', ContentLanguages::current())->whereIn('type', array_keys($types))->published()->where('author_id', (int) $user['id'])->with('author')
            ->orderByDesc('published_at')->orderByDesc('id')->paginate(10, $page);
        if ($result['total'] === 0 || $page > $result['last_page']) {
            return Response::notFound();       // a person who published nothing has no public page
        }
        $name = (string) $user['username'];
        $path = '/author/' . rawurlencode($name);

        return Page::render('@core/content/Archive', [
            'title' => $name,
            'type' => ['name' => 'author', 'singular' => 'Author', 'plural' => $name, 'description' => '', 'path' => self::here($path)],
            'author' => ['name' => $name],
            'entries' => self::presentList($result['data']->all(), $types),
            'pagination' => ['page' => $result['current_page'], 'last' => $result['last_page'], 'total' => $result['total']],
            'seo' => self::listSeo($path, $page, '', ['title' => $name, 'url' => '/feed']),
        ]);
    }

    /**
     * Entries as lists show them: title, path, excerpt, date, the featured
     * image (the type's first image field, loaded in one query) and the
     * sortable fields. Themes use it for lists of their own.
     *
     * @param list<Entry> $entries
     * @param array<string, ContentType> $types by name
     * @return list<array<string, mixed>>
     * @api
     */
    public static function presentList(array $entries, array $types): array
    {
        \Kestello\Cms\Cache\PageTags::add(...array_map(static fn(string $t) => 'list:' . $t, array_keys($types)));
        $featured = [];
        foreach ($entries as $e) {
            $field = self::featuredField($types[$e->type]);
            $value = $field === null ? null : $e->field($field->name);
            $id = is_array($value) ? ($value[0] ?? null) : $value;
            if (is_numeric($id)) {
                $featured[(int) $e->getKey()] = (int) $id;
            }
        }
        $found = $featured === [] ? [] : \Kestello\Cms\Media\MediaPresenter::many(array_values(array_unique($featured)));

        return array_map(static fn(Entry $e) => self::present($e, $types[$e->type], full: false)
            + ['image' => self::onlyImage(isset($featured[(int) $e->getKey()]) ? \Kestello\Cms\Media\MediaPresenter::value($featured[(int) $e->getKey()], false, $found) : null)]
            + ['fields' => (object) self::listFields($e, $types[$e->type])], $entries);
    }

    /**
     * What a list shows of an entry besides its title: its public sortable
     * fields (Field::indexed()), which are short by design, such as a
     * project's year or place. Themes lay out catalogue rows with them.
     *
     * @return array<string, mixed>
     */
    private static function listFields(Entry $entry, ContentType $type): array
    {
        $out = [];
        foreach ($type->getFields() as $field) {
            if ($field->isIndexed() && !$field->isInternal() && ($value = $entry->field($field->name)) !== null && $value !== '') {
                $out[$field->name] = $value;
            }
        }

        return $out;
    }

    private static function onlyImage(mixed $media): ?array
    {
        return is_array($media) && ($media['kind'] ?? '') === 'image' ? $media : null;
    }

    /** The field whose image stands for an entry in lists, feeds and link previews: the first image field. */
    public static function featuredField(ContentType $type): ?Field
    {
        foreach ($type->getFields() as $field) {
            if ($field->type === 'media' && !$field->isInternal() && (($kinds = (array) $field->getOption('kinds', [])) === [] || in_array('image', $kinds, true))) {
                return $field;
            }
        }

        return null;
    }

    /** @return array<string, mixed> canonical keeps ?page=N past the first page */
    private static function listSeo(string $path, int $page, string $description, array $feed): array
    {
        return ['canonical' => self::here($path) . ($page > 1 ? '?page=' . $page : ''), 'description' => \Kestello\Cms\Site\Seo::summary($description), 'feed' => $feed, 'kind' => 'collection'];
    }

    /** Public fields only: no internal fields, no author email. */
    public static function present(Entry $entry, ContentType $type, bool $full, ?\Kestello\Cms\Blocks\BlockContext $blocks = null): array
    {
        $data = [];
        $html = [];
        $trees = [];
        if ($full) {
            foreach ($type->getFields() as $field) {
                if (!$field->isInternal()) {
                    $data[$field->name] = $entry->field($field->name);
                    if ($field->type === 'rich_text') {
                        // sanitized on save, and again here: a value written another way (an older type, a restored revision, a plugin) is never trusted as HTML
                        $data[$field->name] = is_string($data[$field->name]) ? Html::sanitize($data[$field->name], 'rich-text') : $data[$field->name];
                        $html[] = $field->name;
                    }
                    if ($field->type === 'blocks') {
                        // validated again and prepared for this viewer (visibility, bindings, queries)
                        $data[$field->name] = \Kestello\Cms\Cms::instance()?->blocks->prepare(
                            $data[$field->name] ?? [],
                            $blocks ?? new \Kestello\Cms\Blocks\BlockContext($entry, $type),
                            is_array($field->getOption('allowed')) ? $field->getOption('allowed') : null,
                        ) ?? [];
                        $trees[] = $field->name;
                    }
                }
            }
        }
        $data = self::resolveMedia($type, self::inPageLanguage($type, $data));
        $author = $entry->author;

        return [
            'id' => $entry->getKey(),
            'title' => $entry->title,
            'path' => $entry->url($type),
            'excerpt' => $type->hasExcerpt() ? $entry->excerpt : null,
            'published_at' => Caster::present($entry->published_at),
            'author' => $author === null ? null : ['name' => (string) $author->username, 'path' => $type->hasArchive() ? '/author/' . rawurlencode((string) $author->username) : null],
            'data' => (object) $data,
            'html' => $html,
            'blocks' => $trees,
        ];
    }

    /**
     * Entry and term fields point at their translations in the page's
     * language, where they have one: a Spanish page links to Spanish
     * projects, whichever language the link was picked in.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function inPageLanguage(ContentType $type, array $data): array
    {
        if (\Kestello\Cms\I18n\ContentLanguages::others() === []) {
            return $data;
        }
        $language = \Kestello\Cms\I18n\ContentLanguages::current();
        foreach (['entry' => 'entries', 'term' => 'terms'] as $fieldType => $table) {
            $fields = array_filter($type->getFields(), static fn($f) => $f->type === $fieldType && isset($data[$f->name]) && $data[$f->name] !== []);
            $ids = [];
            foreach ($fields as $f) {
                $ids = array_merge($ids, array_map('intval', (array) $data[$f->name]));
            }
            if ($ids === []) {
                continue;
            }
            $map = \Kestello\Cms\Content\Translations::idsIn($table, array_values(array_unique($ids)), $language);
            foreach ($fields as $f) {
                $data[$f->name] = is_array($data[$f->name])
                    ? array_values(array_unique(array_map(static fn($id) => $map[(int) $id] ?? (int) $id, $data[$f->name])))
                    : ($map[(int) $data[$f->name]] ?? (int) $data[$f->name]);
            }
        }

        return $data;
    }

    /**
     * Media fields become {id, url, alt, title, caption, width, height, kind,
     * mime, color, placeholder, alpha, focus, sizes} (deleted files drop out),
     * ready for <KImage>; themes never look ids up themselves.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function resolveMedia(ContentType $type, array $data): array
    {
        $fields = array_filter($type->getFields(), static fn($f) => $f->type === 'media' && isset($data[$f->name]));
        $ids = [];
        foreach ($fields as $f) {
            $ids = array_merge($ids, array_map('intval', (array) $data[$f->name]));
        }
        if ($ids === []) {
            return $data;
        }
        $found = \Kestello\Cms\Media\MediaPresenter::many($ids);
        foreach ($fields as $f) {
            $data[$f->name] = \Kestello\Cms\Media\MediaPresenter::value($data[$f->name], (bool) $f->getOption('multiple', false), $found);
        }

        return $data;
    }
}
