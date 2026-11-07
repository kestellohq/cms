<?php

declare(strict_types=1);

namespace Kestello\Tests\Cms;

use Kestello\Cms\Site\SeoTitle;
use Kestello\Framework\Testing\TestCase;

/** Titles from the site's title pattern. The browser runs the same cases: tests/js/document-title.test.mjs. */
final class SeoTitleTest extends TestCase
{
    public function testPlaceholdersAreFilledIn(): void
    {
        $this->assertSame('Rye - Bakery', SeoTitle::format(SeoTitle::PAGE, 'Rye', 'Bakery'));
        $this->assertSame('Rye | Bakery', SeoTitle::format(SeoTitle::PAGE, 'Rye', 'Bakery', '|'));
        $this->assertSame('Bakery · Bread, daily.', SeoTitle::format('{site} {sep} {tagline}', '', 'Bakery', '·', 'Bread, daily.'));
        $this->assertSame('Read: Rye', SeoTitle::format('Read: {title}', 'Rye', 'Bakery'), 'a pattern without the site name');
    }

    public function testAnEmptyPlaceholderTakesItsSeparatorWithIt(): void
    {
        $this->assertSame('Bakery', SeoTitle::format('{site} {sep} {tagline}', '', 'Bakery', '-', ''));
        $this->assertSame('Bakery', SeoTitle::format('{title} {sep} {site}', '', 'Bakery'));
        $this->assertSame('Rye - Bakery', SeoTitle::format('{title} {sep} {tagline} {sep} {site}', 'Rye', 'Bakery'), 'one separator, not two');
        $this->assertSame('Rye', SeoTitle::format('{sep}', 'Rye', 'Bakery'), 'nothing left: the title');
    }

    public function testATitleIsNeverReadAsAPattern(): void
    {
        $this->assertSame('Why {site} matters - Bakery', SeoTitle::format(SeoTitle::PAGE, 'Why {site} matters', 'Bakery'));
        $this->assertSame('A-B - Bakery', SeoTitle::format(SeoTitle::PAGE, 'A-B', 'Bakery'), 'a separator inside the title stays');
    }
}
