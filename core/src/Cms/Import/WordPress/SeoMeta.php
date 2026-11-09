<?php

declare(strict_types=1);

namespace Kestello\Cms\Import\WordPress;

use Kestello\Cms\Site\Seo;

/**
 * The search settings an SEO plugin kept on a WordPress post, read from its
 * post meta in the export: Yoast SEO, Rank Math, SEOPress and All in One
 * SEO (before version 4, which keeps them in a table of its own).
 *
 * Their titles are templates ("%%title%% %%sep%% %%sitename%%"). Here the
 * site's title pattern adds the site name, so the template is reduced to
 * the part that belongs to the post; a title that comes out as the post's
 * own title needs no search title at all. Variables that depend on the old
 * site (categories, dates, page numbers) are left out.
 *
 * Nothing is unserialized: Rank Math's robots setting is a serialized PHP
 * array, and unserializing an uploaded file could create objects. It is
 * searched as text instead.
 *
 * @internal
 */
final class SeoMeta
{
    /** Meta keys per plugin, in the order they are tried. */
    private const array PLUGINS = [
        'Yoast SEO' => [
            'title' => '_yoast_wpseo_title', 'description' => '_yoast_wpseo_metadesc', 'noindex' => '_yoast_wpseo_meta-robots-noindex',
            'canonical' => '_yoast_wpseo_canonical', 'image_id' => '_yoast_wpseo_opengraph-image-id', 'image_url' => '_yoast_wpseo_opengraph-image',
        ],
        'Rank Math' => [
            'title' => 'rank_math_title', 'description' => 'rank_math_description', 'noindex' => 'rank_math_robots',
            'canonical' => 'rank_math_canonical_url', 'image_id' => 'rank_math_facebook_image_id', 'image_url' => 'rank_math_facebook_image',
        ],
        'SEOPress' => [
            'title' => '_seopress_titles_title', 'description' => '_seopress_titles_desc', 'noindex' => '_seopress_robots_index',
            'canonical' => '_seopress_robots_canonical', 'image_id' => '_seopress_social_fb_img_attachment_id', 'image_url' => '_seopress_social_fb_img',
        ],
        'All in One SEO' => [
            'title' => '_aioseop_title', 'description' => '_aioseop_description', 'noindex' => '_aioseop_noindex',
            'canonical' => '_aioseop_custom_link', 'image_id' => '', 'image_url' => '',
        ],
    ];

    /** Variables that stand for the post's title. */
    private const array TITLE = ['title', 'post_title'];

    /** Variables for the site's name (a description keeps it; a title leaves it to the title pattern). */
    private const array SITE_NAME = ['sitename', 'sitetitle', 'site_title', 'blog_title'];

    /** Variables for the separator. */
    private const array SEPARATOR = ['sep', 'separator'];

    /** Variables a plugin knows with one % on each side (Rank Math, All in One SEO); with %% any name is one. */
    private const array SINGLE = ['title', 'post_title', 'sitename', 'sitetitle', 'site_title', 'blog_title', 'sitedesc', 'tagline', 'sep', 'separator',
        'page', 'pagenumber', 'pagetotal', 'excerpt', 'excerpt_only', 'category', 'categories', 'primary_category', 'tag', 'tags', 'term',
        'currentyear', 'currentmonth', 'currentday', 'currentdate', 'date', 'modified', 'name', 'author', 'post_author', 'focuskw', 'keywords'];

    /** Characters that separate a title from the site name. */
    private const string DIVIDERS = '-–—|·•»«:/,~';

