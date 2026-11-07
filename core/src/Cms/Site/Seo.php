<?php

declare(strict_types=1);

namespace Kestello\Cms\Site;

use Kestello\Cms\Cms;
use Kestello\Cms\Content\ContentType;
use Kestello\Cms\Content\Entry;
use Kestello\Cms\Content\Field;
use Kestello\Framework\Settings\Settings;

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
        $image = self::image($own['image'] ?? null);
        foreach ($type->getFields() as $field) {
            if ($field->isInternal()) {
                continue;
            }
            if ($description === '' && $field->type === 'rich_text' && is_string($data[$field->name] ?? null)) {
                $description = self::summary($data[$field->name]);
            }
            if ($description === '' && $field->type === 'blocks' && ($cms = \Kestello\Cms\Cms::instance()) !== null) {
                // pages built from blocks: the text every visitor sees, never members-only or scheduled blocks
                $description = $cms->search->visibleBlockText($entry->field($field->name));
            }
            if ($image === null && $field->type === 'media') {
                $value = $data[$field->name] ?? null;
                $first = is_array($value) && array_is_list($value) ? ($value[0] ?? null) : $value;
                if (is_array($first) && ($first['kind'] ?? 'image') === 'image' && isset($first['url'])) {
                    $image = ['url' => (string) $first['url'], 'width' => $first['width'] ?? null, 'height' => $first['height'] ?? null, 'alt' => (string) ($first['alt'] ?? '')];
                }
            }
        }

        return [
            'title' => $title === '' ? null : $title,
            'description' => self::clip($description),
            'canonical' => $canonical,
            'image' => $image,
            'type' => $type->hasArchive() ? 'article' : 'website',
            'published' => $type->hasArchive() ? ($presented['published_at'] ?? null) : null,
            'noindex' => !empty($own['noindex']),
        ];
    }

    /**
     * The chosen sharing image as {url,width,height,alt}, or null when none
     * is set, the file is gone or it isn't an image.
     *
     * @return array{url: string, width: mixed, height: mixed, alt: string}|null
     */
    private static function image(mixed $id): ?array
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
        if ($error || !empty($seo['noindex']) || self::discouraged()) {
            $tags[] = ['meta', ['name' => 'robots', 'content' => 'noindex']];
        }
        $tags[] = ['link', ['rel' => 'alternate', 'type' => 'application/rss+xml', 'title' => $site, 'href' => '/feed']];
        if (is_array($seo['feed'] ?? null) && ($seo['feed']['url'] ?? '/feed') !== '/feed') {
            $tags[] = ['link', ['rel' => 'alternate', 'type' => 'application/rss+xml', 'title' => trim($seo['feed']['title'] . ' - ' . $site, ' -'), 'href' => (string) $seo['feed']['url']]];
        }

        if (!$error && $base !== '') {
            $path = (string) ($seo['canonical'] ?? (parse_url($data['url'], PHP_URL_PATH) ?: '/'));
            $url = $abs($path);
            $title = self::title($props) ?: $site;
            $tags[] = ['link', ['rel' => 'canonical', 'href' => $url]];
            $tags[] = ['meta', ['property' => 'og:type', 'content' => $seo['type'] ?? 'website']];
            $tags[] = ['meta', ['property' => 'og:title', 'content' => $title]];
            $tags[] = ['meta', ['property' => 'og:url', 'content' => $url]];
            if ($site !== '') {
                $tags[] = ['meta', ['property' => 'og:site_name', 'content' => $site]];
            }
            if ($description !== '') {
                $tags[] = ['meta', ['property' => 'og:description', 'content' => $description]];
            }
            $image = is_array($seo['image'] ?? null) ? $seo['image'] : self::image(Settings::getInstance()->get('seo_image'));
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
                if (preg_match('/^[a-z][a-z:_-]*$/', (string) $name) === 1 && $value !== null) {
                    $html .= ' ' . $name . '="' . htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
                }
            }
            $html .= ">\n";
        }

        return $html;
    }
}
