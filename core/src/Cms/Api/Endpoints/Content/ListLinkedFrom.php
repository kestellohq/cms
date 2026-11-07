<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Content;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Content\Entry;
use Kestello\Cms\Content\EntryRelations;

/**
 * The entries that link to this one through a relationship field (two-way
 * relationships), each with the fields that hold the link. Only entries the
 * caller could open are listed; trashed ones are left out.
 */
final class ListLinkedFrom extends ContentEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/content/{type}/entries/{id}/linked-from')->where('type', self::TYPE)->where('id', '\d{1,18}');
    }
    public function summary(): string
    {
        return 'List the entries that link to an entry';
    }

    public function handle(ApiRequest $request): mixed
    {
        $type = self::type($request);
        $entry = self::entry($request, $type, withTrashed: true);
        $p = $request->principal;
        $cms = self::cms();
        $types = [];
        foreach ($cms->content->types() as $t) {
            if ($p->can($t->capability('edit'))) {
                $types[$t->name] = $t;
            }
        }

        $entries = self::entries();
        $out = [];
        foreach ($cms->container->make(EntryRelations::class)->linkingTo((int) $entry->getKey(), array_keys($types)) as $link) {
            $source = $types[$link['type']];
            $own = (new Entry())->forceFill(['author_id' => $link['author_id']]);
            if (!$entries->can($p, $source, 'view', $own)) {
                continue;
            }
            $labels = [];
            foreach ($source->getFields() as $field) {
                $labels[$field->name] = $field->getLabel();
            }
            $out[] = [
                'id' => $link['id'], 'type' => $link['type'], 'type_label' => $source->singular(), 'title' => $link['title'], 'status' => $link['status'],
                'fields' => array_map(static fn(string $name): array => ['name' => $name, 'label' => $labels[$name] ?? $name], $link['fields']),
            ];
        }

        return $out;
    }
}
