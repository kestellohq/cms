<?php

declare(strict_types=1);

namespace Kestello\Tests\I18n;

use Kestello\Cms\I18n\ContentLanguages;
use Kestello\Framework\Testing\TestCase;
use Kestello\Support\Translator;

/** The rules behind /es/… addresses, without a site. */
final class ContentLanguagesTest extends TestCase
{
    public function testTheStoredListIsCleanedUp(): void
    {
        $this->assertSame(['es', 'pt-br'], ContentLanguages::parse('es, pt_BR ,en,es', 'en'), 'the main language and duplicates are dropped, codes are written as in addresses');
        $this->assertSame(['es'], ContentLanguages::parse(['es', 'not a code', 7, '../x'], 'en'), 'malformed codes are dropped');
        $this->assertSame([], ContentLanguages::parse(null, 'en'));
        $this->assertSame(['en'], ContentLanguages::parse('en,sr', 'sr'), 'a site in Serbian can add English');
        $this->assertCount(ContentLanguages::MAX, ContentLanguages::parse(array_map(static fn(int $i) => 'x' . chr(97 + intdiv($i, 26)) . chr(97 + $i % 26), range(0, 30)), 'en'));
    }

    public function testCodesAreWrittenAsInAddresses(): void
    {
        $this->assertSame('sr-latn', ContentLanguages::code('sr_Latn'));
        $this->assertSame('es', ContentLanguages::code(' ES '));
        $this->assertSame('', ContentLanguages::code('español'));
        $this->assertSame('', ContentLanguages::code(''));
    }

    public function testAPathIsSplitIntoItsLanguageAndTheRest(): void
    {
        $this->assertSame(['es', '/about/team'], ContentLanguages::split('/es/about/team', ['es']));
        $this->assertSame(['es', '/'], ContentLanguages::split('/es', ['es']));
        $this->assertSame(['es', '/'], ContentLanguages::split('/es/', ['es']));
        $this->assertSame(['', '/estudio'], ContentLanguages::split('/estudio', ['es']), 'a page whose slug starts with the code');
        $this->assertSame(['', '/fr/about'], ContentLanguages::split('/fr/about', ['es']), 'a language the site does not have');
        $this->assertSame(['', '/'], ContentLanguages::split('/', ['es']));
    }

    public function testAPathIsAddressedInALanguage(): void
    {
        $this->assertSame('/es/about', ContentLanguages::localize('/about', 'es'));
        $this->assertSame('/es', ContentLanguages::localize('/', 'es'));
        $this->assertSame('/about', ContentLanguages::localize('/about', ''));
        $this->assertSame('/', ContentLanguages::localize('/', ''));
    }

    public function testWorkRunsInALanguageAndComesBack(): void
    {
        $before = Translator::locale();
        $seen = ContentLanguages::within('es', static fn() => [ContentLanguages::current(), Translator::locale()]);
        $this->assertSame(['es', 'es'], $seen);
        $this->assertSame(['', $before], [ContentLanguages::current(), Translator::locale()]);
        try {
            ContentLanguages::within('es', static fn() => throw new \RuntimeException('boom'));
        } catch (\RuntimeException) {
        }
        $this->assertSame('', ContentLanguages::current(), 'restored after an error too');
    }
}
