<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Auth;

use Closure;
use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Auth\AccountDeletion;
use Kestello\Cms\Auth\Auth;
use Kestello\Cms\Auth\AuthModule;
use Kestello\Cms\Cms;
use Kestello\Cms\Notifications\MailMessage;
use Kestello\Cms\System\WebRunner;
use Kestello\Framework\Database\QueryBuilder;
use Kestello\Support\Translator;

/**
 * Delete your own account. In a signed-in browser only, after a recent sign-in. The same as
 * deleting someone from People: sessions and access tokens end, content stays without its
 * author. Refused for the last administrator, or when a plugin refuses (UserDeleting). The
 * address gets an email saying the account is gone, and you are signed out.
 */
final class DeleteMyAccount extends Endpoint
{
    use SessionOnly;

    public function route(): ApiRoute
    {
        return ApiRoute::delete('/me');
    }
    public function capability(): ?string
    {
        return 'profile.manage';
    }
    public function scopes(): array
    {
        return ['profile'];
    }
    public function requiresRecentAuth(): bool
    {
        return true;
    }
    public function rateLimit(): ?array
    {
        return [5, 3600];
    }
    public function summary(): string
    {
        return 'Delete your account';
    }
    public function tags(): array
    {
        return ['me'];
    }

    public function handle(ApiRequest $request): mixed
    {
        $cms = Cms::instance() ?? throw new \RuntimeException('Not booted.');
        if ($cms->config->get('app.demo', false)) {
            throw ApiProblem::forbidden('Accounts cannot be deleted on this site.');
        }
        $id = (int) $request->userId();
        $user = QueryBuilder::table('users')->where('id', $id)->first() ?? throw ApiProblem::unauthenticated();
        $refusal = AccountDeletion::refusal($cms, $user, 'self', $id);
        if ($refusal !== null) {
            throw ApiProblem::conflict($refusal);
        }

        // sign this browser out first, so the ending request doesn't write the session back
        Auth::getInstance()?->logout();
        AccountDeletion::delete($cms, $id, 'self', $id);
        $cms->audit->record('users.deleted', ['user', (string) $id], ['username' => $user['username'], 'email' => $user['email'], 'via' => 'self'], $id);

        [$site] = AuthModule::site($cms);
        $message = self::goodbye($site);
        $mail = $cms->container->has('auth.mail') ? $cms->container->make('auth.mail') : null;
        if ($mail instanceof Closure) {
            $to = (string) $user['email'];
            WebRunner::defer(static function () use ($mail, $to, $message, $site): void {
                $mail($to, $message->subject, $message->render($site));
            });
        }

        return null;
    }

    /** The email saying the account is gone, so a deletion nobody asked for is noticed. */
    public static function goodbye(string $site): MailMessage
    {
        return MailMessage::make(Translator::get('Your {site} account was deleted', ['site' => $site]))
            ->greeting(Translator::get('Your account was deleted'))
            ->line(Translator::get('Your {site} account and the data kept with it were deleted at your request. You are signed out everywhere.', ['site' => $site]))
            ->line(Translator::get('If you did not do this, reply to this email or contact the site at once.'));
    }
}
