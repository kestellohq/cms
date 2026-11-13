<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Auth;

use Closure;
use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Auth\AccountDeletion;
use Kestello\Cms\Auth\AccountMail;
use Kestello\Cms\Auth\Auth;
use Kestello\Cms\Auth\AuthModule;
use Kestello\Cms\Cms;
use Kestello\Framework\Database\QueryBuilder;

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
    public function problems(): array
    {
        return [...parent::problems(), 409];
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

        // the language they read in, while their account is still there to say it
        $language = \Kestello\Cms\I18n\PersonLanguage::of($user);
        // sign this browser out first, so the ending request doesn't write the session back
        Auth::getInstance()?->logout();
        AccountDeletion::delete($cms, $id, 'self', $id);
        $cms->audit->record('users.deleted', ['user', (string) $id], ['username' => $user['username'], 'email' => $user['email'], 'via' => 'self'], $id);

        [$site] = AuthModule::site($cms);
        $mail = $cms->container->has('auth.mail') ? $cms->container->make('auth.mail') : null;
        if ($mail instanceof Closure) {
            AccountMail::send($mail, 'auth.account_deleted', (string) $user['email'], ['site' => $site], $site, "the account-deleted email for user #{$id} could not be sent", $language);
        }

        return null;
    }
}
