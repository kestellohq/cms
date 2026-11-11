<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Resources;

use Kestello\Cms\Api\Resource;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Cms;
use Kestello\Contracts\Auth\Principal;

final class UserResource extends Resource
{
    public const string NAME = 'users';

    /** Columns endpoints may select (never password). */
    public const array COLUMNS = ['id', 'username', 'email', 'role', 'is_active', 'bio', 'locale', 'last_login_at', 'created_at', 'updated_at'];

    protected function fields(Principal $principal): array
    {
        return [
            'id' => (int) $this->row['id'],
            'username' => (string) $this->row['username'],
            'email' => (string) $this->row['email'],
            'role' => (string) $this->row['role'],
            'role_label' => self::roleLabel((string) $this->row['role']),
            'is_active' => (bool) $this->row['is_active'],
            'bio' => $this->row['bio'] ?? null,
            'locale' => (string) ($this->row['locale'] ?? ''),
            'last_login_at' => self::date($this->row['last_login_at'] ?? null),
            'created_at' => self::date($this->row['created_at'] ?? null),
        ];
    }

    private static function roleLabel(string $role): string
    {
        $roles = Cms::instance()?->auth->roles->roles() ?? [];

        return (string) ($roles[$role]['label'] ?? $role);
    }

    public static function properties(): array
    {
        return [
            'id' => Schema::int(),
            'username' => Schema::string(),
            'email' => Schema::string()->format('email'),
            'role' => Schema::string(),
            'role_label' => Schema::string()->description('The role as people see it (its name if the role is gone)'),
            'is_active' => Schema::bool(),
            'bio' => Schema::string()->nullable(),
            'locale' => Schema::string()->description('The language their emails are written in, such as "sr"; empty for the site\'s language'),
            'last_login_at' => Schema::string()->format('date-time')->nullable(),
            'created_at' => Schema::string()->format('date-time')->nullable(),
        ];
    }
}
