<?php

declare(strict_types=1);

namespace Kestello\Cms\Site;

use Kestello\Cms\Cms;
use Kestello\Framework\Settings\Settings;
use Kestello\Support\Translator;

/**
 * The schema.org description of a public page, as one JSON-LD @graph:
 * the site (WebSite) and who runs it (Organization or Person, from
 * Settings → Search and sharing), the page (WebPage, or CollectionPage for
 * a list), its main image, an Article for dated entries, and the
 * breadcrumbs to it. Nodes point at each other by @id, the way search
 * engines expect one graph per page.
 *
 * Plugins change the nodes with the "seo.schema" filter. Whatever comes
 * back is encoded with every character that could end the <script> escaped,
 * so a node can carry any text without breaking out of it.
 *
 * Pages that ask not to be listed (noindex, a hidden site), error pages
 * and the admin get none.
 */
final class StructuredData
{
    /** Search engines show about this much of an Article headline. */
    private const int HEADLINE = 110;

    private const int JSON = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    /**
     * The <script type="application/ld+json"> for a page, or '' when it gets none.
     *
     * @param array{component: string, props: array<string, mixed>, url: string} $data
     */
    public static function script(Cms $cms, array $data): string
    {
        $nodes = self::nodes($cms, $data);
        if ($nodes !== []) {
            $nodes = (array) $cms->filters->apply('seo.schema', $nodes, $data);
        }
        $nodes = array_values(array_filter($nodes, static fn(mixed $n): bool => is_array($n) && is_string($n['@type'] ?? null) && $n['@type'] !== ''));
        if ($nodes === []) {
            return '';
        }
        $json = json_encode(['@context' => 'https://schema.org', '@graph' => $nodes], self::JSON);

        return $json === false ? '' : '<script type="application/ld+json">' . $json . "</script>\n";
    }

    /**
     * The nodes before plugins see them.
     *
     * @param array{component: string, props: array<string, mixed>, url: string} $data
     * @return list<array<string, mixed>>
     */
    public static function nodes(Cms $cms, array $data): array
    {
        $props = $data['props'];
        $seo = is_array($props['seo'] ?? null) ? $props['seo'] : [];
        $base = Seo::siteUrl($cms);
        if (isset($props['admin']) || str_starts_with($data['component'], '@core/errors/') || $base === ''
            || !empty($seo['noindex']) || Seo::discouraged()) {
            return [];
        }

        $settings = Settings::getInstance();
        $site = (string) ($props['site']['name'] ?? $settings->get('site_name', ''));
        $language = str_replace('_', '-', Translator::locale());
        $abs = static fn(string $url): string => preg_match('#^https?://#i', $url) === 1 ? $url : $base . '/' . ltrim($url, '/');
        $path = (string) (parse_url($data['url'], PHP_URL_PATH) ?: '/');
        $url = $abs($path);     // this page, not its canonical: the graph describes the page being read
        $home = $path === '/';

        $publisher = self::publisher($base, $site);
        $website = array_filter([
            '@type' => 'WebSite',
            '@id' => $base . '/#website',
            'url' => $base . '/',
            'name' => $site,
            'description' => (string) $settings->get('site_description', '') ?: null,
            'publisher' => ['@id' => $publisher['@id']],
            'inLanguage' => $language,
        ], static fn(mixed $v): bool => $v !== null && $v !== '');

        $title = Seo::title($props) ?: $site;
        $image = is_array($seo['image'] ?? null) && is_string($seo['image']['url'] ?? null) ? $seo['image'] : null;
        $crumbs = $home ? [] : self::breadcrumbs($seo, $title, $abs, $url, $site, $base);
        $page = array_filter([
            '@type' => ($seo['kind'] ?? null) === 'collection' ? 'CollectionPage' : 'WebPage',
            '@id' => $url . '#webpage',
            'url' => $url,
            'name' => $title,
            'description' => (string) ($seo['description'] ?? '') ?: null,
            'isPartOf' => ['@id' => $base . '/#website'],
            'about' => $home ? ['@id' => $publisher['@id']] : null,
            'primaryImageOfPage' => $image !== null ? ['@id' => $url . '#primaryimage'] : null,
            'breadcrumb' => $crumbs !== [] ? ['@id' => $url . '#breadcrumb'] : null,
            'datePublished' => self::date($seo['published'] ?? null),
            'dateModified' => self::date($seo['modified'] ?? null),
            'inLanguage' => $language,
        ], static fn(mixed $v): bool => $v !== null && $v !== '');

        $nodes = [$website, $publisher, $page];
        if ($image !== null) {
            $nodes[] = self::image($url . '#primaryimage', $abs((string) $image['url']), $image);
        }
        if (($seo['kind'] ?? null) === 'article') {
            $author = trim((string) ($seo['author'] ?? ''));
            $nodes[] = array_filter([
                '@type' => 'Article',
                '@id' => $url . '#article',
                'headline' => self::clip($title, self::HEADLINE),
                'description' => (string) ($seo['description'] ?? '') ?: null,
                'datePublished' => self::date($seo['published'] ?? null),
                'dateModified' => self::date($seo['modified'] ?? null) ?? self::date($seo['published'] ?? null),
                'author' => $author !== '' ? ['@type' => 'Person', 'name' => $author] : ['@id' => $publisher['@id']],
                'publisher' => ['@id' => $publisher['@id']],
                'image' => $image !== null ? ['@id' => $url . '#primaryimage'] : null,
                'mainEntityOfPage' => ['@id' => $url . '#webpage'],
                'isPartOf' => ['@id' => $url . '#webpage'],
                'inLanguage' => $language,
            ], static fn(mixed $v): bool => $v !== null && $v !== '');
        }
        if ($crumbs !== []) {
            $nodes[] = ['@type' => 'BreadcrumbList', '@id' => $url . '#breadcrumb', 'itemListElement' => $crumbs];
        }

        return $nodes;
    }

