<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Content;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Content\Entry;
use Kestello\Framework\Database\QueryBuilder;

/**
 * Small entry records across types, for relation pickers: search by title,
 * or resolve ids to titles. Only types the caller can edit, and only their
 * own entries where they can't edit others'.
 */
final class LookupEntries extends ContentEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/content/lookup');
    }
    public function summary(): string
    {
        return 'Find entries for relation pickers';
    }

    public function query(): ?Schema
    {
        return Schema::object([
            'search' => Schema::string()->max(100)->optional(),
            'types' => Schema::string()->max(400)->pattern('[a-z0-9,-]*')->optional()->description('Comma separated; all editable types when empty'),
            'ids' => Schema::string()->max(1000)->pattern('[0-9,]*')->optional()->description('Comma separated ids to resolve'),
            'limit' => Schema::int()->min(1)->max(50)->default(20),
        ]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $p = $request->principal;
        $wanted = array_filter(explode(',', (string) $request->query('types', '')));
        $types = [];
        foreach (self::cms()->content->types() as $type) {
            if (($wanted === [] || in_array($type->name, $wanted, true)) && $p->can($type->capability('edit'))) {
                $types[$type->name] = $type;
            }
        }
        if ($types === []) {
            return [];
        }
        $q = Entry::query()->whereIn('type', array_keys($types))->where(static function (QueryBuilder $q) use ($types, $p): void {
            foreach ($types as $type) {
                $q->orWhere(static function (QueryBuilder $w) use ($type, $p): void {
                    $w->where('type', $type->name);
                    if (!$p->can($type->capability('edit_others'))) {
                        $w->where('author_id', $p->userId);
                    }
                });
            }
        });
        $ids = array_slice(array_values(array_unique(array_map('intval', array_filter(explode(',', (string) $request->query('ids', '')))))), 0, 100);
        if ($ids !== []) {
            $q->whereIn('id', $ids);
        } else {
            if (($search = trim((string) $request->query('search', ''))) !== '') {
                $q->whereLike('title', '%' . QueryBuilder::escapeLike($search) . '%');
            }
            $q->orderByDesc('updated_at')->limit((int) $request->query('limit'));
        }

        return array_map(static fn(Entry $e): array => [
            'id' => $e->getKey(), 'type' => $e->type, 'type_label' => \Kestello\Cms\I18n\Labels::text($types[$e->type]->singular(), \Kestello\Cms\I18n\Labels::domain($types[$e->type]->owner)),
            'title' => (string) $e->title, 'status' => $e->status, 'path' => $e->path($types[$e->type]),
        ], $q->get()->all());
    }
}
