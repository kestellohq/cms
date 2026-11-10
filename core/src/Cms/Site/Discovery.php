<?php

declare(strict_types=1);

namespace Kestello\Cms\Site;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Kestello\Cms\Cms;
use Kestello\Cms\Content\ContentType;
use Kestello\Cms\Content\Entry;
use Kestello\Cms\Content\Term;
use Kestello\Cms\Media\MediaPresenter;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Core\Response;
use Kestello\Framework\Database\QueryBuilder;
use Kestello\Framework\Settings\Settings;
use XMLWriter;

/**
 * Machine-readable addresses: RSS 2.0 and Atom feeds, sitemap.xml
 * (an index plus one file per type and taxonomy, 2,000 addresses each) and
 * robots.txt. All public, read-only and cacheable for five minutes.
 */
final class Discovery
{
    public const int SITEMAP_CHUNK = 2000;

    // ── feeds ────────────────────────────────────────────────────────────

    /**
     * /feed (every type with an archive), /blog/feed (one type),
     * /category/news/feed (one term). Add /atom for Atom.
     */
    public static function feed(Cms $cms, Request $request, string $format, ?string $typeName = null, ?string $taxName = null, ?string $slug = null): Response
    {
        $site = (string) (Settings::getInstance()->get('site_name') ?: $cms->config->get('app.name', 'Kestello'));
        $term = null;
        if ($taxName !== null) {
            $tax = $cms->content->getTaxonomy($taxName);
            $term = $tax === null ? null : Term::query()->of($tax->name)->where('slug', (string) $slug)->first();
            if ($term === null) {
                return Response::notFound();
            }
            $types = array_filter(array_map($cms->content->get(...), $tax->getTypes()), static fn($t) => $t !== null && $t->getPath() !== null);
            $title = "{$term->name} - {$site}";
            $self = $term->path($tax) . '/feed';
            $home = $term->path($tax);
        } elseif ($typeName !== null) {
            $type = $cms->content->get($typeName);
            if ($type === null || !$type->hasArchive()) {
                return Response::notFound();
            }
            $types = [$type];
            $title = "{$type->plural()} - {$site}";
            $self = $type->getPath() . '/feed';
            $home = (string) $type->getPath();
        } else {
            $types = array_filter($cms->content->types(), static fn(ContentType $t) => $t->hasArchive());
            $title = $site;
            $self = '/feed';
            $home = '/';
        }
        $byName = [];
        foreach ($types as $t) {
            $byName[$t->name] = $t;
        }
        $limit = max(1, min(50, (int) (Settings::getInstance()->get('feed_items') ?: 10)));
        $query = Entry::query()->whereIn('type', array_keys($byName) ?: ['-'])->published()->with('author')
            ->orderByDesc('published_at')->orderByDesc('id')->limit($limit * 2);
        if ($term !== null) {
            $query->whereExists(static fn(QueryBuilder $q) => $q->from('entry_term')->whereColumn('entry_term.entry_id', '=', 'entries.id')->where('entry_term.term_id', $term->getKey()));
        }
        $entries = array_slice(array_values(array_filter($query->get()->all(), static fn(Entry $e) => !Seo::hidden($e))), 0, $limit);

        $base = Seo::siteUrl($cms);
        $host = (string) (parse_url($base, PHP_URL_HOST) ?: 'localhost');
        $items = [];
        foreach ($entries as $entry) {
            $type = $byName[$entry->type];
            $html = '';
            foreach ($type->getFields() as $field) {
                if (!$field->isInternal() && $field->type === 'rich_text' && is_string($v = $entry->field($field->name))) {
                    $html .= $v;
                } elseif (!$field->isInternal() && $field->type === 'blocks') {
                    $html .= self::blocksHtml($cms->blocks->prepare($entry->field($field->name) ?? [], new \Kestello\Cms\Blocks\BlockContext($entry, $type)));
                }
            }
            $published = self::date($entry->published_at) ?? self::date($entry->created_at) ?? new DateTimeImmutable();
            $items[] = [
                'title' => (string) $entry->title,
                'url' => $base . self::address($entry, $type),
                'id' => "tag:{$host},{$published->format('Y-m-d')}:entry-{$entry->getKey()}",   // survives slug changes
                'published' => $published,
                'updated' => self::date($entry->updated_at) ?? $published,
                'author' => $entry->author?->username !== null ? (string) $entry->author->username : null,
                'summary' => ($type->hasExcerpt() && trim((string) $entry->excerpt) !== '') ? trim((string) $entry->excerpt) : Seo::summary($html, 300),
                'html' => $html,
            ];
        }
        $feed = new Feed(
            $title,
            $base . $home,
            $base . $self . ($format === 'atom' ? '/atom' : ''),
            (string) Settings::getInstance()->get('site_description', ''),
            "tag:{$host},2000:" . $self,
        );
        foreach ($items as $item) {
            $feed->add($item['title'], $item['url'], $item['published'], $item['updated'], $item['summary'], $item['html'], $item['author'], $item['id']);
        }
        \Kestello\Cms\Cache\PageTags::add('content');

        return $format === 'atom' ? $feed->atom() : $feed->rss();
    }

