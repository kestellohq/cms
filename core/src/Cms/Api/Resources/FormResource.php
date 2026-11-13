<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Resources;

use Kestello\Cms\Api\Resource;
use Kestello\Cms\Api\Schema;
use Kestello\Contracts\Auth\Principal;

/**
 * A form as Forms presents it: its definition (fields, wording,
 * notifications and limits) and, in the list, how many submissions it has.
 * Plugins add fields with $cms->api->extend('forms').
 */
final class FormResource extends Resource
{
    public const string NAME = 'forms';

    protected function fields(Principal $principal): array
    {
        $r = $this->row;

        return [
            'id' => (int) $r['id'],
            'title' => (string) $r['title'],
            'status' => (string) $r['status'],
            'definition' => $r['definition'],
            'updated_at' => self::date($r['updated_at'] ?? null),
        ] + (isset($r['submissions']) ? ['submissions' => ['total' => (int) $r['submissions']['total'], 'new' => (int) $r['submissions']['new']]] : []);
    }

    /** @return array<string, Schema> */
    public static function properties(): array
    {
        return [
            'id' => Schema::int(),
            'title' => Schema::string(),
            'status' => Schema::enum(['open', 'closed']),
            'definition' => Schema::any()->description('Its fields, wording, notifications and limits, as saved'),
            'updated_at' => Schema::string()->format('date-time')->nullable(),
            'submissions' => Schema::object(['total' => Schema::int()->description('Not counting spam'), 'new' => Schema::int()])->optional()->description('Only in the list of forms'),
        ];
    }
}
