<?php

declare(strict_types=1);

namespace Kestello\Cms\Import\WordPress;

use DOMElement;
use DOMNode;
use Generator;
use Kestello\Cms\Import\ImportException;
use XMLReader;

/**
 * Reads a WordPress export (WXR 1.0, 1.1 or 1.2) one record at a time, so a
 * file of any size is read with the memory of its largest single post.
 *
 * Elements are matched by local name and namespace family, never by prefix:
 * exports in the wild use other prefixes and every WXR version has its own
 * namespace address (http://wordpress.org/export/1.x/).
 *
 * records() yields, in file order:
 *   ['kind' => 'site',   ...site]                 once, before the first author, term or item
 *   ['kind' => 'author', ...author]
 *   ['kind' => 'term',   ...term]
 *   ['kind' => 'item',   ...item]
 *
 * The shapes are documented on site(), author(), term() and item().
 */
final class WxrReader
{
    private const string CONTENT_NS = 'http://purl.org/rss/1.0/modules/content/';
    private const string DC_NS = 'http://purl.org/dc/elements/1.1/';

    /** Whether the <rss> root was reached: a parse error before it means another kind of file. */
    private bool $sawRss = false;

    public function __construct(private readonly string $path) {}

    /**
     * @return Generator<int, array<string, mixed>>
     * @throws ImportException when the file cannot be read or is not a WordPress export
     */
    public function records(): Generator
    {
        if (!is_file($this->path) || !is_readable($this->path)) {
            throw new ImportException('The export file could not be read.');
        }
        XmlCleanFilter::register();
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $reader = new XMLReader();
        if (!$reader->open('php://filter/read=' . XmlCleanFilter::NAME . '/resource=' . $this->path, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
            libxml_use_internal_errors($previous);
            throw new ImportException('The export file could not be opened.');
        }

        try {
            $channel = [];
            $siteSent = false;
            $this->sawRss = false;
            $positioned = false;       // true when next() already moved the reader onto the following node
            while ($positioned || $this->step($reader, static fn(XMLReader $r): bool => $r->read())) {
                $positioned = false;
                if ($reader->nodeType !== XMLReader::ELEMENT) {
                    continue;
                }
                $family = self::family($reader->namespaceURI);
                $name = $reader->localName;
                if ($reader->depth === 0) {
                    if ($name !== 'rss') {
                        throw new ImportException('This is not a WordPress export: it should start with <rss>. Make one in WordPress under Tools → Export.');
                    }
                    $this->sawRss = true;
                    continue;
                }
                if ($reader->depth !== 2) {
                    continue;
                }

                $isRecord = ($family === 'wp' && in_array($name, ['author', 'category', 'tag', 'term'], true)) || ($family === '' && $name === 'item');
                if (!$isRecord) {
                    // a channel field (title, link, wp:base_site_url, …)
                    $channel[$family . ':' . $name] = trim($reader->readString());
                    continue;
                }
                if (!$siteSent) {
                    yield ['kind' => 'site'] + self::site($channel);
                    $siteSent = true;
                }
                $node = null;
                $this->step($reader, static function (XMLReader $r) use (&$node): bool {
                    $node = $r->expand();
                    return $node !== false;
                });
                if ($node instanceof DOMElement) {
                    $record = match ($name) {
                        'author' => ['kind' => 'author'] + self::author($node),
                        'item' => ['kind' => 'item'] + self::item($node),
                        default => self::term($name, $node),
                    };
                    if ($record !== null) {
                        yield $record;
                    }
                }
                // skip the record's insides; the reader now sits on whatever follows it
                if (!$this->step($reader, static fn(XMLReader $r): bool => $r->next())) {
                    break;
                }
                $positioned = true;
            }
            if (!$this->sawRss) {
                throw new ImportException('This is not a WordPress export. Make one in WordPress under Tools → Export → All content.');
            }
            if (!$siteSent) {
                $site = self::site($channel);
                if ($site['url'] === '') {
                    throw new ImportException('This file has no WordPress site address (wp:base_site_url). Make the export again in WordPress under Tools → Export.');
                }
                yield ['kind' => 'site'] + $site;
            }
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * Move the reader (read() or next()) and turn a parse error into a message
     * with the line it happened on.
     *
     * @param callable(XMLReader): bool $move
     */
    private function step(XMLReader $reader, callable $move): bool
    {
        $ok = @$move($reader);
        $error = libxml_get_last_error();
        if ($error !== false && $error->level >= LIBXML_ERR_ERROR) {
            if (!$this->sawRss) {
                // broken before the <rss> root: some other kind of file
                throw new ImportException('This is not a WordPress export. Make one in WordPress under Tools → Export → All content.');
            }
            throw new ImportException(sprintf('The export file is damaged near line %d: %s. Download it again from WordPress; if it still fails, the file may have been cut off while uploading.', $error->line, trim($error->message)));
        }

        return $ok;
    }

    /** 'wp', 'excerpt', 'content', 'dc' or '' (plain RSS) for a namespace address. */
    private static function family(?string $uri): string
    {
        $uri = (string) $uri;
        if ($uri === '') {
            return '';
        }
        if (preg_match('#^https?://wordpress\.org/export/\d+\.\d+/excerpt/?$#', $uri) === 1) {
            return 'excerpt';
        }
        if (preg_match('#^https?://wordpress\.org/export/\d+\.\d+/?$#', $uri) === 1) {
            return 'wp';
        }

        return match ($uri) {
            self::CONTENT_NS => 'content',
            self::DC_NS => 'dc',
            default => 'other',
        };
    }

    /**
     * @param array<string, string> $channel
     * @return array{title: string, url: string, blog_url: string, description: string, language: string, version: string}
     */
    private static function site(array $channel): array
    {
        $url = rtrim($channel['wp:base_site_url'] ?? '', '/');

        return [
            'title' => $channel[':title'] ?? '',
            'url' => $url,
            'blog_url' => rtrim($channel['wp:base_blog_url'] ?? $url, '/'),
            'description' => $channel[':description'] ?? '',
            'language' => $channel[':language'] ?? '',
            'version' => $channel['wp:wxr_version'] ?? '1.0',
        ];
    }

    /**
     * The element's direct children as family:name → text. Repeated names keep the first.
     *
     * @return array<string, string>
     */
    private static function fields(DOMNode $node): array
    {
        $fields = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $key = self::family($child->namespaceURI) . ':' . $child->localName;
                $fields[$key] ??= $child->textContent;
            }
        }

        return $fields;
    }

    /**
     * @return array{id: int, login: string, email: string, name: string}
     */
    private static function author(DOMElement $node): array
    {
        $f = self::fields($node);
        $first = trim($f['wp:author_first_name'] ?? '');
        $last = trim($f['wp:author_last_name'] ?? '');
        $name = trim($f['wp:author_display_name'] ?? '');

        return [
            'id' => (int) ($f['wp:author_id'] ?? 0),
            'login' => trim($f['wp:author_login'] ?? ''),
            'email' => trim($f['wp:author_email'] ?? ''),
            'name' => $name !== '' ? $name : trim($first . ' ' . $last),
        ];
    }

    /**
     * A category, tag or other term. Parents are named by slug, as WordPress writes them.
     *
     * @return array{kind: 'term', taxonomy: string, id: int, slug: string, name: string, parent: string, description: string}|null
     */
    private static function term(string $element, DOMElement $node): ?array
    {
        $f = self::fields($node);
        [$taxonomy, $slug, $name, $parent, $description] = match ($element) {
            'category' => ['category', $f['wp:category_nicename'] ?? '', $f['wp:cat_name'] ?? '', $f['wp:category_parent'] ?? '', $f['wp:category_description'] ?? ''],
            'tag' => ['post_tag', $f['wp:tag_slug'] ?? '', $f['wp:tag_name'] ?? '', '', $f['wp:tag_description'] ?? ''],
            default => [$f['wp:term_taxonomy'] ?? '', $f['wp:term_slug'] ?? '', $f['wp:term_name'] ?? '', $f['wp:term_parent'] ?? '', $f['wp:term_description'] ?? ''],
        };
        $slug = self::slug($slug);
        if (trim($taxonomy) === '' || $slug === '') {
            return null;
        }

        return [
            'kind' => 'term',
            'taxonomy' => trim($taxonomy),
            'id' => (int) ($f['wp:term_id'] ?? 0),
            'slug' => $slug,
            'name' => trim($name) !== '' ? trim($name) : $slug,
            'parent' => self::slug($parent),
            'description' => trim($description),
        ];
    }

    /**
     * One post, page, attachment, menu item or other post type.
     *
     * Dates are WordPress's own 'Y-m-d H:i:s' strings ('' when unset);
     * date_gmt is '' when WordPress wrote 0000-00-00 (drafts never published).
     *
     * @return array{
     *   id: int, type: string, status: string, title: string, slug: string, link: string, guid: string,
     *   author: string, content: string, excerpt: string, date: string, date_gmt: string,
     *   modified: string, modified_gmt: string, parent: int, menu_order: int, password: string,
     *   sticky: bool, attachment_url: string, terms: list<array{taxonomy: string, slug: string, name: string}>,
     *   meta: array<string, string>, comments: int
     * }
     */
    private static function item(DOMElement $node): array
    {
        $f = self::fields($node);
        $terms = [];
        $meta = [];
        $comments = 0;
        foreach ($node->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            $family = self::family($child->namespaceURI);
            if ($family === '' && $child->localName === 'category') {
                $taxonomy = trim($child->getAttribute('domain'));
                $slug = self::slug($child->getAttribute('nicename'));
                $name = trim($child->textContent);
                if ($taxonomy !== '' && ($slug !== '' || $name !== '')) {
                    $terms[] = ['taxonomy' => $taxonomy, 'slug' => $slug, 'name' => $name !== '' ? $name : $slug];
                }
            } elseif ($family === 'wp' && $child->localName === 'postmeta') {
                $m = self::fields($child);
                $key = $m['wp:meta_key'] ?? '';
                if ($key !== '') {
                    $meta[$key] ??= $m['wp:meta_value'] ?? '';
                }
            } elseif ($family === 'wp' && $child->localName === 'comment') {
                $comments++;
            }
        }

        return [
            'id' => (int) ($f['wp:post_id'] ?? 0),
            'type' => trim($f['wp:post_type'] ?? 'post'),
            'status' => trim($f['wp:status'] ?? 'publish'),
            'title' => trim($f[':title'] ?? ''),
            'slug' => self::slug($f['wp:post_name'] ?? ''),
            'link' => trim($f[':link'] ?? ''),
            'guid' => trim($f[':guid'] ?? ''),
            'author' => trim($f['dc:creator'] ?? ''),
            'content' => $f['content:encoded'] ?? '',
            'excerpt' => $f['excerpt:encoded'] ?? '',
            'date' => self::date($f['wp:post_date'] ?? ''),
            'date_gmt' => self::date($f['wp:post_date_gmt'] ?? ''),
            'modified' => self::date($f['wp:post_modified'] ?? ''),
            'modified_gmt' => self::date($f['wp:post_modified_gmt'] ?? ''),
            'parent' => (int) ($f['wp:post_parent'] ?? 0),
            'menu_order' => (int) ($f['wp:menu_order'] ?? 0),
            'password' => $f['wp:post_password'] ?? '',
            'sticky' => trim($f['wp:is_sticky'] ?? '0') === '1',
            'attachment_url' => trim($f['wp:attachment_url'] ?? ''),
            'terms' => $terms,
            'meta' => $meta,
            'comments' => $comments,
        ];
    }

    /** WordPress stores non-Latin slugs percent-encoded; Kestello keeps them readable. */
    private static function slug(string $slug): string
    {
        $slug = trim($slug);

        return str_contains($slug, '%') ? rawurldecode($slug) : $slug;
    }

    private static function date(string $date): string
    {
        $date = trim($date);

        return preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $date) === 1 && !str_starts_with($date, '0000') ? $date : '';
    }
}
