<?php

declare(strict_types=1);

namespace Kestello\Tests\Import;

use Kestello\Cms\Import\WordPress\ContentConverter;
use Kestello\Cms\Import\WordPress\Links;
use Kestello\Framework\Testing\TestCase;

/** WordPress content to Kestello blocks: structured when nothing is lost, HTML otherwise. */
final class ContentConverterTest extends TestCase
{
    private function converter(): ContentConverter
    {
        return new ContentConverter(new class implements Links {
            public function url(string $url): string
            {
                return str_starts_with($url, 'https://old.test/') ? '/new' . substr($url, strlen('https://old.test')) : $url;
            }

            public function media(int $attachment, string $url): ?int
            {
                if (in_array($attachment, [40, 42], true)) {
                    return $attachment + 1000;
                }
                return preg_match('#old\.test/wp-content/uploads/(studio|poster)(-\d+x\d+)?\.jpg$#', $url, $m) === 1 ? ($m[1] === 'studio' ? 1040 : 1042) : null;
            }

            public function isOld(string $url): bool
            {
                return str_starts_with($url, 'https://old.test/');
            }

            public function reusable(int $id): ?string
            {
                return match ($id) {
                    9 => '<!-- wp:heading --><h2>Shared</h2><!-- /wp:heading -->',
                    10 => '<!-- wp:paragraph --><p>Loop</p><!-- /wp:paragraph -->' . str_repeat('<!-- wp:block {"ref":10} /-->', 30),
                    default => null,
                };
            }
        });
    }

