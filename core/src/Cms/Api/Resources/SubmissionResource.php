<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Resources;

use Kestello\Cms\Api\Resource;
use Kestello\Cms\Api\Schema;
use Kestello\Contracts\Auth\Principal;

/**
 * What someone sent with a form, and where from. Plugins add fields with
 * $cms->api->extend('form-submissions').
 */
final class SubmissionResource extends Resource
{
    public const string NAME = 'form-submissions';

    protected function fields(Principal $principal): array
    {
        $r = $this->row;

        return [
            'id' => (int) $r['id'],
            'form_id' => (int) $r['form_id'],
            'data' => $r['data'],
            'status' => (string) $r['status'],
            'ip' => (string) $r['ip'],
            'user_agent' => (string) $r['user_agent'],
            'page' => (string) $r['page'],
            'user_id' => $r['user_id'] === null ? null : (int) $r['user_id'],
            'created_at' => self::date($r['created_at'] ?? null),
        ];
    }

    /** @return array<string, Schema> */
    public static function properties(): array
    {
        return [
            'id' => Schema::int(),
            'form_id' => Schema::int(),
            'data' => Schema::any()->description('The values sent, by field name; a file field holds a list of {key, name, size, type}'),
            'status' => Schema::enum(['new', 'read', 'spam']),
            'ip' => Schema::string(),
            'user_agent' => Schema::string(),
            'page' => Schema::string()->description('The path of the page it was sent from'),
            'user_id' => Schema::int()->nullable()->description('Who sent it, when signed in'),
            'created_at' => Schema::string()->format('date-time')->nullable(),
        ];
    }
}
