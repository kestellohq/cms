<?php

declare(strict_types=1);

namespace Kestello\Tests\Content;

use DateTimeImmutable;
use InvalidArgumentException;
use Kestello\Cms\Site\Feed;
use Kestello\Framework\Testing\TestCase;

/**
 * A feed of a plugin's own items: paths go under the site's address, ids are lasting tag: URIs,
 * and text never becomes markup.
 */
final class FeedTest extends TestCase
{
    public function testAtomPutsPathsUnderTheSiteAndMakesLastingIds(): void
    {
        $feed = (new Feed('Security advisories', '/advisories', '/advisories.atom', 'Every advisory.'))
            ->add('Stored XSS in <Events>', '/advisories/KSA-2027-0001', new DateTimeImmutable('2027-01-02T10:00:00Z'), summary: 'Before 1.2.0 & after 1.0.0.')
            ->add('Elsewhere', 'https://example.org/a', new DateTimeImmutable('2027-01-01T10:00:00Z'), new DateTimeImmutable('2027-01-03T10:00:00Z'), html: '<p>Fixed.</p>', author: 'Acme', id: 'urn:acme:1');

        $xml = simplexml_load_string($feed->atomXml('https://kestello.com/'));

        $this->assertInstanceOf(\SimpleXMLElement::class, $xml);
        $this->assertSame('Security advisories', (string) $xml->title);
        $this->assertSame('Every advisory.', (string) $xml->subtitle);
        $this->assertSame('tag:kestello.com,2000:/advisories.atom', (string) $xml->id);
        $this->assertSame('2027-01-03T10:00:00+00:00', (string) $xml->updated, 'the newest update');
        $this->assertSame(['https://kestello.com/advisories', 'https://kestello.com/advisories.atom'], [(string) $xml->link[0]['href'], (string) $xml->link[1]['href']]);

        $first = $xml->entry[0];
        $this->assertSame('Stored XSS in <Events>', (string) $first->title, 'text stays text');
        $this->assertSame('https://kestello.com/advisories/KSA-2027-0001', (string) $first->link['href']);
        $this->assertSame('tag:kestello.com,2027-01-02:/advisories/KSA-2027-0001', (string) $first->id);
        $this->assertSame('Security advisories', (string) $first->author->name, 'the feed when no author is given');
        $this->assertSame('Before 1.2.0 & after 1.0.0.', (string) $first->summary);
        $this->assertCount(0, $first->content);

        $second = $xml->entry[1];
        $this->assertSame(['https://example.org/a', 'urn:acme:1', 'Acme', '<p>Fixed.</p>'], [(string) $second->link['href'], (string) $second->id, (string) $second->author->name, (string) $second->content]);
    }

    public function testRssCarriesTheSameItems(): void
    {
        $feed = (new Feed('Releases', '/releases', '/releases.rss'))
            ->add('1.0.0', '/releases/1.0.0', new DateTimeImmutable('2027-01-02T10:00:00Z'), html: '<p>A ]]> in the text</p>');

        $xml = simplexml_load_string($feed->rssXml('https://example.com'));

        $this->assertInstanceOf(\SimpleXMLElement::class, $xml);
        $this->assertSame('Releases', (string) $xml->channel->description, 'the title when there is no description');
        $item = $xml->channel->item[0];
        $this->assertSame(['1.0.0', 'https://example.com/releases/1.0.0', 'tag:example.com,2027-01-02:/releases/1.0.0'], [(string) $item->title, (string) $item->link, (string) $item->guid]);
        $this->assertSame('<p>A ]]> in the text</p>', (string) $item->children('http://purl.org/rss/1.0/modules/content/')->encoded, 'a CDATA end inside the html is kept');
    }

    public function testAnEmptyFeedIsStillAFeed(): void
    {
        $xml = simplexml_load_string((new Feed('Nothing yet', '/news', '/news.atom'))->atomXml('https://example.com'));

        $this->assertInstanceOf(\SimpleXMLElement::class, $xml);
        $this->assertCount(0, $xml->entry);
    }

    public function testAddressesArePathsOrWebAddresses(): void
    {
        $this->assertThrows(InvalidArgumentException::class, fn() => new Feed('x', 'news', '/news.atom'));
        $this->assertThrows(InvalidArgumentException::class, fn() => (new Feed('x', '/news', '/news.atom'))->add('y', 'javascript:alert(1)', new DateTimeImmutable()));
    }
}
