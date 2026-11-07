<?php

declare(strict_types=1);

namespace Kestello\Tests\Import;

use Kestello\Cms\Import\ImportException;
use Kestello\Cms\Import\WordPress\WxrReader;
use Kestello\Framework\Testing\TestCase;

/** Reading WordPress exports: every version, broken bytes, broken files. */
final class WxrReaderTest extends TestCase
{
    private const string FIXTURE = __DIR__ . '/../fixtures/wordpress/site.xml';

    /**
     * @return list<array<string, mixed>>
     */
    private static function read(string $path): array
    {
        return iterator_to_array((new WxrReader($path))->records(), false);
    }

    private function file(string $xml): string
    {
        $path = $this->tempDir() . '/export.xml';
        file_put_contents($path, $xml);

        return $path;
    }

    public function testTheFixtureIsReadRecordByRecord(): void
    {
        $records = self::read(self::FIXTURE);
        $kinds = array_count_values(array_column($records, 'kind'));

        $this->assertSame(['site' => 1, 'author' => 2, 'term' => 6, 'item' => 17], $kinds);
        $this->assertSame('site', $records[0]['kind'], 'the site comes first');
        $this->assertSame(['Old Studio', 'https://old.example.com', '1.2'], [$records[0]['title'], $records[0]['url'], $records[0]['version']]);
        $this->assertSame(['ana', 'ana@example.test', 'Ana Petrović'], [$records[1]['login'], $records[1]['email'], $records[1]['name']]);
    }

    public function testItemsCarryEverythingTheImportNeeds(): void
    {
        $items = array_column(array_filter(self::read(self::FIXTURE), static fn(array $r) => $r['kind'] === 'item'), null, 'id');

        $post = $items[41];
        $this->assertSame(['post', 'publish', 'hello-studio', 'ana'], [$post['type'], $post['status'], $post['slug'], $post['author']]);
        $this->assertSame(['2024-06-03 10:30:00', '2024-06-10 06:00:00'], [$post['date_gmt'], $post['modified_gmt']]);
        $this->assertSame('40', $post['meta']['_thumbnail_id']);
        $this->assertSame(1, $post['comments']);
        $this->assertSame([['taxonomy' => 'category', 'slug' => 'news', 'name' => 'News']], array_slice($post['terms'], 0, 1));
        $this->assertStringContainsString('<!-- wp:paragraph -->', $post['content']);

        $this->assertSame('здраво', $items[31]['slug'], 'percent-encoded slugs come back readable');
        $this->assertSame('', $items[32]['date_gmt'], 'a draft never published has no GMT date');
        $this->assertSame('Secret.', $items[34]['content'], 'characters XML forbids are dropped, not fatal');
        $this->assertSame('letmein', $items[34]['password']);
        $this->assertSame('https://old.example.com/wp-content/uploads/2024/06/studio.jpg', $items[40]['attachment_url']);
        $this->assertSame(50, $items[60]['parent']);
    }

    public function testOlderExportVersionsAndOtherPrefixesRead(): void
    {
        $xml = '<?xml version="1.0"?><rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:x="http://wordpress.org/export/1.0/" xmlns:dc="http://purl.org/dc/elements/1.1/"><channel>'
            . '<x:base_site_url>http://old.test</x:base_site_url><item><title>Old</title><x:post_id>5</x:post_id><x:post_type>post</x:post_type><x:status>publish</x:status>'
            . '<content:encoded><![CDATA[Hi]]></content:encoded><category domain="category" nicename="a">A</category></item></channel></rss>';

        $records = self::read($this->file($xml));

        $this->assertSame(['site', 'item'], array_column($records, 'kind'));
        $this->assertSame(['http://old.test', '1.0'], [$records[0]['url'], $records[0]['version']]);
        $this->assertSame([5, 'Old', 'Hi'], [$records[1]['id'], $records[1]['title'], $records[1]['content']]);
    }

    public function testFilesThatAreNotExportsAreExplained(): void
    {
        $e = $this->assertThrows(ImportException::class, fn() => self::read($this->file('<html><body>no</body></html>')));
        $this->assertStringContainsString('not a WordPress export', $e->getMessage());

        $e = $this->assertThrows(ImportException::class, fn() => self::read($this->file('<?xml version="1.0"?><rss><channel><item><title>cut off')));
        $this->assertStringContainsString('damaged near line', $e->getMessage());

        $e = $this->assertThrows(ImportException::class, fn() => self::read($this->file('<?xml version="1.0"?><rss><channel><title>x</title></channel></rss>')));
        $this->assertStringContainsString('no WordPress site address', $e->getMessage());

        $this->assertThrows(ImportException::class, fn() => self::read('/nonexistent/export.xml'));
    }

    public function testExternalEntitiesAreNeverFetched(): void
    {
        $secret = $this->tempDir() . '/secret.txt';
        file_put_contents($secret, 'SECRET');
        $xml = '<?xml version="1.0"?><!DOCTYPE rss [<!ENTITY x SYSTEM "file://' . $secret . '">]><rss xmlns:wp="http://wordpress.org/export/1.2/"><channel>'
            . '<wp:base_site_url>https://old.test</wp:base_site_url><item><title>&x;</title><wp:post_id>1</wp:post_id></item></channel></rss>';

        try {
            $records = self::read($this->file($xml));
            $this->assertStringNotContainsString('SECRET', (string) ($records[1]['title'] ?? ''));
        } catch (ImportException) {
            $this->assertTrue(true);   // refusing the file is fine too
        }
    }
}