    // ── sitemaps ─────────────────────────────────────────────────────────

    /** A sitemap file name: a type, taxonomy or plugin name and a page number. */
    private const string FILE = '/^[a-z0-9_]+-[1-9][0-9]{0,4}$/';

    /** /sitemap.xml: one file per type and taxonomy, split every 2,000 addresses. */
    public static function sitemapIndex(Cms $cms): Response
    {
        $base = Seo::siteUrl($cms);
        $w = self::writer();
        $w->startElement('sitemapindex');
        $w->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        foreach (self::listedFiles($cms) as $file => $lastmod) {
            $w->startElement('sitemap');
            $w->writeElement('loc', "{$base}/sitemaps/{$file}.xml");
            if ($lastmod !== null) {
                $w->writeElement('lastmod', $lastmod->format(DateTimeInterface::ATOM));
            }
            $w->endElement();
        }
        $w->endElement();

        return self::xml($w->outputMemory(), 'application/xml');
    }

    /**
     * Core's files and the ones plugins add with "sitemap.files"; names a
     * route could not serve, and dates that aren't dates, are left out.
     *
     * @return array<string, ?DateTimeInterface>
     */
    private static function listedFiles(Cms $cms): array
    {
        $out = [];
        foreach ((array) $cms->filters->apply('sitemap.files', self::sitemapFiles($cms)) as $name => $lastmod) {
            if (is_string($name) && preg_match(self::FILE, $name) === 1) {
                $out[$name] = $lastmod instanceof DateTimeInterface ? $lastmod : null;
            }
        }

        return $out;
    }

    /** @return array<string, ?DateTimeImmutable> file name (type-1) => newest change */
    private static function sitemapFiles(Cms $cms): array
    {
        $files = [];
        foreach ($cms->content->types() as $type) {
            if ($type->getPath() === null) {
                continue;
            }
            $count = Entry::query()->ofType($type->name)->published()->count();
            $newest = self::date(Entry::query()->ofType($type->name)->published()->toBase()->max('updated_at'));
            for ($i = 1, $n = max(1, (int) ceil($count / self::SITEMAP_CHUNK)); $i <= $n && ($count > 0 || $type->hasArchive()); $i++) {
                $files["{$type->name}-{$i}"] = $newest;
            }
        }
        foreach ($cms->content->taxonomies() as $tax) {
            if ($tax->getPath() === null) {
                continue;
            }
            $count = Term::onlyInUse(Term::query()->of($tax->name))->count();
            for ($i = 1, $n = (int) ceil($count / self::SITEMAP_CHUNK); $i <= $n; $i++) {
                $files["{$tax->name}-{$i}"] = null;
            }
        }

        return $files;
    }

    /** /sitemaps/post-1.xml, /sitemaps/category-1.xml */
    public static function sitemap(Cms $cms, string $file): Response
    {
        return Entry::rememberingParents(static fn(): Response => self::sitemapFile($cms, $file));
    }

    private static function sitemapFile(Cms $cms, string $file): Response
    {
        if (preg_match('/^([a-z0-9_]+)-([1-9][0-9]{0,4})\.xml$/', $file, $m) !== 1) {
            return Response::notFound();
        }
        $urls = self::coreUrls($cms, $m[1], (int) $m[2]);
        $urls = self::siteUrls(Seo::siteUrl($cms), (array) $cms->filters->apply('sitemap.urls', $urls, $m[1] . '-' . $m[2]));
        if ($urls === []) {
            return Response::notFound();
        }

        $w = self::writer();
        $w->startElement('urlset');
        $w->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $w->writeAttribute('xmlns:image', 'http://www.google.com/schemas/sitemap-image/1.1');
        foreach ($urls as $u) {
            $w->startElement('url');
            $w->writeElement('loc', $u['loc']);
            if ($u['lastmod'] !== null) {
                $w->writeElement('lastmod', $u['lastmod']->format(DateTimeInterface::ATOM));
            }
            foreach ($u['images'] as $img) {
                $w->startElement('image:image');
                $w->writeElement('image:loc', $img);
                $w->endElement();
            }
            $w->endElement();
        }
        $w->endElement();

        return self::xml($w->outputMemory(), 'application/xml');
    }

