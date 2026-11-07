<?php

declare(strict_types=1);

namespace Kestello\Tests\Import;

use Kestello\Cms\Import\WordPress\Autop;
use Kestello\Cms\Import\WordPress\BlockParser;
use Kestello\Cms\Import\WordPress\OldSite;
use Kestello\Cms\Import\WordPress\Shortcodes;
use Kestello\Framework\Testing\TestCase;

/** The pieces that read WordPress content the way WordPress does. */
final class BlockParserTest extends TestCase
{
    /**
     * @param list<array<string, mixed>> $blocks
     * @return list<string>
     */
    private static function names(array $blocks, int $depth = 0): array
    {
        $out = [];
        foreach ($blocks as $b) {
            $out[] = str_repeat('-', $depth) . ($b['name'] ?? 'html');
            array_push($out, ...self::names($b['innerBlocks'], $depth + 1));
        }

        return $out;
    }

    public function testBlocksNestAsInWordPress(): void
    {
        $blocks = BlockParser::parse("<!-- wp:paragraph -->\n<p>a</p>\n<!-- /wp:paragraph -->\nloose\n<!-- wp:columns --><div><!-- wp:column --><div><!-- wp:image {\"id\":4} --><img><!-- /wp:image --></div><!-- /wp:column --></div><!-- /wp:columns --><!-- wp:latest-posts {\"n\":3} /-->");

        $this->assertSame(['core/paragraph', 'html', 'core/columns', '-core/column', '--core/image', 'core/latest-posts'], self::names($blocks));
        $this->assertSame(['id' => 4], $blocks[2]['innerBlocks'][0]['innerBlocks'][0]['attrs']);
        $this->assertSame(['<div>', null, '</div>'], $blocks[2]['innerContent']);
        $this->assertSame(['n' => 3], $blocks[3]['attrs']);
    }

    public function testBrokenMarkupKeepsItsText(): void
    {
        $this->assertSame(['html', 'core/group', '-core/paragraph'], self::names(BlockParser::parse('before<!-- wp:group --><div><!-- wp:paragraph --><p>open')));
        $this->assertSame('<p>x</p><!-- /wp:paragraph --><p>y</p>', BlockParser::parse('<p>x</p><!-- /wp:paragraph --><p>y</p>')[0]['innerHTML']);
        $this->assertSame([], BlockParser::parse('<!-- wp:image {not json} --><img><!-- /wp:image -->')[0]['attrs']);
        $this->assertSame('jetpack/form', BlockParser::parse('<!-- wp:jetpack/form --><p>x</p><!-- /wp:jetpack/form -->')[0]['name']);
        $this->assertSame([], BlockParser::parse(''));
        $this->assertFalse(BlockParser::hasBlocks('classic <b>text</b>'));
    }

    public function testClassicTextGetsWordPressParagraphs(): void
    {
        $this->assertSame("<p>One<br />\ntwo</p>\n<p>Three</p>", Autop::apply("One\ntwo\n\nThree"));
        $this->assertSame("<h3>Head</h3>\n<ul>\n<li>A</li>\n</ul>\n<p>After</p>", Autop::apply("<h3>Head</h3>\n<ul>\n<li>A</li>\n</ul>\nAfter"));
        $this->assertSame("<pre>a\n\nb</pre>", Autop::apply("<pre>a\n\nb</pre>"), 'pre keeps its blank lines');
        $this->assertSame('', Autop::apply("  \n "));
    }

    public function testShortcodesAreFoundAsWordPressFindsThem(): void
    {
        $seen = [];
        $out = Shortcodes::replace('a [gallery ids="1,2" columns=3] b [[gallery]] c [caption id="x"]<img>text[/caption]', ['gallery', 'caption'], function (string $name, array $atts, string $inner) use (&$seen): string {
            $seen[] = [$name, $atts, $inner];
            return '#';
        });

        $this->assertSame('a # b [gallery] c #', $out);
        $this->assertSame([['gallery', ['ids' => '1,2', 'columns' => '3'], ''], ['caption', ['id' => 'x'], '<img>text']], $seen);
        $this->assertSame(['contact-form-7' => 1, 'box' => 1], Shortcodes::names('[contact-form-7 id="1"] see [1] and [sic] [box]x[/box]'));
        $this->assertSame(['a', 'b' => 'c d', 'e'], Shortcodes::attributes("a b='c d' \"e\""));
    }

    public function testOldAddressesAreRecognised(): void
    {
        $site = new OldSite('https://www.old.example.com');

        $this->assertTrue($site->owns('http://old.example.com/x'));
        $this->assertTrue($site->owns('/relative'));
        $this->assertFalse($site->owns('https://elsewhere.test/x'));
        $this->assertFalse($site->owns('#top'));
        $this->assertSame('/2024/06/hello', $site->path('https://old.example.com/2024/06/hello/?utm=x#c'));
        $this->assertSame('/%D0%B7%D0%B4', $site->path('https://old.example.com/%d0%b7%d0%b4/'), 'one spelling, as browsers send it');
        $this->assertNull($site->path('https://old.example.com/'));
        $this->assertSame(12, $site->queryId('https://old.example.com/?page_id=12'));
        $this->assertSame(7, $site->queryId('/?p=7&amp;x=1'));
        $this->assertNull($site->queryId('https://other.test/?p=7'));
        foreach (['photo.jpg', 'photo-300x200.jpg', 'photo-scaled.jpg', 'Photo-1024x768-scaled.JPG', 'photo-e1712345678901.jpg'] as $name) {
            $this->assertSame('2024/06/photo.jpg', $site->fileKey('https://old.example.com/wp-content/uploads/2024/06/' . $name), $name);
        }
        $this->assertNull($site->fileKey('https://old.example.com/wp-content/themes/x.jpg'));
        $this->assertSame(OldSite::key('http://old.example.com/'), OldSite::key('https://www.old.example.com'));
    }

    public function testTheOldHomePageAlsoInAFolder(): void
    {
        $root = new OldSite('https://old.example.com');
        $this->assertTrue($root->isHome('https://www.old.example.com/'));
        $this->assertTrue($root->isHome('/?utm=x'));
        $this->assertFalse($root->isHome('https://old.example.com/' . str_repeat('%D0%B7', 120) . '/'), 'a long address is not the home page');
        $this->assertFalse($root->isHome('https://elsewhere.test/'));

        $blog = new OldSite('https://example.com/blog');
        $this->assertTrue($blog->isHome('https://example.com/blog/'));
        $this->assertFalse($blog->isHome('https://example.com/'));
    }

    public function testFilesOnAnotherHostAreTheSitesOwn(): void
    {
        $site = new OldSite('https://blog.example.com', ['wpthemetestdata.files.wordpress.com', 'blog.example.com']);

        $this->assertSame(['wpthemetestdata.files.wordpress.com'], $site->fileHosts());
        $this->assertSame('2008/06/canola2.jpg', $site->fileKey('https://wpthemetestdata.files.wordpress.com/2008/06/canola2-300x200.jpg'));
        $this->assertSame('2024/01/a.png', $site->fileKey('//wpthemetestdata.files.wordpress.com/wp-content/uploads/2024/01/a.png'));
        $this->assertNull($site->fileKey('https://elsewhere.test/2008/06/canola2.jpg'));
        $this->assertFalse($site->owns('https://wpthemetestdata.files.wordpress.com/2008/06/canola2.jpg'), 'files there; not pages');
        $this->assertSame(['cdn.example.net'], $site->hostsOf(['https://blog.example.com/wp-content/uploads/x.jpg', 'https://cdn.example.net/x.jpg', 'https://www.cdn.example.net/y.jpg']));
    }
}
