<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Users;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Api\Resources\UserResource;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Auth\Emails;
use Kestello\Cms\Auth\PasswordPolicy;
use Kestello\Framework\Core\Response;
use Kestello\Framework\Database\QueryBuilder;
use Kestello\Support\Hash;
use Kestello\Support\Translator;

/** Create an account for someone else. */
final class CreateUser extends Endpoint
{
    use UserAdmin;

    public function __construct(private readonly PasswordPolicy $policy) {}

    public function route(): ApiRoute
    {
        return ApiRoute::post('/users');
    }
    public function capability(): ?string
    {
        return 'users.manage';
    }
    public function scopes(): array
    {
        return ['users:write'];
    }
    public function requiresRecentAuth(): bool
    {
        return true;
    }
    public function rateLimit(): ?array
    {
        return [30, 3600];
    }
    public function resource(): ?string
    {
        return UserResource::class;
    }
    public function status(): int
    {
        return 201;
    }

    public function summary(): string
    {
        return 'Create a user';
    }
    public function tags(): array
    {
        return ['users'];
    }

    public function body(): ?Schema
    {
        return Schema::object([
            'username' => Schema::string()->min(3)->max(50)->pattern('^[A-Za-z0-9_-]+$'),
            'email' => Schema::string()->max(255)->format('email'),
            'password' => Schema::string()->min(1)->max(4096),
            'role' => Schema::enum(array_keys(self::cms()->auth->roles->roles())),
            'is_active' => Schema::bool()->default(true),
        ]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $username = (string) $request->input('username');
        $email = Emails::normalize((string) $request->input('email'));
        $role = (string) $request->input('role');
        self::guardSuper($request, $role);

        $errors = [];
        if (QueryBuilder::table('users')->where('username', $username)->exists()) {
            $errors['username'][] = Translator::get('This username is already taken.');
        }
        if (QueryBuilder::table('users')->where('email', $email)->exists()) {
            $errors['email'][] = Translator::get('This email is already in use.');
        }
        if (($problems = $this->policy->errors((string) $request->input('password'), ['username' => $username, 'email' => $email])) !== []) {
            $errors['password'] = $problems;
        }
        if ($errors !== []) {
            throw ApiProblem::validation($errors);
        }

        $now = date('Y-m-d H:i:s');
        $id = (int) QueryBuilder::table('users')->insert([
            'username' => $username,
            'email' => $email,
            'password' => Hash::password((string) $request->input('password')),
            'role' => $role,
            'is_active' => $request->input('is_active') ? 1 : 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        self::audit($request, 'users.created', $id, ['username' => $username, 'role' => $role]);
        self::cms()->events->dispatch(new \Kestello\Cms\Events\UserRegistered($id, 'admin'));

        $row = QueryBuilder::table('users')->select(...UserResource::COLUMNS)->where('id', $id)->first();

        return Response::json(['data' => (new UserResource((array) $row))->resolve($request->principal)], 201);
    }
}
