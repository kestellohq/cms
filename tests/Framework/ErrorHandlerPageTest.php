<?php

declare(strict_types=1);

namespace Kestello\Tests\Framework;

use Kestello\Framework\Core\ErrorHandler;
use Kestello\Framework\Core\ErrorWords;
use Kestello\Framework\Testing\TestCase;
use Kestello\Support\Translator;

/** The page shown when an error escapes before the site can draw its own: the same words, in the site's language. */
final class ErrorHandlerPageTest extends TestCase
{
    protected function tearDown(): void
    {
        Translator::reset();
        parent::tearDown();
    }

    public function testItSaysWhatTheSitesErrorPageSays(): void
    {
        $html = (new ErrorHandler())->productionPage(500);
        [$heading, $message] = ErrorWords::for(500);
        $this->assertStringContainsString("<h1>{$heading}</h1>", $html);
        $this->assertStringContainsString(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'), $html);
        $this->assertStringContainsString('>' . ErrorWords::home() . '</a>', $html);
        $this->assertStringContainsString('<html lang="en"', $html);
    }

    public function testItIsInTheSiteLanguage(): void
    {
        Translator::add('core', 'sr', ['Something went wrong' => 'Nešto nije u redu <b>']);
        $html = Translator::withLocale('sr', static fn() => (new ErrorHandler())->productionPage(500));
        $this->assertStringContainsString('<html lang="sr"', $html);
        $this->assertStringContainsString('Nešto nije u redu &lt;b&gt;', $html, 'translations are escaped too');
    }
}
