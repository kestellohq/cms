<?php

declare(strict_types=1);

namespace Kestello\Tests\Content;

use Kestello\Cms\Media\Image\FormatNames;
use Kestello\Framework\Testing\TestCase;

/** Image formats in messages are written as people write them, never "WEBP AND AVIF". */
final class FormatNamesTest extends TestCase
{
    public function testASentenceJoinsTheLastWithAnd(): void
    {
        $this->assertSame('WebP and AVIF', FormatNames::sentence(['webp', 'avif']));
        $this->assertSame('WebP', FormatNames::sentence(['webp']));
        $this->assertSame('JPEG, WebP and AVIF', FormatNames::sentence(['jpg', 'webp', 'avif']));
        $this->assertSame('', FormatNames::sentence([]));
    }

    public function testAListIsCommaSeparated(): void
    {
        $this->assertSame('WebP, AVIF', FormatNames::list(['webp', 'avif']));
    }

    public function testAnUnknownFormatIsInCapitals(): void
    {
        $this->assertSame('HEIC', FormatNames::name('heic'));
        $this->assertSame('WebP', FormatNames::name('WEBP'));
    }
}
