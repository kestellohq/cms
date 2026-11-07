<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Resources;

use Kestello\Cms\Api\Resource;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Auth\PersonalAccessTokens;
use Kestello\Contracts\Auth\Principal;

/** Anyone's access token, for System → Security: with its owner, never the secret. */
final class SecurityTokenResource extends Resource
{
    protected function fields(Principal $principal): array
    {
        $expires = $this->row['expires_at'] ?? null;

        return [
            'id' => (int) $this->row['id'],
            'name' => (string) $this->row['name'],
            'user' => ['id' => (int) $this->row['user_id'], 'username' => $this->row['username'] ?? null],
            'scopes' => PersonalAccessTokens::decodeScopes($this->row['scopes'] ?? null),
            'expires_at' => self::date($expires),
            'expired' => $expires !== null && (string) $expires < date('Y-m-d H:i:s'),
            'last_used_at' => self::date($this->row['last_used_at'] ?? null),
            'last_used_ip' => $this->row['last_used_ip'] ?? null,
            'created_at' => self::date($this->row['created_at'] ?? null),
        ];
    }

    /** @return array<string, Schema> */
    public static function properties(): array
    {
        return [
            'id' => Schema::int(),
            'name' => Schema::string(),
            'user' => Schema::object(['id' => Schema::int(), 'username' => Schema::string()->nullable()]),
            'scopes' => Schema::list(Schema::string()),
            'expires_at' => Schema::string()->format('date-time')->nullable(),
            'expired' => Schema::bool(),
            'last_used_at' => Schema::string()->format('date-time')->nullable(),
            'last_used_ip' => Schema::string()->nullable(),
            'created_at' => Schema::string()->format('date-time')->nullable(),
        ];
    }
}
