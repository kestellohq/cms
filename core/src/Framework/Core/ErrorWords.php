<?php

declare(strict_types=1);

namespace Kestello\Framework\Core;

use Kestello\Support\Translator;

/**
 * What an error page says to visitors for each status, in the current
 * language: the site's own error page (Kestello\Cms\Frontend\ErrorPage)
 * and the plain one ErrorHandler shows when an error escapes before the
 * site can draw a page. One set of words, so both say the same.
 */
final class ErrorWords
{
    /** @return array{string, string} the heading, and a sentence on what to do */
    public static function for(int $status): array
    {
        return match ($status) {
            403 => [Translator::get('No access'), Translator::get('You don’t have permission to see this page.')],
            404 => [Translator::get('Page not found'), Translator::get('There is no page at this address. It may have moved, or the address may be mistyped.')],
            419 => [Translator::get('This page expired'), Translator::get('Reload the page and try again.')],
            429 => [Translator::get('Too many requests'), Translator::get('Wait a moment, then try again.')],
            500 => [Translator::get('Something went wrong'), Translator::get('The site couldn’t show this page. Try again in a little while.')],
            503 => [Translator::get('Back soon'), Translator::get('The site is being updated. Try again in a few minutes.')],
            default => [Translator::get('Error {status}', ['status' => (string) $status]), Translator::get('The site couldn’t show this page.')],
        };
    }

    /** The label of the link home. */
    public static function home(): string
    {
        return Translator::get('Go to the home page');
    }
}
