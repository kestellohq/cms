<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Settings;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Mail\MailBrand;
use Kestello\Cms\Notifications\MailMessage;
use Kestello\Framework\Mail\Mailer;
use Kestello\Framework\Settings\Settings;
use Kestello\Support\Translator;

/** Send a test email with the saved mail settings, in the site's language and email layout. */
final class SendTestEmail extends Endpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/settings/mail/test');
    }
    public function capability(): ?string
    {
        return 'settings.manage';
    }
    public function scopes(): array
    {
        return ['settings:write'];
    }
    public function rateLimit(): ?array
    {
        return [5, 600];
    }
    public function summary(): string
    {
        return 'Send a test email';
    }
    public function tags(): array
    {
        return ['settings'];
    }

    public function body(): ?Schema
    {
        return Schema::object(['to' => Schema::string()->max(255)->format('email')]);
    }

    public function handle(ApiRequest $request): mixed
    {
        Settings::getInstance()->reload();
        $mailer = new Mailer();
        $brand = MailBrand::site();
        $message = self::message($brand->name);
        if (!$mailer->send((string) $request->input('to'), $message->subject, $message->render($brand), [], $message->text($brand))) {
            throw ApiProblem::validation(['to' => ['The email could not be sent. Check the mail settings and the server log.']], 'Sending failed.');
        }

        return ['sent' => true, 'via' => $mailer->isSmtpConfigured() ? 'smtp' : 'php-mail'];
    }

    /** The test email itself. */
    public static function message(string $site): MailMessage
    {
        return MailMessage::make(Translator::get('Test email from {site}', ['site' => $site]))
            ->heading(Translator::get('Your email settings work'))
            ->paragraph(Translator::get('This is a test email from {site}. It reached you, so the site can send email with the settings you saved.', ['site' => $site]))
            ->note(Translator::get('Someone sent this from Settings → Mail on {site}.', ['site' => $site]));
    }
}