    /**
     * What "sitemap.urls" returned, kept to what a sitemap may hold: addresses
     * on this site only (a path, or an absolute address under the site
     * address), real dates, http(s) images, and at most the protocol's
     * 50,000 addresses with 1,000 images each.
     *
     * @param array<mixed> $urls
     * @return list<array{loc: string, lastmod: ?DateTimeInterface, images: list<string>}>
     */
    public static function siteUrls(string $base, array $urls): array
    {
        $on = static function (mixed $url) use ($base): ?string {
            if (!is_string($url) || $url === '') {
                return null;
            }
            if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
                $url = $base . $url;
            }

            return Seo::isWebAddress($url) && ($url === $base || str_starts_with($url, $base . '/')) ? $url : null;
        };
        $out = [];
        foreach ($urls as $u) {
            $loc = is_array($u) ? $on($u['loc'] ?? null) : null;
            if ($loc === null) {
                continue;
            }
            $images = [];
            foreach (is_array($u['images'] ?? null) ? $u['images'] : [] as $img) {
                if (is_string($img) && ($on($img) !== null || Seo::isWebAddress($img))) {
                    $images[] = $on($img) ?? $img;      // images may live on a CDN or in a bucket
                }
            }
            $lastmod = $u['lastmod'] ?? null;
            $out[] = ['loc' => $loc, 'lastmod' => $lastmod instanceof DateTimeInterface ? $lastmod : null, 'images' => array_slice($images, 0, 1000)];
            if (count($out) === 50000) {
                break;
            }
        }

