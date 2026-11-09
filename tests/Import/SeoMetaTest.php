<?php

declare(strict_types=1);

namespace Kestello\Tests\Import;

use Kestello\Cms\Import\WordPress\SeoMeta;
use Kestello\Framework\Testing\TestCase;

/** What WordPress SEO plugins keep on a post, read from the export. */
final class SeoMetaTest extends TestCase
{
    public function testNothingWhenNoPluginLeftAnything(): void
    {
        $this->assertNull(SeoMeta::read(['_thumbnail_id' => '5', '_yoast_wpseo_title' => '  '], 'Rye', 'Bakery'));
    }

    public function testYoastTitlesKeepOnlyWhatBelongsToThePost(): void
    {
        $this->assertNull(SeoMeta::title('%%title%% %%sep%% %%sitename%%', 'Rye'), 'the default template: the title pattern does this already');
        $this->assertNull(SeoMeta::title('%%title%% %%page%% %%sep%% %%sitename%%', 'Rye'));
        $this->assertSame('Rye bread in three days', SeoMeta::title('Rye bread in three days %%sep%% %%sitename%%', 'Rye'));
        $this->assertSame('Rye - our recipe', SeoMeta::title('%%title%% %%sep%% our recipe %%sep%% %%sitename%%', 'Rye'));
        $this->assertSame('Rye guide', SeoMeta::title('%%title%% guide %%primary_category%% | %%sitename%%', 'Rye'), 'variables that depend on the old site are left out');
        $this->assertNull(SeoMeta::title('%%sitename%%', 'Rye'));
        $this->assertSame('Tom & Jerry', SeoMeta::title('Tom &amp; Jerry <b>%%sep%%</b>', 'Rye'), 'entities and tags');
    }

    public function testRankMathAndPercentSigns(): void
    {
        $this->assertNull(SeoMeta::title('%title% %sep% %sitename%', 'Rye'));
        $this->assertSame('Save 50% now, 20% later', SeoMeta::description('Save 50% now, 20% later', 'Rye', 'Bakery'), 'a percent sign is not a variable');
        $this->assertSame('Rye from Bakery', SeoMeta::description('%title% from %sitename% %excerpt%', 'Rye', 'Bakery'));
        $this->assertNull(SeoMeta::description('%%excerpt%%', 'Rye', 'Bakery'), 'only the excerpt: used here anyway');
    }

    public function testEverythingAPluginKeeps(): void
    {
        $seo = SeoMeta::read([
            '_yoast_wpseo_title' => 'Rye bread in three days %%sep%% %%sitename%%',
            '_yoast_wpseo_metadesc' => 'How %%title%% is made at %%sitename%%.',
            '_yoast_wpseo_meta-robots-noindex' => '1',
            '_yoast_wpseo_canonical' => 'https://medium.com/@ana/rye',
            '_yoast_wpseo_opengraph-image-id' => '61',
            '_yoast_wpseo_opengraph-image' => 'https://old.test/wp-content/uploads/rye.jpg',
        ], 'Rye', 'Bakery');
        $this->assertSame([
            'source' => 'Yoast SEO', 'title' => 'Rye bread in three days', 'description' => 'How Rye is made at Bakery.', 'noindex' => true,
            'canonical' => 'https://medium.com/@ana/rye', 'image_id' => 61, 'image_url' => 'https://old.test/wp-content/uploads/rye.jpg',
        ], $seo);
        $this->assertFalse(SeoMeta::read(['_yoast_wpseo_meta-robots-noindex' => '2'], 'Rye', 'Bakery')['noindex'] ?? true, '2 means "index"');
    }

    public function testRankMathRobotsAreReadAsTextNeverUnserialized(): void
    {
        $this->assertTrue(SeoMeta::read(['rank_math_robots' => 'a:2:{i:0;s:7:"noindex";i:1;s:8:"nofollow";}'], 'Rye', 'Bakery')['noindex']);
        $this->assertFalse(SeoMeta::read(['rank_math_robots' => 'a:1:{i:0;s:5:"index";}'], 'Rye', 'Bakery')['noindex']);
        // a crafted value that would build an object if it were unserialized
        $evil = 'O:8:"stdClass":1:{s:1:"x";s:7:"noindex";}';
        $this->assertSame('Rank Math', SeoMeta::read(['rank_math_robots' => $evil], 'Rye', 'Bakery')['source']);
        $source = (string) file_get_contents((string) (new \ReflectionClass(SeoMeta::class))->getFileName());
        $this->assertSame(0, preg_match('/\bunserialize\s*\(/', (string) preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $source)), 'never unserialize what an export holds');
    }

    public function testOnlyWebAddressesComeOver(): void
    {
        foreach (['javascript:alert(1)', 'data:text/html,x', '//evil.test/x', 'https://user:pw@evil.test/', "https://a.test/\nb", '/relative'] as $bad) {
            $seo = SeoMeta::read(['_yoast_wpseo_canonical' => $bad, '_yoast_wpseo_opengraph-image' => $bad], 'Rye', 'Bakery');
            $this->assertSame([null, null], [$seo['canonical'], $seo['image_url']], $bad);
        }
        $this->assertSame(0, SeoMeta::read(['_yoast_wpseo_opengraph-image-id' => '12abc'], 'Rye', 'Bakery')['image_id']);
    }

    public function testTheFirstPluginWinsAndOthersFillIn(): void
    {
        $seo = SeoMeta::read(['_yoast_wpseo_metadesc' => 'From Yoast.', 'rank_math_title' => 'Rank Math title', 'rank_math_description' => 'From Rank Math.', '_seopress_robots_index' => 'yes'], 'Rye', 'Bakery');
        $this->assertSame(['Yoast SEO', 'Rank Math title', 'From Yoast.', true], [$seo['source'], $seo['title'], $seo['description'], $seo['noindex']]);
        $this->assertSame('All in One SEO', SeoMeta::read(['_aioseop_noindex' => 'on'], 'Rye', 'Bakery')['source']);
    }

    public function testLengthsAreCapped(): void
    {
        $this->assertSame(150, mb_strlen((string) SeoMeta::title(str_repeat('é', 400), 'Rye')));
        $this->assertSame(300, mb_strlen((string) SeoMeta::description(str_repeat('é', 400), 'Rye', 'Bakery')));
    }
}
