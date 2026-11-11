<?php

declare(strict_types=1);

namespace Kestello\Cms\Auth;

use Closure;
use Kestello\Cms\Notifications\MailMessage;

/**
 * How core's account emails go out: in the notification layout
 * (MailMessage), as HTML with a plain-text part, after the response.
 *
 * @internal
 */
final class AccountMail
{
    /**
     * Send after the response, so a slow mail server can't tell anyone which
     * addresses have accounts. A refused message is logged as $failure.
     *
     * @param Closure(string $to, string $subject, string $html, string $text): bool $mail
     */
    public static function send(Closure $mail, string $to, MailMessage $message, string $siteName, string $failure): void
    {
        $subject = $message->subject;
        $html = $message->render($siteName);
        $text = $message->text($siteName);
        \Kestello\Cms\System\WebRunner::defer(static function () use ($mail, $to, $subject, $html, $text, $failure): void {
            if (!$mail($to, $subject, $html, $text)) {
                error_log('[kestello] ' . $failure);
            }
        });
    }
}
