<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Users;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Api\Resources\UserResource;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Auth\AccountSecurity;
use Kestello\Cms\Auth\Emails;
use Kestello\Cms\Auth\PasswordPolicy;
use Kestello\Framework\Database\QueryBuilder;
use Kestello\Support\Translator;

/**
 * Edit someone's account. A new password ends their sessions and tokens;
 * deactivating ends them too.
 */
final class UpdateUser extends Endpoint
{
    use UserAdmin;

    public function __construct(private readonly PasswordPolicy $policy, private readonly AccountSecurity $accounts) {}

    public function route(): ApiRoute
    {
        return ApiRoute::patch('/users/{id}')->where('id', '\d+');
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
    public function resource(): ?string
    {
        return UserResource::class;
    }
    public function summary(): string
    {
        return 'Update a user';
    }
    public function tags(): array
    {
        return ['users'];
    }

    public function body(): ?Schema
    {
        return Schema::object([
            'username' => Schema::string()->min(3)->max(50)->pattern('^[A-Za-z0-9_-]+$')->optional(),
            'email' => Schema::string()->max(255)->format('email')->optional(),
            'role' => Schema::enum(array_keys(self::cms()->auth->roles->roles()))->optional(),
            'is_active' => Schema::bool()->optional(),
            'bio' => Schema::string()->max(2000)->nullable()->optional(),
            'password' => Schema::string()->min(1)->max(4096)->optional(),
        ]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $id = $request->intParam('id');
        $user = self::target($id);
        $self = $id === $request->userId();
        self::guardSuper($request, (string) $user['role']);

        $changes = [];
        $errors = [];
        if ($request->has('username') && $request->input('username') !== $user['username']) {
            $username = (string) $request->input('username');
            if (QueryBuilder::table('users')->where('username', $username)->where('id', '!=', $id)->exists()) {
                $errors['username'][] = Translator::get('This username is already taken.');
            }
            $changes['username'] = $username;
        }
        if ($request->has('email')) {
            $email = Emails::normalize((string) $request->input('email'));
            if ($email !== $user['email']) {
                if (QueryBuilder::table('users')->where('email', $email)->where('id', '!=', $id)->exists()) {
                    $errors['email'][] = Translator::get('This email is already in use.');
                }
                $changes['email'] = $email;
            }
        }
        if ($request->has('role') && $request->input('role') !== $user['role']) {
            $role = (string) $request->input('role');
            self::guardSuper($request, $role);
            if ($self) {
                $errors['role'][] = Translator::get('You cannot change your own role.');
            } elseif (self::isSuperRole((string) $user['role']) && !self::isSuperRole($role) && self::otherActiveSupers($id) === 0) {
                $errors['role'][] = Translator::get('This is the last administrator.');
            }
            $changes['role'] = $role;
        }
        if ($request->has('is_active') && (bool) $request->input('is_active') !== (bool) $user['is_active']) {
            $active = (bool) $request->input('is_active');
            if (!$active && $self) {
                $errors['is_active'][] = Translator::get('You cannot deactivate your own account.');
            } elseif (!$active && self::isSuperRole((string) $user['role']) && self::otherActiveSupers($id) === 0) {
                $errors['is_active'][] = Translator::get('This is the last active administrator.');
            }
            $changes['is_active'] = $active ? 1 : 0;
        }
        if ($request->has('bio')) {
            $changes['bio'] = $request->input('bio');
        }
        $password = $request->has('password') ? (string) $request->input('password') : null;
        if ($password !== null) {
            if ($self) {
                $errors['password'][] = Translator::get('Change your own password from your profile.');
            } elseif (($problems = $this->policy->errors($password, ['username' => $changes['username'] ?? $user['username'], 'email' => $changes['email'] ?? $user['email']])) !== []) {
                $errors['password'] = $problems;
            }
        }
        if ($errors !== []) {
            throw ApiProblem::validation($errors);
        }

        if ($changes !== []) {
            QueryBuilder::table('users')->where('id', $id)->update($changes + ['updated_at' => date('Y-m-d H:i:s')]);
        }
        if (isset($changes['email'])) {
            // reset links already mailed went to the old address
            \Kestello\Cms\Auth\EmailChanges::addressChanged($id);
        }
        if ($password !== null) {
            $this->accounts->changePassword($id, $password, 'admin');
        }
        if (($changes['is_active'] ?? null) === 0) {
            self::endAccess($id);
        }
        $logged = array_keys($changes + ($password !== null ? ['password' => true] : []));
        if ($logged !== []) {
            self::audit($request, 'users.updated', $id, ['fields' => $logged] + (isset($changes['role']) ? ['role' => ['from' => $user['role'], 'to' => $changes['role']]] : []));
        }

        return new UserResource((array) QueryBuilder::table('users')->select(...UserResource::COLUMNS)->where('id', $id)->first());
    }
}
