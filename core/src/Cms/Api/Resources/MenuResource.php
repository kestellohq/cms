<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Resources;

use Kestello\Cms\Api\Resource;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Menus\Menus;
use Kestello\Contracts\Auth\Principal;

/**
 * A menu with its items as the editor shows them: each item as saved,
 * with what it links to now ("resolved"). Plugins add fields with
 * $cms->api->extend('menus').
 */
final class MenuResource extends Resource
{
    public const string NAME = 'menus';

    protected function fields(Principal $principal): array
    {
        return [
            'id' => (int) $this->row['id'],
            'name' => (string) $this->row['name'],
            'items' => array_values((array) $this->row['items']),
            'locations' => array_values((array) $this->row['locations']),
            'placements' => array_values((array) $this->row['placements']),
            'updated_at' => self::date($this->row['updated_at'] ?? null),
        ];
    }

    /** @return array<string, Schema> */
    public static function properties(): array
    {
        return [
            'id' => Schema::int(),
            'name' => Schema::string(),
            'items' => Schema::list(self::item(Menus::MAX_DEPTH)),
            'locations' => Schema::list(Schema::string())->description('The theme locations it is in, in the main language'),
            'placements' => Schema::list(self::placement())->description('Every location it is in, in each language'),
            'updated_at' => Schema::string()->format('date-time')->nullable(),
        ];
    }

    public static function placement(): Schema
    {
        return Schema::object(['location' => Schema::string(), 'locale' => Schema::string()->description("'' for the main language")]);
    }

    /** One item, with its children to the depth menus allow. */
    private static function item(int $depth): Schema
    {
        $item = [
            'kind' => Schema::enum(Menus::KINDS),
            'label' => Schema::string()->optional()->description('Its own label; without one, the linked page\'s'),
            'ref' => Schema::any()->optional()->description('entry and term: an id; archive: a content type'),
            'url' => Schema::string()->optional()->description('kind url: the address'),
            'new_tab' => Schema::bool()->optional(),
            'resolved' => Schema::object([
                'label' => Schema::string(),
                'url' => Schema::string()->nullable(),
                'live' => Schema::bool()->description('Whether visitors see it: published, with an address'),
                'type_label' => Schema::string()->optional(),
            ])->description('What it links to now'),
        ];
        $item['children'] = $depth > 1 ? Schema::list(self::item($depth - 1)) : Schema::list(Schema::any());

        return Schema::object($item);
    }
}
