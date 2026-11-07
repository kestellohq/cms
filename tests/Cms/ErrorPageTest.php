<?php

declare(strict_types=1);

namespace Tests\Cms;

use Kestello\Cms\Frontend\ErrorPage;
use Kestello\Framework\Testing\TestCase;
use Kestello\Support\Translator;

/** What visitors read on an error page: plain words, in the site's language. */
final class ErrorPageTest extends TestCase
{
    protected function tearDown(): void
    {
        Translator::reset();
        parent::tearDown();
    }

    public function testEachStatusHasItsWords(): void
    {
        $page = ErrorPage::props(404);
        $this->assertSame(404, $page['status']);
        $this->assertSame('Page not found', $page['heading']);
        $this->assertSame($page['heading'], $page['title'], 'the tab says what the page says');
        $this->assertSame('Go to the home page', $page['home']);
        $headings = array_map(static fn(int $s) => ErrorPage::props($s)['heading'], [403, 404, 419, 429, 500, 503]);
        $this->assertSame(6, count(array_unique($headings)), 'each its own');
        $this->assertSame('Error 418', ErrorPage::props(418)['heading'], 'a status without words of its own');
    }

    public function testTheWordsAreInTheSiteLanguage(): void
    {
        Translator::add('core', 'sr', ['Page not found' => 'Stranica nije pronađena', 'Go to the home page' => 'Na početnu stranicu', 'Error {status}' => 'Greška {status}']);
        $page = Translator::withLocale('sr', static fn() => ErrorPage::props(404));
        $this->assertSame(['Stranica nije pronađena', 'Na početnu stranicu'], [$page['heading'], $page['home']]);
        $this->assertSame('Greška 418', Translator::withLocale('sr', static fn() => ErrorPage::props(418))['heading']);
    }

    public function testACallerMaySayMore(): void
    {
        $page = ErrorPage::props(404, heading: 'Preview not available', message: 'Ask for a new link.');
        $this->assertSame(['Preview not available', 'Preview not available', 'Ask for a new link.'], [$page['heading'], $page['title'], $page['message']]);
    }
}
