<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Resources;

use Kestello\Cms\Api\Resource;
use Kestello\Cms\Api\Schema;
use Kestello\Contracts\Auth\Principal;

/** An address visitors asked for that the site does not have (the not-found log). */
final class NotFoundResource extends Resource
{
    public const string NAME = 'not-found';

    protected function fields(Principal $principal): array
    {
        return [
            'id' => (int) $this->row['id'],
            'path' => (string) $this->row['path'],
            'referrer' => (string) $this->row['referrer'],
            'hits' => (int) $this->row['hits'],
            'first_seen_at' => self::date($this->row['first_seen_at'] ?? null),
            'last_seen_at' => self::date($this->row['last_seen_at'] ?? null),
        ];
    }

    /** @return array<string, Schema> */
    public static function properties(): array
    {
        return [
            'id' => Schema::int(),
            'path' => Schema::string(),
            'referrer' => Schema::string()->description('The last page that linked to it; empty when none was sent'),
            'hits' => Schema::int(),
            'first_seen_at' => Schema::string()->format('date-time')->nullable(),
            'last_seen_at' => Schema::string()->format('date-time')->nullable(),
        ];
    }
}
