<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Emails;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Mail\MailBrand;
use Kestello\Cms\Notifications\MailMessage;

/**
 * An email as it would arrive, with sample values and the site's look: with
 * the draft in the body, or with what is saved for the language. Nothing is
 * sent or saved.
 */
final class PreviewEmail extends EmailEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/emails/{id}/preview')->where('id', self::ID);
    }
    public function summary(): string
    {
        return 'Preview an email, saved or as a draft';
    }
    public function body(): ?Schema
    {
        return self::editBody(false);
    }
    public function response(): ?Schema
    {
        return Schema::object(['subject' => Schema::string(), 'html' => Schema::string(), 'text' => Schema::string()]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $id = self::email($request)['id'];
        $message = self::message($request, $id);
        $brand = MailBrand::site();

        return ['subject' => $message->subject, 'html' => $message->render($brand, $id), 'text' => $message->text($brand)];
    }

    /** The email with sample values in the body's language, with its draft or what is saved. */
    public static function message(ApiRequest $request, string $id): MailMessage
    {
        return self::in(self::locale($request->input('locale')), static fn(): MailMessage => self::emails()->sample($id, self::draft($request, $id)));
    }
}
