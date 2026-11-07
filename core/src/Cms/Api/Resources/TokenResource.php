<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Resources;

use Kestello\Cms\Api\Resource;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Auth\PersonalAccessTokens;
use Kestello\Contracts\Auth\Principal;

/** A personal access token's metadata. The token itself is never included. */
final class TokenResource extends Resource
{
    protected function fields(Principal $principal): array
    {
        return [
            'id' => (int) $this->row['id'],
            'name' => (string) $this->row['name'],
            'scopes' => PersonalAccessTokens::decodeScopes($this->row['scopes'] ?? null),
            'expires_at' => self::date($this->row['expires_at'] ?? null),
            'last_used_at' => self::date($this->row['last_used_at'] ?? null),
            'last_used_ip' => $this->row['last_used_ip'] ?? null,
            'created_at' => self::date($this->row['created_at'] ?? null),
            'current' => $principal->method === 'token' && $principal->credentialId === (string) $this->row['id'],
        ];
    }

    public static function properties(): array
    {
        return [
            'id' => Schema::int(),
            'name' => Schema::string(),
            'scopes' => Schema::list(Schema::string()),
            'expires_at' => Schema::string()->format('date-time')->nullable(),
            'last_used_at' => Schema::string()->format('date-time')->nullable(),
            'last_used_ip' => Schema::string()->nullable(),
            'created_at' => Schema::string()->format('date-time')->nullable(),
            'current' => Schema::bool()->description('The token making this request'),
        ];
    }
}
