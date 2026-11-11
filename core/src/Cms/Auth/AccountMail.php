<?php

declare(strict_types=1);

namespace Kestello\Cms\Auth;

use Closure;
use Kestello\Cms\Cms;
use Kestello\Cms\Mail\EmailRegistry;

/**
 * How core's account emails go out: the named email built and rendered
 * now (EmailRegistry::prepare, so plugins see it while the request is
 * still theirs), handed to the mail server after the response.
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
     * @param array<string, mixed> $data the named email's values
     */
    public static function send(Closure $mail, string $id, string $to, array $data, string $siteName, string $failure): void
    {
        $emails = Cms::instance()->emails ?? new EmailRegistry();
        $email = $emails->prepare($id, $to, $data, $siteName);
        if ($email === null) {
            return;   // stopped on purpose by a MailSending listener
        }
        \Kestello\Cms\System\WebRunner::defer(static function () use ($emails, $email, $mail, $failure): void {
            if (!$emails->deliver($email, $mail)) {
                error_log('[kestello] ' . $failure);
            }
        });
    }
}
