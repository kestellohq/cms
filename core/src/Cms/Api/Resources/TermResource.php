<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Resources;

use Kestello\Cms\Api\Resource;
use Kestello\Cms\Api\Schema;
use Kestello\Contracts\Auth\Principal;

/**
 * A category, tag or other term, with its address, its entries and its
 * translations. Plugins add fields with $cms->api->extend('terms').
 */
final class TermResource extends Resource
{
    public const string NAME = 'terms';

    protected function fields(Principal $principal): array
    {
        $r = $this->row;

        return [
            'id' => (int) $r['id'],
            'taxonomy' => (string) $r['taxonomy'],
            'name' => (string) $r['name'],
            'slug' => (string) $r['slug'],
            'parent_id' => $r['parent_id'] === null ? null : (int) $r['parent_id'],
            'description' => $r['description'] === null ? null : (string) $r['description'],
            'menu_order' => (int) $r['menu_order'],
            'path' => $r['path'] === null ? null : (string) $r['path'],
            'count' => $r['count'] === null ? null : (int) $r['count'],
            'locale' => (string) $r['locale'],
            'translations' => array_values((array) $r['translations']),
            'depth' => (int) ($r['depth'] ?? 0),
        ];
    }

    /** @return array<string, Schema> */
    public static function properties(): array
    {
        return [
            'id' => Schema::int(),
            'taxonomy' => Schema::string(),
            'name' => Schema::string(),
            'slug' => Schema::string(),
            'parent_id' => Schema::int()->nullable(),
            'description' => Schema::string()->nullable(),
            'menu_order' => Schema::int(),
            'path' => Schema::string()->nullable()->description('Its public address, or null when the taxonomy has none'),
            'count' => Schema::int()->nullable()->description('Entries with it'),
            'locale' => Schema::string()->description("Its language: '' for the main one"),
            'translations' => Schema::list(Schema::object(['locale' => Schema::string(), 'id' => Schema::int(), 'title' => Schema::string()]))->description('Its translations, by language'),
            'depth' => Schema::int()->description('How deep it sits in a hierarchical list; 0 otherwise'),
        ];
    }
}
