<?php

declare(strict_types=1);

namespace Kestello\Cms\Site;

use Kestello\Cms\Cms;
use Kestello\Cms\Content\ContentType;
use Kestello\Cms\Content\Entry;
use Kestello\Cms\Content\Field;
use Kestello\Framework\Settings\Settings;
use Kestello\Support\Html;
use Kestello\Support\Translator;

/**
 * Head basics for public pages: canonical link, meta description,
 * robots, Open Graph and Twitter cards, feed autodiscovery.
 *
 * Pages describe themselves with a "seo" prop:
 *   ['description' => '', 'canonical' => '/path', 'image' => {url,width,height,alt},
 *    'type' => 'website'|'article', 'published' => ISO date, 'noindex' => bool,
 *    'feed' => ['title' => '', 'url' => '/blog/feed']]
 * Everything is optional; the site description and the request path fill in.
 * A full SEO plugin replaces the output with the "seo.head" filter.
 */
final class Seo
{
    /** The per-entry fields every public type gets (sidebar group "seo"), unless it defines its own "seo" field. */
    public static function field(): Field
    {
        return Field::group('seo', [
            Field::text('title')->label('Search title')->max(150)
                ->help('Shown as the title in search results and link previews, instead of the title on the page. Left empty, the title is used.'),
            Field::textarea('description')->label('Search description')->max(300)
                ->help('Shown under the title in search results and link previews. Left empty, the excerpt or the first lines are used.'),
            Field::media('image', ['image'])->label('Sharing image')
                ->help('Shown when the page is shared on social media or in a chat. Left empty, the first image of the page is used.'),
            Field::url('canonical')->label('Canonical address')->max(2000)
                ->help('Only for text first published at another address, such as an article you also wrote elsewhere. Search engines then list that address instead of this page. Leave empty otherwise.'),
            Field::boolean('noindex')->label('Hide from search engines')
                ->help('The page stays public but is left out of the sitemap, and search engines are asked not to list it.'),
        ])->label('Search and sharing')->sidebar();
    }

    /**
     * The "seo" prop for an entry's page.
     *
     * @param array<string, mixed> $presented ContentController::present() output (full)
     * @return array<string, mixed>
     */
    public static function entry(Entry $entry, ContentType $type, array $presented, string $canonical): array
    {
        $own = $entry->field('seo');
        $own = is_array($own) ? $own : [];
        $data = (array) $presented['data'];

        $title = trim((string) ($own['title'] ?? ''));
        $description = trim((string) ($own['description'] ?? ''));
        if ($description === '') {
            $description = trim((string) ($presented['excerpt'] ?? ''));
        }
        $image = self::media($own['image'] ?? null);
        foreach ($type->getFields() as $field) {
            if ($field->isInternal()) {
                continue;
            }
            if ($description === '' && $field->type === 'rich_text' && is_string($data[$field->name] ?? null)) {
                $description = self::summary($data[$field->name]);
            }
            if ($description === '' && $field->type === 'blocks' && ($cms = \Kestello\Cms\Cms::instance()) !== null) {
                // pages built from blocks: the text every visitor sees, never members-only or scheduled blocks
                $description = self::sentences($cms->search->visibleBlockTexts($entry->field($field->name)));
            }
            if ($image === null && $field->type === 'media') {
                $value = $data[$field->name] ?? null;
                $first = is_array($value) && array_is_list($value) ? ($value[0] ?? null) : $value;
                if (is_array($first) && ($first['kind'] ?? 'image') === 'image' && isset($first['url'])) {
                    $image = ['url' => (string) $first['url'], 'width' => $first['width'] ?? null, 'height' => $first['height'] ?? null, 'alt' => (string) ($first['alt'] ?? '')];
                }
            }
        }

        // the address search engines should list: another one only when the editor chose it, and only a web address
        $elsewhere = trim((string) ($own['canonical'] ?? ''));

        return [
            'title' => $title === '' ? null : $title,
            'description' => self::clip($description),
            'canonical' => self::isWebAddress($elsewhere) ? $elsewhere : $canonical,
            'image' => $image,
            'type' => $type->hasArchive() ? 'article' : 'website',
            'kind' => $type->hasArchive() ? 'article' : 'page',
            'published' => $type->hasArchive() ? ($presented['published_at'] ?? null) : null,
            'modified' => \Kestello\Support\Date::isoUtc($entry->getAttribute('updated_at')),
            'author' => $type->hasArchive() && is_array($presented['author'] ?? null) ? (string) $presented['author']['name'] : null,
            'breadcrumbs' => $canonical === '/' ? [] : self::breadcrumbs($entry, $type),
            'noindex' => !empty($own['noindex']),
        ];
    }