    /**
     * Who runs the site: Organization (with its logo) or Person (with a
     * picture), with the profiles listed in Settings → Search and sharing.
     *
     * @return array<string, mixed>
     */
    private static function publisher(string $base, string $site): array
    {
        $s = Settings::getInstance();
        $person = $s->get('seo_publisher_type', 'organization') === 'person';
        $name = trim((string) $s->get('seo_publisher_name', '')) ?: $site;
        $logo = Seo::media($s->get('seo_publisher_logo'));
        $id = $base . '/#publisher';

        return array_filter([
            '@type' => $person ? 'Person' : 'Organization',
            '@id' => $id,
            'name' => $name,
            'url' => $base . '/',
            $person ? 'image' : 'logo' => $logo === null ? null
                : self::image($base . '/#logo', preg_match('#^https?://#i', $logo['url']) === 1 ? $logo['url'] : $base . '/' . ltrim($logo['url'], '/'), $logo),
            'sameAs' => self::profiles((string) $s->get('seo_profiles', '')) ?: null,
        ], static fn(mixed $v): bool => $v !== null && $v !== '');
    }

    /**
     * The profile addresses from the settings: one per line, only absolute
     * http(s) addresses, at most 20.
     *
     * @return list<string>
     */
    public static function profiles(string $lines): array
    {
        $out = [];
        foreach (preg_split('/\R/', $lines) ?: [] as $line) {
            $line = trim($line);
            if (Seo::isWebAddress($line) && !in_array($line, $out, true)) {
                $out[] = $line;
            }
        }

        return array_slice($out, 0, 20);
    }

    /**
     * Home first, then the archive or the parent pages, then this page.
     *
     * @param array<string, mixed> $seo
     * @param \Closure(string): string $abs
     * @return list<array<string, mixed>>
     */
    private static function breadcrumbs(array $seo, string $title, \Closure $abs, string $url, string $site, string $base): array
    {
        $list = [['name' => $site !== '' ? $site : 'Home', 'item' => $base . '/']];
        foreach (is_array($seo['breadcrumbs'] ?? null) ? $seo['breadcrumbs'] : [] as $crumb) {
            if (is_array($crumb) && is_string($crumb['name'] ?? null) && $crumb['name'] !== '' && is_string($crumb['path'] ?? null) && str_starts_with($crumb['path'], '/')) {
                $list[] = ['name' => $crumb['name'], 'item' => $abs($crumb['path'])];
            }
        }
        $list[] = ['name' => $title, 'item' => $url];

        return array_map(static fn(array $c, int $i): array => ['@type' => 'ListItem', 'position' => $i + 1] + $c, $list, array_keys($list));
    }

    /**
     * @param array<string, mixed> $media width, height, alt
     * @return array<string, mixed>
     */
    private static function image(string $id, string $url, array $media): array
    {
        return array_filter([
            '@type' => 'ImageObject',
            '@id' => $id,
            'url' => $url,
            'contentUrl' => $url,
            'width' => is_numeric($media['width'] ?? null) ? (int) $media['width'] : null,
            'height' => is_numeric($media['height'] ?? null) ? (int) $media['height'] : null,
            'caption' => (string) ($media['alt'] ?? '') ?: null,
        ], static fn(mixed $v): bool => $v !== null && $v !== '');
    }

    /** An ISO 8601 date as given, or null for anything else. */
    private static function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}(T[\d:.]+(Z|[+-]\d{2}:\d{2})?)?$/', $value) === 1 ? $value : null;
    }

    private static function clip(string $text, int $length): string
    {
        return mb_strlen($text) <= $length ? $text : rtrim(mb_substr($text, 0, $length - 1)) . '…';
    }
}
