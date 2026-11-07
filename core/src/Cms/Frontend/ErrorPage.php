<?php

declare(strict_types=1);

namespace Kestello\Cms\Frontend;

use Kestello\Framework\Core\ErrorWords;

/**
 * What an error page (@core/errors/Error) says to visitors, in the site's
 * language: a heading (also the tab's title), a sentence on what to do,
 * and the label of the link home (ErrorWords). Translated here and handed
 * to the page as props, as core's site blocks get their text.
 */
final class ErrorPage
{
    /**
     * @param string $heading a caller's own heading instead of the status's (an expired preview link)
     * @param string $message a caller's own sentence instead of the status's
     * @return array{status: int, title: string, heading: string, message: string, home: string}
     */
    public static function props(int $status, string $heading = '', string $message = ''): array
    {
        [$standardHeading, $standardMessage] = ErrorWords::for($status);
        $heading = $heading !== '' ? $heading : $standardHeading;

        return [
            'status' => $status,
            'title' => $heading,
            'heading' => $heading,
            'message' => $message !== '' ? $message : $standardMessage,
            'home' => ErrorWords::home(),
        ];
    }
}