    /**
     * @param array<string, string> $meta the post's meta, key => value
     * @return array{source: string, title: ?string, description: ?string, noindex: bool, canonical: ?string, image_id: int, image_url: ?string}|null
     *         null when no SEO plugin left anything
     */
    public static function read(array $meta, string $postTitle, string $siteName): ?array
    {
        $found = null;
        $out = ['title' => null, 'description' => null, 'noindex' => false, 'canonical' => null, 'image_id' => 0, 'image_url' => null];
        foreach (self::PLUGINS as $plugin => $keys) {
            $value = static fn(string $what): string => $keys[$what] === '' ? '' : trim((string) ($meta[$keys[$what]] ?? ''));
            if (!array_any($keys, static fn(string $key): bool => $key !== '' && trim((string) ($meta[$key] ?? '')) !== '')) {
                continue;
            }
            $found ??= $plugin;
            $out['title'] ??= self::title($value('title'), $postTitle);
            $out['description'] ??= self::description($value('description'), $postTitle, $siteName);
            $out['noindex'] = $out['noindex'] || self::noindex($plugin, $value('noindex'));
            $canonical = $value('canonical');
            $out['canonical'] ??= Seo::isWebAddress($canonical) ? $canonical : null;
            if ($out['image_id'] === 0 && ctype_digit($value('image_id'))) {
                $out['image_id'] = (int) $value('image_id');
            }
            $image = $value('image_url');
            $out['image_url'] ??= Seo::isWebAddress($image) ? $image : null;
        }

        return $found === null ? null : ['source' => $found] + $out;
    }

    /**
     * The part of a title template that belongs to the post; null when that
     * is the post's own title (nothing to keep) or nothing at all.
     */
    public static function title(string $template, string $postTitle): ?string
    {
        $text = self::plain($template);
        if ($text === '') {
            return null;
        }
        $post = "\u{E000}";
        $text = self::variables($text, static fn(string $name): string => match (true) {
            in_array($name, self::TITLE, true) => $post,
            in_array($name, self::SEPARATOR, true) => ' - ',
            default => ' ',     // the site name (the title pattern adds it), page numbers, categories, dates
        });
        $text = str_replace($post, self::plain($postTitle), self::tidy($text));
        if ($text === '' || mb_strtolower($text) === mb_strtolower(self::plain($postTitle))) {
            return null;
        }

        return mb_substr($text, 0, 150);
    }

    /** A description template with its variables filled in or left out; null when nothing is left. */
    public static function description(string $template, string $postTitle, string $siteName): ?string
    {
        $text = self::plain($template);
        if ($text === '') {
            return null;
        }
        $text = self::tidy(self::variables($text, static fn(string $name): string => match (true) {
            in_array($name, self::TITLE, true) => self::plain($postTitle),
            in_array($name, self::SITE_NAME, true) => $siteName,
            in_array($name, self::SEPARATOR, true) => ' - ',
            default => ' ',     // the excerpt (used here anyway when there is no description), categories, dates
        }));

        return $text === '' ? null : mb_substr($text, 0, 300);
    }

    private static function noindex(string $plugin, string $value): bool
    {
        return match ($plugin) {
            'Yoast SEO' => $value === '1',                                          // 2 is "index", empty the default
            'Rank Math' => preg_match('/"noindex"/', $value) === 1,                 // a serialized list of robots values
            'SEOPress' => $value === 'yes',
            'All in One SEO' => in_array(strtolower($value), ['on', '1', 'yes'], true),
            default => false,
        };
    }

    /**
     * Replace %%name%% (any name) and %name% (only names plugins use, so
     * "50% off, 20% more" stays as written).
     *
     * @param \Closure(string): string $with
     */
    private static function variables(string $text, \Closure $with): string
    {
        $text = (string) preg_replace_callback('/%%([a-z0-9_]+)(?:\([^)%]*\))?%%/i', static fn(array $m): string => $with(strtolower($m[1])), $text);

        return (string) preg_replace_callback('/%([a-z_]+)(?:\([^)%]*\))?%/i', static fn(array $m): string => in_array(strtolower($m[1]), self::SINGLE, true) ? $with(strtolower($m[1])) : $m[0], $text);
    }

    /** One space between words, and no divider left hanging at either end or doubled. */
    private static function tidy(string $text): string
    {
        $d = preg_quote(self::DIVIDERS, '/');
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        do {
            $before = $text;
            $text = (string) preg_replace(["/^[\\s{$d}]+/u", "/[\\s{$d}]+$/u", "/([{$d}])(\\s*[{$d}])+/u"], ['', '', '$1'], $text);
            $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        } while ($text !== $before);

        return $text;
    }

    /** Text without tags or entities, on one line. */
    private static function plain(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }
}