    /**
     * What leads to an entry, without the home page and the entry itself:
     * its type's archive, or its parent pages (the furthest first).
     *
     * @return list<array{name: string, path: string}>
     */
    private static function breadcrumbs(Entry $entry, ContentType $type): array
    {
        if ($type->hasArchive() && $type->getPath() !== null && $type->getPath() !== '') {
            return [['name' => $type->plural(), 'path' => (string) $type->getPath()]];
        }
        // a new entry on the editor's canvas has no parents to look up yet
        $ids = $type->isHierarchical() && $entry->exists() ? ($entry->publicAncestors() ?? []) : [];
        if ($ids === []) {
            return [];
        }
        $parents = [];
        foreach (Entry::query()->findMany($ids) as $parent) {
            $path = $parent->url($type);
            if ($path !== null) {
                $parents[(int) $parent->getKey()] = ['name' => (string) $parent->title, 'path' => $path];
            }
        }

        return array_values(array_filter(array_map(static fn(int $id) => $parents[$id] ?? null, array_reverse($ids))));
    }

    /**
     * An absolute http(s) address with a host: no spaces, control characters
     * or backslashes (which browsers read in surprising ways), and no
     * user:password@ part (which hides the real host).
     */
    public static function isWebAddress(string $url): bool
    {
        if ($url === '' || strlen($url) > 2000 || preg_match('/[\x00-\x20\x7F\\\\]/', $url) === 1) {
            return false;
        }
        $parts = parse_url($url);

        return is_array($parts)
            && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && ($parts['host'] ?? '') !== ''
            && !isset($parts['user']) && !isset($parts['pass'])
            && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * The chosen sharing image as {url,width,height,alt}, or null when none
     * is set, the file is gone or it isn't an image.
     *
     * @return array{url: string, width: mixed, height: mixed, alt: string}|null
     */
    public static function media(mixed $id): ?array
    {
        $id = is_array($id) ? ($id[0] ?? null) : $id;
        if (!is_numeric($id) || (int) $id < 1) {
            return null;
        }
        $media = \Kestello\Cms\Media\MediaPresenter::many([(int) $id])[(int) $id] ?? null;
        if ($media === null || $media['kind'] !== 'image') {
            return null;
        }

        return ['url' => (string) $media['url'], 'width' => $media['width'], 'height' => $media['height'], 'alt' => (string) $media['alt']];
    }

    /**
     * The page's title for search engines and link previews: its own search
     * title when it has one, then its title, then the site name.
     *
     * @param array<string, mixed> $props
     */
    public static function title(array $props): string
    {
        $own = $props['seo']['title'] ?? null;
        if (is_string($own) && $own !== '') {
            return $own;
        }

        return is_string($props['title'] ?? null) ? $props['title'] : '';
    }

    /**
     * The text of the page's <title>: the site's title pattern, or its home
     * pattern on the home page and on a page without a title. The admin
     * keeps "Title - Site".
     *
     * @param array<string, mixed> $props
     */
    public static function documentTitle(array $props, string $url): string
    {
        $site = (string) ($props['site']['name'] ?? '');
        $title = self::title($props);
        if (isset($props['admin']) || $site === '') {
            return $title !== '' ? ($site !== '' ? "{$title} - {$site}" : $title) : $site;
        }
        $titles = self::titles();
        $home = (parse_url($url, PHP_URL_PATH) ?: '/') === '/' || $title === '';

        return SeoTitle::format($home ? $titles['home'] : $titles['page'], $title, $site, $titles['sep'], $titles['tagline']);
    }

    /**
     * The title patterns from Settings → Search and sharing, shared with
     * every page as site.titles so the browser writes the same title.
     *
     * @return array{page: string, home: string, sep: string, tagline: string}
     */
    public static function titles(): array
    {
        $s = Settings::getInstance();
        $sep = (string) $s->get('seo_separator', SeoTitle::SEPARATOR);

        return [
            'page' => (string) ($s->get('seo_title_pattern') ?: SeoTitle::PAGE),
            'home' => (string) ($s->get('seo_home_pattern') ?: SeoTitle::HOME),
            'sep' => isset(SeoTitle::SEPARATORS[$sep]) ? $sep : SeoTitle::SEPARATOR,
            'tagline' => (string) $s->get('site_description', ''),
        ];
    }

    /**
     * Whether an entry's canonical address is another page (it was first
     * published elsewhere): search engines list that one, so sitemaps leave
     * this one out.
     */
    public static function listedElsewhere(Entry $entry, string $address): bool
    {
        $own = $entry->field('seo');
        $canonical = is_array($own) ? trim((string) ($own['canonical'] ?? '')) : '';

        return self::isWebAddress($canonical) && rtrim($canonical, '/') !== rtrim($address, '/');
    }

    /** Whether an entry asked to stay out of search engines. */
    public static function hidden(Entry $entry): bool
    {
        $own = $entry->field('seo');

        return is_array($own) && !empty($own['noindex']);
    }

    /** Plain text from sanitized HTML, cut at a word near 160 characters. */
    public static function summary(string $html, int $length = 160): string
    {
        $text = html_entity_decode(strip_tags(preg_replace('/<(br|\/p|\/li|\/h[1-6])\b[^>]*>/i', ' $0', $html) ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return self::clip(trim((string) preg_replace('/\s+/u', ' ', $text)), $length);
    }

    /**
     * Pieces of text read one after another (a heading, a paragraph, button
     * labels) as sentences: a piece that does not end a sentence gets a full
     * stop, so "Fresh bread" and "Baked daily" read "Fresh bread. Baked daily",
     * not "Fresh bread Baked daily".
     *
     * @param list<string> $pieces
     */
    public static function sentences(array $pieces): string
    {
        $out = [];
        foreach ($pieces as $piece) {
            $piece = trim((string) preg_replace('/\s+/u', ' ', $piece));
            if ($piece === '') {
                continue;
            }
            $out[] = preg_match('/[.!?…:;]["”’)\]]*$/u', $piece) === 1 ? $piece : $piece . '.';
        }

        return implode(' ', $out);
    }

    private static function clip(string $text, int $length = 300): string
    {
        if (mb_strlen($text) <= $length) {
            return $text;
        }
        $cut = mb_substr($text, 0, $length);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space !== false && $space > $length * 0.6 ? mb_substr($cut, 0, $space) : $cut, ' ,.;:-') . '…';
    }

    /** The site's public address without a trailing slash (Settings, then config). */
    public static function siteUrl(Cms $cms): string
    {
        $url = (string) (Settings::getInstance()->get('site_url') ?: $cms->config->get('app.url', ''));

        return rtrim($url, '/');
    }

    /** Settings → Reading → "Ask search engines not to index this site". */
    public static function discouraged(): bool
    {
        return filter_var(Settings::getInstance()->get('discourage_search', false), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * The <head> tags for a rendered page.
     *
     * @param array{component: string, props: array<string, mixed>, url: string} $data
     */
    public static function head(Cms $cms, array $data): string
    {
        $props = $data['props'];
        if (isset($props['admin'])) {
            return '';
        }
        $seo = is_array($props['seo'] ?? null) ? $props['seo'] : [];
        $site = (string) ($props['site']['name'] ?? '');
        $base = self::siteUrl($cms);
        $abs = static fn(string $url): string => preg_match('#^https?://#i', $url) === 1 ? $url : $base . '/' . ltrim($url, '/');
        $error = str_starts_with($data['component'], '@core/errors/');

        $tags = [];
        $description = (string) ($seo['description'] ?? '');
        if ($description === '') {
            $description = (string) Settings::getInstance()->get('site_description', $cms->config->get('app.description', ''));
        }
        if ($description !== '') {
            $tags[] = ['meta', ['name' => 'description', 'content' => $description]];
        }
        $hidden = $error || !empty($seo['noindex']) || self::discouraged();
        // a page that may be listed lets search engines show its pictures large (Google Discover, image results)
        $tags[] = ['meta', ['name' => 'robots', 'content' => $hidden ? 'noindex' : 'max-image-preview:large']];
        $tags[] = ['link', ['rel' => 'alternate', 'type' => 'application/rss+xml', 'title' => $site, 'href' => '/feed']];
        if (is_array($seo['feed'] ?? null) && ($seo['feed']['url'] ?? '/feed') !== '/feed') {
            $tags[] = ['link', ['rel' => 'alternate', 'type' => 'application/rss+xml', 'title' => trim($seo['feed']['title'] . ' - ' . $site, ' -'), 'href' => (string) $seo['feed']['url']]];
        }

        if (!$error && $base !== '') {
            $path = (string) ($seo['canonical'] ?? (parse_url($data['url'], PHP_URL_PATH) ?: '/'));
            $url = $abs($path);
            // the home page is shared as the site, unless the editor gave it a title for sharing
            $own = is_string($seo['title'] ?? null) && $seo['title'] !== '';
            $title = ($path === '/' && !$own && $site !== '') ? $site : (self::title($props) ?: $site);
            $tags[] = ['link', ['rel' => 'canonical', 'href' => $url]];
            $tags[] = ['meta', ['property' => 'og:type', 'content' => $seo['type'] ?? 'website']];
            $tags[] = ['meta', ['property' => 'og:title', 'content' => $title]];
            $tags[] = ['meta', ['property' => 'og:url', 'content' => $url]];
            if ($site !== '') {
                $tags[] = ['meta', ['property' => 'og:site_name', 'content' => $site]];
            }
            if (($locale = self::ogLocale(Translator::locale())) !== null) {
                $tags[] = ['meta', ['property' => 'og:locale', 'content' => $locale]];
            }
            if ($description !== '') {
                $tags[] = ['meta', ['property' => 'og:description', 'content' => $description]];
            }
            $image = is_array($seo['image'] ?? null) ? $seo['image'] : self::media(Settings::getInstance()->get('seo_image'));
            if ($image !== null) {
                $tags[] = ['meta', ['property' => 'og:image', 'content' => $abs((string) $image['url'])]];
                foreach (['width', 'height'] as $dim) {
                    if (is_numeric($image[$dim] ?? null)) {
                        $tags[] = ['meta', ['property' => "og:image:{$dim}", 'content' => (string) (int) $image[$dim]]];
                    }
                }
                if (($image['alt'] ?? '') !== '') {
                    $tags[] = ['meta', ['property' => 'og:image:alt', 'content' => (string) $image['alt']]];
                }
            }
            if (is_string($seo['published'] ?? null) && $seo['published'] !== '') {
                $tags[] = ['meta', ['property' => 'article:published_time', 'content' => $seo['published']]];
                if (is_string($seo['modified'] ?? null) && $seo['modified'] !== '') {
                    $tags[] = ['meta', ['property' => 'article:modified_time', 'content' => $seo['modified']]];
                }
            }
            if (!$hidden) {
                foreach (self::alternates($cms, $data, $abs) as $alternate) {
                    $tags[] = ['link', ['rel' => 'alternate', 'hreflang' => $alternate['hreflang'], 'href' => $alternate['href']]];
                }
            }
            $tags[] = ['meta', ['name' => 'twitter:card', 'content' => $image !== null ? 'summary_large_image' : 'summary']];
        }

        $tags = (array) $cms->filters->apply('seo.head', $tags, $data);
        $html = '';
        foreach ($tags as $tag) {
            if (!is_array($tag) || !in_array($tag[0] ?? null, ['meta', 'link'], true) || !is_array($tag[1] ?? null)) {
                continue;
            }
            $html .= '<' . $tag[0];
            foreach ($tag[1] as $name => $value) {
                if (preg_match('/^[a-z][a-z:_-]*$/', (string) $name) !== 1 || $value === null || is_array($value) || is_object($value)) {
                    continue;
                }
                if ($name === 'href' && !Html::safeUrl((string) $value, ['http', 'https'])) {
                    continue;       // a filter's link never points at javascript: or data:
                }
                $html .= ' ' . $name . '="' . htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
            }
            $html .= ">\n";
        }

        return $html . StructuredData::script($cms, $data);
    }

    /**
     * The same page in other languages, from the "seo.alternates" filter
     * (core has one language per site; a translation plugin adds them).
     * Kept only with a valid language tag and a web address on this site or
     * an absolute http(s) one; at most 50.
     *
     * @param array{component: string, props: array<string, mixed>, url: string} $data
     * @param \Closure(string): string $abs
     * @return list<array{hreflang: string, href: string}>
     */
    public static function alternates(Cms $cms, array $data, \Closure $abs): array
    {
        $out = [];
        foreach ((array) $cms->filters->apply('seo.alternates', [], $data) as $alternate) {
            if (!is_array($alternate) || !is_string($alternate['hreflang'] ?? null) || !is_string($alternate['href'] ?? null)) {
                continue;
            }
            $lang = $alternate['hreflang'];
            $href = trim($alternate['href']);
            if (preg_match('/^(x-default|[a-z]{2,3}(-[a-z0-9]{2,8}){0,3})$/i', $lang) !== 1) {
                continue;
            }
            if (str_starts_with($href, '/') && !str_starts_with($href, '//') && preg_match('/[\s\\\\]/', $href) !== 1) {
                $href = $abs($href);
            } elseif (!self::isWebAddress($href)) {
                continue;
            }
            $out[strtolower($lang)] = ['hreflang' => $lang, 'href' => $href];
        }

        return array_slice(array_values($out), 0, 50);
    }

    /** "pt_BR" → "pt_BR", "sr_Latn_RS" → "sr_RS"; a language without a country ("en") has none. */
    public static function ogLocale(string $locale): ?string
    {
        $parts = explode('_', str_replace('-', '_', $locale));
        $language = strtolower($parts[0]);
        $country = null;
        foreach (array_slice($parts, 1) as $part) {
            if (preg_match('/^[A-Za-z]{2}$/', $part) === 1) {
                $country = strtoupper($part);
            }
        }

        return preg_match('/^[a-z]{2,3}$/', $language) === 1 && $country !== null ? $language . '_' . $country : null;
    }
}
