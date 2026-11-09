<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Api\Resources\UserResource;
use Kestello\Cms\Auth\EmailChanges;
use Kestello\Cms\Auth\Emails;
use Kestello\Cms\Cms;
use Kestello\Framework\Database\QueryBuilder;

/**
 * Edit your own profile. Changing your email needs a recent sign-in (it is
 * where password resets go) and only takes effect once a link sent to the
 * new address is opened (EmailChanges); the bio does not.
 */
final class UpdateMe extends Endpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::patch('/me');
    }
    public function capability(): ?string
    {
        return 'profile.manage';
    }
    public function scopes(): array
    {
        return ['profile'];
    }
    public function resource(): ?string
    {
        return UserResource::class;
    }
    public function summary(): string
    {
        return 'Update your profile';
    }
    public function tags(): array
    {
        return ['me'];
    }

    public function body(): ?\Kestello\Cms\Api\Schema
    {
        return \Kestello\Cms\Api\Schema::object([
            'email' => \Kestello\Cms\Api\Schema::string()->max(255)->format('email')->optional(),
            'bio' => \Kestello\Cms\Api\Schema::string()->max(2000)->nullable()->optional(),
        ]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $id = (int) $request->userId();
        $user = QueryBuilder::table('users')->where('id', $id)->first() ?? throw ApiProblem::unauthenticated();
        $changes = [];
        $pending = null;

        if ($request->has('email')) {
            $email = Emails::normalize((string) $request->input('email'));
            if ($email !== $user['email']) {
                if (!$request->principal->recentlyAuthenticated()) {
                    throw ApiProblem::reauthRequired();
                }
                $cms = Cms::instance();
                if ($cms === null || $cms->config->get('app.demo', false) || !$cms->container->has(EmailChanges::class)) {
                    throw ApiProblem::validation(['email' => ['The email address cannot be changed on this site.']]);
                }
                // nothing changes yet: the new address gets a link, and the account takes it when the link is opened
                $problem = $cms->container->make(EmailChanges::class)->request($user, $email);
                if ($problem !== null) {
                    throw ApiProblem::validation(['email' => [$problem]]);
                }
                $pending = $email;
            }
        }
        if ($request->has('bio')) {
            $changes['bio'] = $request->input('bio');
        }

        if ($changes !== []) {
            QueryBuilder::table('users')->where('id', $id)->update($changes + ['updated_at' => date('Y-m-d H:i:s')]);
            Cms::instance()?->audit->record('profile.updated', ['user', $id], ['fields' => array_keys($changes)], $id);
        }
        if ($pending !== null) {
            Cms::instance()?->audit->record('profile.email_change_requested', ['user', $id], [], $id);
        }

        return new UserResource((array) QueryBuilder::table('users')->select(...UserResource::COLUMNS)->where('id', $id)->first());
    }
}
