<?php

declare(strict_types=1);

namespace Kestello\Cms\I18n;

use Kestello\Cms\Content\ContentType;
use Kestello\Cms\Content\Entry;
use Kestello\Cms\Content\Taxonomy;
use Kestello\Cms\Content\Term;

/**
 * The page being answered in each of the site's languages: where a
 * language switcher links to, and the hreflang alternates search engines
 * read.
 *
 * By default a page is at the same address in every language (/blog and
 * /es/blog). An entry's or term's page says better with forEntry() and
 * forTerm(): each language gets its translation's address, or the
 * original's under that language's prefix, marked missing (it shows the
 * original, marked as not translated yet).
 *
 * LanguagePrefix runs each request in during(), so what one page sets never
 * reaches the next.
 *
 * @api
 */
final class PageLanguages
{
    /** @var array{path: string, links: array<string, array{url: string, missing: bool}>|null} */
    private static array $page = ['path' => '/', 'links' => null];

    /**
     * Run $work for a request whose path, without a language prefix, is $path.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public static function during(string $path, callable $work): mixed
    {
        $previous = self::$page;
        self::$page = ['path' => $path, 'links' => null];
        try {
            return $work();
        } finally {
            self::$page = $previous;
        }
    }

    /** The page is an entry's: each language links to its published translation, else to the original's address in it. */
    public static function forEntry(Entry $entry, ContentType $type): void
    {
        $members = [$entry->language() => $entry];
        if ((string) $entry->translation_group !== '') {
            $q = Entry::query();
            $q->where('translation_group', $entry->translation_group)->where('type', $type->name);
            foreach ($q->get() as $member) {
                if ($member instanceof Entry && $member->isPublished() && !isset($members[$member->language()])) {
                    $members[$member->language()] = $member;
                }
            }
        }
        $urls = [];
        foreach ($members as $code => $member) {
            if ($member->isPublished() && ($url = $member->url($type)) !== null) {
                $urls[(string) $code] = $url;
            }
        }
        $original = isset($members['']) && $members['']->isPublished() ? $members['']->path($type) : null;
        self::fill($urls, $original);
    }

    /** The page is a term's: each language links to the term's translation, else to the original term's page in it. */
    public static function forTerm(Term $term, Taxonomy $taxonomy): void
    {
        $members = [$term->language() => $term];
        if ((string) $term->translation_group !== '') {
            $q = Term::query();
            $q->where('translation_group', $term->translation_group);
            foreach ($q->get() as $member) {
                if ($member instanceof Term) {
                    $members[$member->language()] ??= $member;
                }
            }
        }
        $links = [];
        foreach (self::codes() as $code) {
            $path = ($members[$code] ?? $members[''] ?? $term)->path($taxonomy);
            if ($path !== null) {
                $links[$code] = ['url' => ContentLanguages::localize($path, $code), 'missing' => false];    // a term's page lists that language's entries either way
            }
        }
        self::$page['links'] = $links;
    }

    /**
     * Every language for the page being answered, for a switcher: code (as
     * in a lang attribute: the main one's own, such as "en"), name, url,
     * whether it is the page's language, and whether the page isn't
     * translated into it. Empty on a site in one language.
     *
     * @return list<array{code: string, name: string, url: string, current: bool, missing: bool}>
     */
    public static function all(): array
    {
        if (ContentLanguages::others() === []) {
            return [];
        }
        $main = SiteLanguage::site();
        $out = [];
        foreach (self::codes() as $code) {
            $link = self::$page['links'][$code] ?? ['url' => ContentLanguages::localize(self::$page['path'], $code), 'missing' => false];
            $out[] = [
                'code' => $code === '' ? ContentLanguages::code($main) : $code,
                'name' => SiteLanguage::name($code === '' ? $main : $code),
                'url' => $link['url'], 'current' => $code === ContentLanguages::current(), 'missing' => $link['missing'],
            ];
        }

        return $out;
    }

    /**
     * hreflang alternates: the languages the page is really in, plus
     * x-default (the main language's). None unless there are two.
     *
     * @return list<array{hreflang: string, href: string}>
     */
    public static function alternates(): array
    {
        $out = [];
        foreach (self::all() as $l) {
            if (!$l['missing']) {
                $out[] = ['hreflang' => $l['code'], 'href' => $l['url']];
            }
        }
        if (count($out) < 2) {
            return [];
        }
        $main = self::all()[0];

        return $main['missing'] ? $out : [...$out, ['hreflang' => 'x-default', 'href' => $main['url']]];
    }

    /** @return list<string> '' (the main language) and the others */
    private static function codes(): array
    {
        return ['', ...ContentLanguages::others()];
    }

    /** @param array<string, string> $urls published addresses by language */
    private static function fill(array $urls, ?string $original): void
    {
        $links = [];
        foreach (self::codes() as $code) {
            $links[$code] = isset($urls[$code])
                ? ['url' => $urls[$code], 'missing' => false]
                : ['url' => ContentLanguages::localize($original ?? '/', $code), 'missing' => true];
        }
        self::$page['links'] = $links;
    }
}
