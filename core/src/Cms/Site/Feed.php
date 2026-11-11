<?php

declare(strict_types=1);

namespace Kestello\Cms\Site;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use Kestello\Cms\Cms;
use Kestello\Framework\Core\Response;
use XMLWriter;

/**
 * An RSS 2.0 or Atom feed, as core writes its own (/feed, /blog/feed/atom), for a plugin or
 * theme that has a list of its own to offer: events, releases, advisories.
 *
 *   $feed = new Feed('Security advisories', '/advisories', '/advisories.atom');
 *   $feed->add('Stored XSS in Events', '/advisories/KSA-2027-0001', $published, summary: 'Events before 1.2.0.');
 *   return $feed->atom();          // or ->rss()
 *
 * Addresses can be paths on this site or absolute http(s) addresses; paths are put under the
 * site's address when the feed is written. Text is escaped as XML; html is sent as the item's
 * content and must be safe to show. Ids default to tag: URIs made from the site's host and
 * the address, so give items a lasting id of their own when their address can change.
 *
 * @api
 */
final class Feed
{
    /** @var list<array{title: string, url: string, id: ?string, published: DateTimeImmutable, updated: DateTimeImmutable, summary: string, html: string, author: ?string}> */
    private array $items = [];

    /**
     * @param string $home the page the feed belongs to
     * @param string $self the feed's own address
     * @param string|null $id the feed's lasting id; null: made from the site's host and $self
     * @param string $language what its items are written in, as a lang attribute ("en", "pt-br"); '' leaves it unsaid
     */
    public function __construct(
        private readonly string $title,
        private readonly string $home,
        private readonly string $self,
        private readonly string $description = '',
        private readonly ?string $id = null,
        private readonly string $language = '',
    ) {
        foreach ([$home, $self] as $address) {
            self::check($address);
        }
    }

    /**
     * One item, in the order given (newest first is what readers expect).
     *
     * @param string|null $id a lasting id; null: made from the site's host, the date and $url
     */
    public function add(
        string $title,
        string $url,
        DateTimeInterface $published,
        ?DateTimeInterface $updated = null,
        string $summary = '',
        string $html = '',
        ?string $author = null,
        ?string $id = null,
    ): self {
        self::check($url);
        $this->items[] = [
            'title' => $title,
            'url' => $url,
            'id' => $id,
            'published' => DateTimeImmutable::createFromInterface($published),
            'updated' => DateTimeImmutable::createFromInterface($updated ?? $published),
            'summary' => $summary,
            'html' => $html,
            'author' => $author,
        ];

        return $this;
    }

    public function atom(): Response
    {
        return self::response($this->atomXml(self::base()), 'application/atom+xml');
    }

    public function rss(): Response
    {
        return self::response($this->rssXml(self::base()), 'application/rss+xml');
    }

    /** The Atom document, with paths under $base (the site's address, no trailing slash). */
    public function atomXml(string $base): string
    {
        [$feed, $items] = $this->resolved($base);
        $w = self::writer();
        $w->startElement('feed');
        $w->writeAttribute('xmlns', 'http://www.w3.org/2005/Atom');
        if ($this->language !== '') {
            $w->writeAttribute('xml:lang', $this->language);
        }
        $w->writeElement('title', $feed['title']);
        if ($feed['description'] !== '') {
            $w->writeElement('subtitle', $feed['description']);
        }
        $w->writeElement('id', $feed['id']);
        $w->writeElement('updated', $feed['updated']->format(DateTimeInterface::ATOM));
        foreach ([['alternate', $feed['home'], 'text/html'], ['self', $feed['self'], 'application/atom+xml']] as [$rel, $href, $mime]) {
            $w->startElement('link');
            $w->writeAttribute('rel', $rel);
            $w->writeAttribute('type', $mime);
            $w->writeAttribute('href', $href);
            $w->endElement();
        }
        $w->writeElement('generator', 'Kestello');
        foreach ($items as $item) {
            $w->startElement('entry');
            $w->writeElement('title', $item['title']);
            $w->startElement('link');
            $w->writeAttribute('rel', 'alternate');
            $w->writeAttribute('href', $item['url']);
            $w->endElement();
            $w->writeElement('id', $item['id']);
            $w->writeElement('published', $item['published']->format(DateTimeInterface::ATOM));
            $w->writeElement('updated', $item['updated']->format(DateTimeInterface::ATOM));
            $w->startElement('author');
            $w->writeElement('name', $item['author'] ?? $feed['title']);
            $w->endElement();
            $w->startElement('summary');
            $w->text($item['summary']);
            $w->endElement();
            if ($item['html'] !== '') {
                $w->startElement('content');
                $w->writeAttribute('type', 'html');
                $w->text($item['html']);
                $w->endElement();
            }
            $w->endElement();
        }
        $w->endElement();

        return $w->outputMemory();
    }

