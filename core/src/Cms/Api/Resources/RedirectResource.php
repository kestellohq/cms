<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Resources;

use Kestello\Cms\Api\Resource;
use Kestello\Cms\Api\Schema;
use Kestello\Contracts\Auth\Principal;

/** A redirect rule (Redirects). Plugins add fields with $cms->api->extend('redirects'). */
final class RedirectResource extends Resource
{
    public const string NAME = 'redirects';

    protected function fields(Principal $principal): array
    {
        return [
            'id' => (int) $this->row['id'],
            'source' => (string) $this->row['source'],
            'match' => (string) $this->row['match'],
            'target' => (string) $this->row['target'],
            'status' => (int) $this->row['status'],
            'origin' => (string) $this->row['origin'],
            'entry_id' => ($this->row['entry_id'] ?? null) === null ? null : (int) $this->row['entry_id'],
            'note' => (string) $this->row['note'],
            'hits' => (int) $this->row['hits'],
            'last_hit_at' => self::date($this->row['last_hit_at'] ?? null),
            'created_at' => self::date($this->row['created_at'] ?? null),
            'updated_at' => self::date($this->row['updated_at'] ?? null),
        ];
    }

    /** @return array<string, Schema> */
    public static function properties(): array
    {
        return [
            'id' => Schema::int(),
            'source' => Schema::string()->description('The address it catches: a path, or a pattern when match is regex'),
            'match' => Schema::enum(['exact', 'prefix', 'regex']),
            'target' => Schema::string()->description('Where it sends people; empty for 410'),
            'status' => Schema::int()->description('301, 302, 307, 308 or 410'),
            'origin' => Schema::enum(['manual', 'auto', 'import'])->description('auto: made when an entry\'s address changed'),
            'entry_id' => Schema::int()->nullable()->description('The entry an automatic redirect belongs to'),
            'note' => Schema::string(),
            'hits' => Schema::int(),
            'last_hit_at' => Schema::string()->format('date-time')->nullable(),
            'created_at' => Schema::string()->format('date-time')->nullable(),
            'updated_at' => Schema::string()->format('date-time')->nullable(),
        ];
    }
}