        return $out;
    }

    /**
     * Core's addresses for a file name: a public type's or taxonomy's page
     * of entries; [] for any other name (a plugin's file).
     *
     * @return list<array{loc: string, lastmod: ?DateTimeInterface, images: list<string>}>
     */
    private static function coreUrls(Cms $cms, string $name, int $page): array
    {
        $base = Seo::siteUrl($cms);
        $urls = [];
        if (($type = $cms->content->get($name)) !== null && $type->getPath() !== null) {
            if ($page === 1 && $type->hasArchive()) {
                $urls[] = ['loc' => $base . $type->getPath(), 'lastmod' => null, 'images' => []];
            }
            $entries = Entry::query()->ofType($type->name)->published()->orderBy('id')
                ->offset(($page - 1) * self::SITEMAP_CHUNK)->limit(self::SITEMAP_CHUNK)->get()->all();
            if ($entries === [] && $urls === []) {
                return [];
            }
            $mediaFields = array_filter($type->getFields(), static fn($f) => $f->type === 'media' && !$f->isInternal());
            $ids = [];
            foreach ($entries as $e) {
                foreach ($mediaFields as $f) {
                    $ids = [...$ids, ...array_map('intval', (array) ($e->field($f->name) ?? []))];
                }
            }
            $media = $ids === [] ? [] : MediaPresenter::many(array_values(array_unique($ids)));
            foreach ($entries as $e) {
                if (Seo::hidden($e) || ($type->isHierarchical() && $e->publicAncestors() === null)) {
                    continue;       // under a draft, private or trashed parent: not public
                }
                if (Seo::listedElsewhere($e, $base . self::address($e, $type))) {
                    continue;       // its canonical address is another one: a sitemap lists only canonical addresses
                }
                $images = [];
                foreach ($mediaFields as $f) {
                    foreach ((array) ($e->field($f->name) ?? []) as $id) {
                        $item = $media[(int) $id] ?? null;
                        if (is_array($item) && ($item['kind'] ?? '') === 'image') {
                            $images[] = preg_match('#^https?://#i', (string) $item['url']) === 1 ? (string) $item['url'] : $base . $item['url'];
                        }
                    }
                }
                $urls[] = ['loc' => $base . self::address($e, $type), 'lastmod' => self::date($e->updated_at), 'images' => array_slice(array_unique($images), 0, 20)];
            }
        } elseif (($tax = $cms->content->getTaxonomy($name)) !== null && $tax->getPath() !== null) {
            $terms = Term::onlyInUse(Term::query()->of($tax->name))->orderBy('id')->offset(($page - 1) * self::SITEMAP_CHUNK)->limit(self::SITEMAP_CHUNK)->get()->all();
            if ($terms === []) {
                return [];
            }
            foreach ($terms as $t) {
                $urls[] = ['loc' => $base . $t->path($tax), 'lastmod' => null, 'images' => []];
            }
        } else {
            return [];
        }

        return $urls;
    }

    // ── robots.txt ───────────────────────────────────────────────────────

    public static function robots(Cms $cms): Response
    {
        \Kestello\Cms\Cache\PageTags::add('robots');      // settings only: purged with everything
        $lines = ['User-agent: *'];
        if (Seo::discouraged()) {
            $lines[] = 'Disallow: /';
        } else {
            array_push($lines, 'Disallow: /admin/', 'Disallow: /api/', 'Disallow: /search');
        }
        $extra = trim(str_replace("\r", '', (string) Settings::getInstance()->get('robots_txt', '')));
        if ($extra !== '') {
            $lines[] = '';
            $lines[] = $extra;
        }
        $base = Seo::siteUrl($cms);
        if ($base !== '') {
            $lines[] = '';
            $lines[] = "Sitemap: {$base}/sitemap.xml";
        }
        $body = (string) $cms->filters->apply('robots.txt', implode("\n", $lines) . "\n");

        return (new Response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'public, max-age=300']));
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** The public address of an entry; the home page is "/". */
    /**
     * A block tree as plain HTML for feed readers, prepared for a visitor
     * (members-only and scheduled blocks are already gone). Core text,
     * headings, lists, quotes, images and buttons; other blocks give their
     * children, or nothing. Rich text was cleaned when it was prepared.
     *
     * @param list<array<string, mixed>> $nodes
     */
    private static function blocksHtml(array $nodes): string
    {
        $e = static fn(mixed $v): string => htmlspecialchars(is_scalar($v) ? (string) $v : '', ENT_QUOTES | ENT_HTML5);
        $html = '';
        foreach ($nodes as $node) {
            $p = (array) ($node['props'] ?? []);
            $html .= match ($node['type'] ?? '') {
                'core/heading' => ($l = max(1, min(6, (int) ($p['level'] ?? 2)))) ? "<h{$l}>" . $e($p['text'] ?? '') . "</h{$l}>" : '',
                'core/text' => is_string($p['text'] ?? null) ? $p['text'] : '',
                'core/quote' => '<blockquote><p>' . nl2br($e($p['text'] ?? '')) . '</p>' . (($p['cite'] ?? '') !== '' ? '<cite>' . $e($p['cite']) . '</cite>' : '') . '</blockquote>',
                'core/list' => (!empty($p['ordered']) ? '<ol>' : '<ul>') . implode('', array_map(static fn($i) => '<li>' . $e(is_array($i) ? ($i['text'] ?? '') : $i) . '</li>', is_array($p['items'] ?? null) ? $p['items'] : [])) . (!empty($p['ordered']) ? '</ol>' : '</ul>'),
                'core/image' => is_array($p['image'] ?? null) && is_string($p['image']['url'] ?? null)
                    ? '<figure><img src="' . $e($p['image']['url']) . '" alt="' . $e(($p['alt'] ?? '') !== '' ? $p['alt'] : ($p['image']['alt'] ?? '')) . '">' . (($p['caption'] ?? '') !== '' ? '<figcaption>' . $e($p['caption']) . '</figcaption>' : '') . '</figure>' : '',
                'core/button' => is_string($p['link'] ?? null) && ($p['text'] ?? '') !== '' ? '<p><a href="' . $e($p['link']) . '">' . $e($p['text']) . '</a></p>' : '',
                default => self::blocksHtml(is_array($node['children'] ?? null) ? $node['children'] : []),
            };
        }

        return $html;
    }

    private static function address(Entry $entry, ContentType $type): string
    {
        return (string) $entry->url($type);
    }

    private static function date(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }
        if (!is_string($value) || $value === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }

    private static function writer(): XMLWriter
    {
        $w = new XMLWriter();
        $w->openMemory();
        $w->setIndent(true);
        $w->setIndentString('  ');
        $w->startDocument('1.0', 'UTF-8');

        return $w;
    }

    private static function xml(string $body, string $type): Response
    {
        \Kestello\Cms\Cache\PageTags::add('content');
        return new Response($body, 200, [
            'Content-Type' => "{$type}; charset=UTF-8",
            'Cache-Control' => 'public, max-age=300',
            'X-Robots-Tag' => 'noindex',     // the feed itself isn't a page to list
        ]);
    }
}