    /** The RSS document, with paths under $base (the site's address, no trailing slash). */
    public function rssXml(string $base): string
    {
        [$feed, $items] = $this->resolved($base);
        $w = self::writer();
        $w->startElement('rss');
        $w->writeAttribute('version', '2.0');
        $w->writeAttribute('xmlns:atom', 'http://www.w3.org/2005/Atom');
        $w->writeAttribute('xmlns:content', 'http://purl.org/rss/1.0/modules/content/');
        $w->writeAttribute('xmlns:dc', 'http://purl.org/dc/elements/1.1/');
        $w->startElement('channel');
        $w->writeElement('title', $feed['title']);
        $w->writeElement('link', $feed['home']);
        $w->writeElement('description', $feed['description'] !== '' ? $feed['description'] : $feed['title']);
        if ($this->language !== '') {
            $w->writeElement('language', $this->language);
        }
        $w->startElement('atom:link');
        $w->writeAttribute('href', $feed['self']);
        $w->writeAttribute('rel', 'self');
        $w->writeAttribute('type', 'application/rss+xml');
        $w->endElement();
        $w->writeElement('lastBuildDate', $feed['updated']->format(DateTimeInterface::RSS));
        $w->writeElement('generator', 'Kestello');
        foreach ($items as $item) {
            $w->startElement('item');
            $w->writeElement('title', $item['title']);
            $w->writeElement('link', $item['url']);
            $w->startElement('guid');
            $w->writeAttribute('isPermaLink', 'false');
            $w->text($item['id']);
            $w->endElement();
            $w->writeElement('pubDate', $item['published']->format(DateTimeInterface::RSS));
            if ($item['author'] !== null) {
                $w->writeElement('dc:creator', $item['author']);
            }
            $w->writeElement('description', $item['summary']);
            if ($item['html'] !== '') {
                $w->startElement('content:encoded');
                $w->writeCdata(str_replace(']]>', ']]]]><![CDATA[>', $item['html']));
                $w->endElement();
            }
            $w->endElement();
        }
        $w->endElement();
        $w->endElement();

        return $w->outputMemory();
    }

    /**
     * @return array{0: array{title: string, home: string, self: string, description: string, id: string, updated: DateTimeImmutable}, 1: list<array{title: string, url: string, id: string, published: DateTimeImmutable, updated: DateTimeImmutable, summary: string, html: string, author: ?string}>}
     */
    private function resolved(string $base): array
    {
        $base = rtrim($base, '/');
        $host = (string) (parse_url($base, PHP_URL_HOST) ?: 'localhost');
        $absolute = static fn(string $address): string => str_starts_with($address, '/') ? $base . $address : $address;
        $tagPath = static fn(string $address): string => str_starts_with($address, '/') ? $address : (string) (parse_url($address, PHP_URL_PATH) ?: '/');
        $items = array_map(static fn(array $item): array => [
            'url' => $absolute($item['url']),
            'id' => $item['id'] ?? "tag:{$host},{$item['published']->format('Y-m-d')}:" . $tagPath($item['url']),
        ] + $item, $this->items);

        return [
            [
                'title' => $this->title,
                'home' => $absolute($this->home),
                'self' => $absolute($this->self),
                'description' => $this->description,
                'id' => $this->id ?? "tag:{$host},2000:" . $tagPath($this->self),
                'updated' => $items === [] ? new DateTimeImmutable() : max(array_column($items, 'updated')),
            ],
            $items,
        ];
    }

    private static function check(string $address): void
    {
        if (!str_starts_with($address, '/') && preg_match('#^https?://#i', $address) !== 1) {
            throw new InvalidArgumentException("A feed address is a path on this site or an http(s) address: \"{$address}\".");
        }
    }

    private static function base(): string
    {
        $cms = Cms::instance() ?? throw new \LogicException('Kestello is not booted.');

        return Seo::siteUrl($cms);
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

    private static function response(string $body, string $type): Response
    {
        return new Response($body, 200, [
            'Content-Type' => "{$type}; charset=UTF-8",
            'Cache-Control' => 'public, max-age=300',
            'X-Robots-Tag' => 'noindex',     // the feed itself isn't a page to list
        ]);
    }
}
