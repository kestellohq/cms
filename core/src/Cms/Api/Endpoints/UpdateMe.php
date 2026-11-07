<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Api\Resources\UserResource;
use Kestello\Cms\Auth\Emails;
use Kestello\Cms\Cms;
use Kestello\Framework\Database\QueryBuilder;

/**
 * Edit your own profile. Changing your email needs a recent sign-in (it is
 * where password resets go); the bio does not.
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

        if ($request->has('email')) {
            $email = Emails::normalize((string) $request->input('email'));
            if ($email !== $user['email']) {
                if (!$request->principal->recentlyAuthenticated()) {
                    throw ApiProblem::reauthRequired();
                }
                if (QueryBuilder::table('users')->where('email', $email)->where('id', '!=', $id)->exists()) {
                    throw ApiProblem::validation(['email' => ['This email is already in use.']]);
                }
                $changes['email'] = $email;
            }
        }
        if ($request->has('bio')) {
            $changes['bio'] = $request->input('bio');
        }

        if ($changes !== []) {
            QueryBuilder::table('users')->where('id', $id)->update($changes + ['updated_at' => date('Y-m-d H:i:s')]);
            Cms::instance()?->audit->record('profile.updated', ['user', $id], ['fields' => array_keys($changes)], $id);
        }

        return new UserResource((array) QueryBuilder::table('users')->select(...UserResource::COLUMNS)->where('id', $id)->first());
    }
}
