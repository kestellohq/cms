<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Resources;

use Kestello\Cms\Api\Resource;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Content\ContentType;
use Kestello\Cms\Content\Entry;
use Kestello\Contracts\Auth\Principal;
use Kestello\Framework\Database\Orm\Caster;

/**
 * An entry for editors. Row keys: model (Entry), type (ContentType),
 * can (abilities), full (bool: include data, excerpt, template, autosave),
 * autosave (array|null).
 */
final class EntryResource extends Resource
{
    public const string NAME = 'entries';

    public static function properties(): array
    {
        $ref = static fn() => Schema::object(['id' => Schema::int(), 'title' => Schema::string()])->nullable();
        $date = static fn() => Schema::string()->format('date-time')->nullable();

        return [
            'id' => Schema::int(),
            'type' => Schema::string(),
            'status' => Schema::enum(Entry::STATUSES),
            'title' => Schema::string(),
            'slug' => Schema::string(),
            'path' => Schema::string()->nullable()->description('Where it is on the site; null for types without pages'),
            'parent' => $ref(),
            'parent_id' => Schema::int()->nullable(),
            'author' => Schema::object(['id' => Schema::int(), 'username' => Schema::string()])->nullable(),
            'assignee' => Schema::object(['id' => Schema::int(), 'username' => Schema::string()])->nullable(),
            'menu_order' => Schema::int(),
            'version' => Schema::int()->description('Send it back as "version" when saving, to detect edits made meanwhile'),
            'published_at' => $date(),
            'created_at' => $date(),
            'updated_at' => $date(),
            'trashed_at' => $date(),
            'can' => Schema::dictionary()->description('What you may do with it: update, publish, delete, …'),
            'terms' => Schema::any()->description('Term ids per taxonomy: {"category": [3, 7]}'),
            'excerpt' => Schema::string()->nullable()->optional()->description('Single-entry answers only'),
            'template' => Schema::string()->nullable()->optional()->description('Single-entry answers only'),
            'data' => Schema::any()->optional()->description("The type's fields; single-entry answers only"),
            'autosave' => Schema::any()->nullable()->optional()->description('A newer unsaved draft, if any; single-entry answers only'),
        ];
    }

    protected function fields(Principal $principal): array
    {
        /** @var Entry $e */
        $e = $this->row['model'];
        /** @var ContentType $type */
        $type = $this->row['type'];
        $author = $e->relationLoaded('author') ? $e->getRelation('author') : null;
        $parent = $e->relationLoaded('parent') ? $e->getRelation('parent') : null;

        $out = [
            'id' => $e->getKey(),
            'type' => $type->name,
            'status' => $e->status,
            'title' => (string) $e->title,
            'slug' => (string) $e->slug,
            'path' => $e->path($type),
            'parent' => $parent === null ? null : ['id' => $parent->getKey(), 'title' => (string) $parent->title],
            'parent_id' => $e->parent_id,
            'author' => $author === null ? null : ['id' => $author->getKey(), 'username' => (string) $author->username],
            'assignee' => $this->row['assignee'] ?? null,
            'menu_order' => (int) $e->menu_order,
            'version' => (int) $e->version,
            'published_at' => Caster::present($e->published_at),
            'created_at' => Caster::present($e->created_at),
            'updated_at' => Caster::present($e->updated_at),
            'trashed_at' => Caster::present($e->deleted_at),
            'can' => $this->row['can'],
            'terms' => (object) ($this->row['terms'] ?? []),
        ];
        if ($this->row['full'] ?? false) {
            $out += [
                'excerpt' => $e->excerpt,
                'template' => $e->template,
                'data' => (object) ($e->data ?? []),
                'autosave' => $this->row['autosave'] ?? null,
            ];
        }

        return $out;
    }
}