    /**
     * @return array{blocks: list<array<string, mixed>>, notes: array<string, int>}
     */
    private function convert(string $content): array
    {
        return $this->converter()->convert($content);
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @return list<string>
     */
    private static function types(array $blocks): array
    {
        return array_map(static fn(array $b) => $b['type'], $blocks);
    }

    public function testPlainBlocksBecomeStructuredBlocks(): void
    {
        $out = $this->convert('<!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Plain</h3><!-- /wp:heading -->'
            . '<!-- wp:list --><ul><!-- wp:list-item --><li>One</li><!-- /wp:list-item --><!-- wp:list-item --><li>Two &amp; more</li><!-- /wp:list-item --></ul><!-- /wp:list -->'
            . '<!-- wp:quote --><blockquote class="wp-block-quote"><!-- wp:paragraph --><p>Said</p><!-- /wp:paragraph --><cite>Ana</cite></blockquote><!-- /wp:quote -->'
            . '<!-- wp:separator {"className":"is-style-dots"} --><hr class="wp-block-separator is-style-dots"/><!-- /wp:separator -->'
            . '<!-- wp:code --><pre class="wp-block-code"><code>&lt;b&gt;</code></pre><!-- /wp:code -->'
            . '<!-- wp:spacer {"height":"120px"} --><div style="height:120px" class="wp-block-spacer"></div><!-- /wp:spacer -->');

        $this->assertSame([
            ['type' => 'core/heading', 'props' => ['text' => 'Plain', 'level' => '3'], 'children' => []],
            ['type' => 'core/list', 'props' => ['ordered' => false, 'items' => [['text' => 'One'], ['text' => 'Two & more']]], 'children' => []],
            ['type' => 'core/quote', 'props' => ['text' => 'Said', 'cite' => 'Ana'], 'children' => []],
            ['type' => 'core/separator', 'props' => ['look' => 'dots'], 'children' => []],
            ['type' => 'core/code', 'props' => ['code' => '<b>'], 'children' => []],
            ['type' => 'core/spacer', 'props' => ['size' => 'lg'], 'children' => []],
        ], $out['blocks']);
        $this->assertSame([], $out['notes']);
    }

    public function testFormattingIsNeverLost(): void
    {
        $out = $this->convert('<!-- wp:heading --><h2>A <a href="https://old.test/x/">link</a></h2><!-- /wp:heading -->'
            . '<!-- wp:list --><ul><li>Item<ul><li>Nested</li></ul></li></ul><!-- /wp:list -->'
            . '<!-- wp:table --><figure class="wp-block-table"><table><tr><td colspan="2">Merged</td></tr></table></figure><!-- /wp:table -->');

        $this->assertSame(['core/text'], self::types($out['blocks']), 'kept as HTML, merged into one text block');
        $html = $out['blocks'][0]['props']['text'];
        $this->assertStringContainsString('<h2>A <a href="/new/x/">link</a></h2>', $html, 'links point at the new site');
        $this->assertStringContainsString('<li>Nested</li>', $html);
        $this->assertStringContainsString('colspan="2"', $html);
        $this->assertStringNotContainsString('class=', $html, 'WordPress classes go');
    }

    public function testImagesBecomeMediaBlocks(): void
    {
        $out = $this->convert('<!-- wp:image {"id":40,"align":"right","linkDestination":"media"} --><figure class="wp-block-image alignright"><a href="https://old.test/wp-content/uploads/studio.jpg"><img src="https://old.test/wp-content/uploads/studio-1024x683.jpg" alt="Windows" class="wp-image-40"/></a><figcaption>The place</figcaption></figure><!-- /wp:image -->'
            . '<!-- wp:image {"id":77} --><figure class="wp-block-image"><img src="https://elsewhere.test/x.png" alt="Gone"/></figure><!-- /wp:image -->'
            . '<!-- wp:gallery {"columns":2} --><figure class="wp-block-gallery"><!-- wp:image {"id":40} --><figure><img src="https://old.test/wp-content/uploads/studio.jpg"/></figure><!-- /wp:image --><!-- wp:image {"id":42} --><figure><img src="x"/></figure><!-- /wp:image --></figure><!-- /wp:gallery -->');

        $this->assertSame(['type' => 'core/image', 'props' => ['image' => 1040, 'alt' => 'Windows', 'caption' => 'The place', 'align' => 'wrap-end', 'lightbox' => true], 'children' => []], $out['blocks'][0]);
        $this->assertSame('core/text', $out['blocks'][1]['type'], 'an image that was not imported stays as it was');
        $this->assertStringContainsString('src="https://elsewhere.test/x.png"', $out['blocks'][1]['props']['text']);
        $this->assertSame(['type' => 'core/gallery', 'props' => ['images' => [1040, 1042], 'columns' => '2'], 'children' => []], $out['blocks'][2]);
        $this->assertSame(['media-missing' => 1], $out['notes']);
    }

    public function testLayoutBlocks(): void
    {
        $out = $this->convert('<!-- wp:columns --><div class="wp-block-columns"><!-- wp:column {"width":"33%"} --><div><!-- wp:paragraph --><p>L</p><!-- /wp:paragraph --></div><!-- /wp:column --><!-- wp:column {"width":"67%"} --><div><!-- wp:paragraph --><p>R</p><!-- /wp:paragraph --></div><!-- /wp:column --></div><!-- /wp:columns -->'
            . '<!-- wp:buttons {"layout":{"justifyContent":"center"}} --><div class="wp-block-buttons"><!-- wp:button {"className":"is-style-outline"} --><div class="wp-block-button is-style-outline"><a class="wp-block-button__link" href="https://old.test/contact/" target="_blank">Write</a></div><!-- /wp:button --></div><!-- /wp:buttons -->'
            . '<!-- wp:group --><div class="wp-block-group"><!-- wp:block {"ref":9} /--></div><!-- /wp:group -->'
            . '<!-- wp:details --><details class="wp-block-details"><summary>More</summary><!-- wp:paragraph --><p>Hidden</p><!-- /wp:paragraph --></details><!-- /wp:details -->');

        $this->assertSame(['core/columns', 'core/buttons', 'core/group', 'core/details'], self::types($out['blocks']));
        $this->assertSame('1-2', $out['blocks'][0]['props']['layout']);
        $this->assertSame(['core/column', 'core/column'], self::types($out['blocks'][0]['children']));
        $this->assertSame(['align' => 'center'], $out['blocks'][1]['props']);
        $this->assertSame(['text' => 'Write', 'link' => '/new/contact/', 'variant' => 'secondary', 'new_tab' => true], $out['blocks'][1]['children'][0]['props']);
        $this->assertSame(['type' => 'core/heading', 'props' => ['text' => 'Shared', 'level' => '2'], 'children' => []], $out['blocks'][2]['children'][0], 'reusable blocks are copied in');
        $this->assertSame(['summary' => 'More', 'text' => '<p>Hidden</p>'], $out['blocks'][3]['props']);
    }

    public function testEmbeds(): void
    {
        $out = $this->convert('<!-- wp:embed {"url":"https://youtu.be/dQw4w9WgXcQ","providerNameSlug":"youtube"} --><figure><div>https://youtu.be/dQw4w9WgXcQ</div></figure><!-- /wp:embed -->'
            . '<!-- wp:core-embed/vimeo {"url":"https://vimeo.com/123"} --><figure><div>https://vimeo.com/123</div></figure><!-- /wp:core-embed/vimeo -->'
            . '<!-- wp:embed {"url":"https://twitter.com/a/status/1"} --><figure><div>https://twitter.com/a/status/1</div></figure><!-- /wp:embed -->');

        $this->assertSame(['core/embed', 'core/embed', 'core/text'], self::types($out['blocks']));
        $this->assertSame('https://youtu.be/dQw4w9WgXcQ', $out['blocks'][0]['props']['url']);
        $this->assertSame('<p><a href="https://twitter.com/a/status/1">https://twitter.com/a/status/1</a></p>', $out['blocks'][2]['props']['text']);
        $this->assertSame(['embed:twitter.com' => 1], $out['notes']);
    }

    public function testWhatCannotComeOverIsCounted(): void
    {
        $out = $this->convert('<!-- wp:latest-posts /--><!-- wp:more --><!--more--><!-- /wp:more -->'
            . '<!-- wp:jetpack/contact-form --><div><p>Write to us</p></div><!-- /wp:jetpack/contact-form -->'
            . '<!-- wp:html --><div><script>alert(1)</script><iframe src="https://maps.example/x"></iframe><p>Kept</p></div><!-- /wp:html -->'
            . '<!-- wp:paragraph --><p>[contact-form-7 id="3"]</p><!-- /wp:paragraph -->');

        $this->assertSame(['core/text'], self::types($out['blocks']));
        $html = $out['blocks'][0]['props']['text'];
        $this->assertStringNotContainsString('script', $html);
        $this->assertStringNotContainsString('iframe', $html);
        $this->assertSame([
            'block:jetpack/contact-form' => 1, 'dropped:core/latest-posts' => 1, 'dropped:core/more' => 1,
            'frame:maps.example' => 1, 'html' => 1, 'removed:script' => 1, 'shortcode:contact-form-7' => 1,
        ], $out['notes']);
    }

    public function testClassicContent(): void
    {
        $out = $this->convert("First line.\nSecond line.\n\n"
            . '<a href="https://old.test/wp-content/uploads/studio.jpg"><img class="aligncenter wp-image-40" src="https://old.test/wp-content/uploads/studio-300x200.jpg" alt="" /></a>' . "\n\n"
            . '[caption id="attachment_42" align="alignleft"]<img src="https://old.test/wp-content/uploads/poster.jpg" alt="Poster" /> Our poster[/caption]' . "\n\n"
            . "<h2>Plain heading</h2>\n\n[gallery ids=\"40,42,99\"]\n\nhttps://www.youtube.com/watch?v=dQw4w9WgXcQ\n\nThe end.");

        $this->assertSame(['core/text', 'core/image', 'core/image', 'core/heading', 'core/gallery', 'core/embed', 'core/text'], self::types($out['blocks']));
        $this->assertSame("<p>First line.<br>\nSecond line.</p>", $out['blocks'][0]['props']['text']);
        $this->assertSame(['image' => 1040, 'align' => 'center', 'lightbox' => true], $out['blocks'][1]['props']);
        $this->assertSame(['image' => 1042, 'alt' => 'Poster', 'caption' => 'Our poster', 'align' => 'wrap-start'], $out['blocks'][2]['props']);
        $this->assertSame(['images' => [1040, 1042], 'columns' => '3'], $out['blocks'][4]['props']);
        $this->assertSame(['media-missing' => 1], $out['notes']);
    }

    public function testFallbackKeepsAllTheText(): void
    {
        $blocks = $this->converter()->fallback('<!-- wp:paragraph --><p>A</p><!-- /wp:paragraph --><!-- wp:columns --><div><p>B</p></div><!-- /wp:columns -->');

        $this->assertSame([['type' => 'core/text', 'props' => ['text' => '<p>A</p><div><p>B</p></div>'], 'children' => []]], $blocks);
        $this->assertSame([], $this->converter()->fallback(''));
    }

    public function testAReusableBlockThatUsesItselfIsCopiedOnce(): void
    {
        $out = $this->convert('<!-- wp:block {"ref":10} /-->');

        $this->assertSame([['type' => 'core/text', 'props' => ['text' => '<p>Loop</p>'], 'children' => []]], $out['blocks']);
        $this->assertSame(['dropped:core/block' => 30], $out['notes']);
    }

    public function testTextTooLargeForThePatternsIsKeptWhole(): void
    {
        $huge = "<p>Intro</p>\n<p><li>" . str_repeat('a', 1_000_000) . '</p>';

        $this->assertSame($huge, \Kestello\Cms\Import\WordPress\Autop::apply($huge));
    }

    public function testSocialLinksBecomeAListOfLinks(): void
    {
        $out = $this->convert('<!-- wp:social-links --><ul class="wp-block-social-links">'
            . '<!-- wp:social-link {"url":"https://github.com/ana","service":"github"} /-->'
            . '<!-- wp:social-link {"url":"#","service":"wordpress"} /-->'
            . '<!-- wp:social-link {"url":"https://ana.example","service":"chain","label":"My site"} /-->'
            . '<!-- wp:social-link {"url":"javascript:alert(1)","service":"x"} /--></ul><!-- /wp:social-links -->'
            . '<!-- wp:social-links --><ul class="wp-block-social-links"><!-- wp:social-link {"url":"#","service":"x"} /--></ul><!-- /wp:social-links -->');

        $this->assertSame([['type' => 'core/text', 'props' => ['text' => '<ul><li><a href="https://github.com/ana">GitHub</a></li><li><a href="https://ana.example">My site</a></li></ul>'], 'children' => []]], $out['blocks']);
        $this->assertSame(['dropped:core/social-links' => 1], $out['notes'], 'only placeholders: nothing to keep');
    }

    public function testVideosNextToTextAndBehindCoversAreAccountedFor(): void
    {
        $out = $this->convert('<!-- wp:media-text {"mediaType":"video"} --><div class="wp-block-media-text"><div class="wp-block-media-text__content">'
            . '<!-- wp:paragraph --><p>Beside</p><!-- /wp:paragraph --></div><figure class="wp-block-media-text__media"><video controls src="https://cdn.test/clip.mov"></video></figure></div><!-- /wp:media-text -->'
            . '<!-- wp:cover {"url":"https://cdn.test/clip.mov","backgroundType":"video"} --><div class="wp-block-cover"><video class="wp-block-cover__video-background" src="https://cdn.test/clip.mov"></video>'
            . '<div class="wp-block-cover__inner-container"><!-- wp:paragraph --><p>Over</p><!-- /wp:paragraph --></div></div><!-- /wp:cover -->');

        $this->assertSame(['core/text', 'core/cover'], self::types($out['blocks']));
        $this->assertSame("<p><a href=\"https://cdn.test/clip.mov\">clip.mov</a></p>\n<p>Beside</p>", $out['blocks'][0]['props']['text'], 'the video, as a link, then its text');
        $this->assertSame(['cover-video' => 1], $out['notes'], 'a video on another site is not missing from the old one');
    }

    public function testImagesFromOtherSitesAreNotReportedMissing(): void
    {
        $out = $this->convert('<!-- wp:image --><figure class="wp-block-image"><img src="https://upload.wikimedia.org/a.jpg" alt="Elsewhere"/></figure><!-- /wp:image -->'
            . '<!-- wp:image --><figure class="wp-block-image"><img src="https://old.test/wp-content/uploads/lost.jpg" alt="Lost"/></figure><!-- /wp:image -->');

        $this->assertStringContainsString('src="https://upload.wikimedia.org/a.jpg"', $out['blocks'][0]['props']['text']);
        $this->assertSame(['media-missing' => 1], $out['notes'], 'only the old site\'s own file counts');
    }

    public function testALongCaptionGoesUnderTheImageWhole(): void
    {
        $long = str_repeat('A long caption. ', 40);
        $out = $this->convert('<!-- wp:image {"id":40} --><figure class="wp-block-image"><img src="https://old.test/wp-content/uploads/studio.jpg" alt="Windows"/><figcaption>' . $long . '</figcaption></figure><!-- /wp:image -->');

        $this->assertSame(['core/image', 'core/text'], self::types($out['blocks']));
        $this->assertArrayNotHasKey('caption', $out['blocks'][0]['props']);
        $this->assertSame('<p>' . trim($long) . '</p>', $out['blocks'][1]['props']['text']);
    }
}
