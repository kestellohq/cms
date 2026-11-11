<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Emails;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Cms;
use Kestello\Cms\Mail\MailBrand;
use Kestello\Framework\Database\QueryBuilder;
use Kestello\Framework\Mail\Mailer;
use Kestello\Support\Translator;

/**
 * Send yourself an email with sample values, as previewed: with the draft
 * in the body, or with what is saved. It goes to your own address only.
 */
final class SendEmailTest extends EmailEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/emails/{id}/test')->where('id', self::ID);
    }
    public function summary(): string
    {
        return 'Send yourself an email to check it';
    }
    public function body(): ?Schema
    {
        return self::editBody(false);
    }
    public function rateLimit(): ?array
    {
        return [10, 600];
    }
    public function response(): ?Schema
    {
        return Schema::object(['sent' => Schema::bool(), 'to' => Schema::string()]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $id = self::email($request)['id'];
        $to = (string) (QueryBuilder::table('users')->where('id', $request->userId())->value('email') ?? '');
        if ($to === '') {
            throw ApiProblem::validation(['to' => [Translator::get('Your account has no email address to send to.')]]);
        }
        $message = PreviewEmail::message($request, $id);
        $brand = MailBrand::site();
        $subject = Translator::get('[Test] {subject}', ['subject' => $message->subject]);
        if (!self::transport()($to, $subject, $message->render($brand), $message->text($brand))) {
            throw ApiProblem::validation(['to' => [Translator::get('The email could not be sent. Check Settings → Mail and the server log.')]], Translator::get('Sending failed.'));
        }
        self::audit($request, 'email.test_sent', $id, self::locale($request->input('locale')));

        return ['sent' => true, 'to' => $to];
    }

    /** @return \Closure(string, string, string, string): bool the site's mail transport, the one account emails use */
    private static function transport(): \Closure
    {
        $container = Cms::instance()?->container;
        $mail = $container !== null && $container->has('auth.mail') ? $container->make('auth.mail') : null;

        return $mail instanceof \Closure ? $mail : static fn(string $to, string $subject, string $html, string $text): bool => (new Mailer())->send($to, $subject, $html, [], $text);
    }
}
