<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\ContentModels;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Content\Models\ContentModels;
use Kestello\Framework\Core\Response;

/** Built types and taxonomies, what code declares (read-only), and the field types the builder offers. */
final class ListContentModels extends ContentModelEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/content-models');
    }
    public function summary(): string
    {
        return 'List content models';
    }

    public function handle(ApiRequest $request): mixed
    {
        $cms = self::cms();
        $models = self::models();
        $built = $models->all();
        $skipped = $models->skipped();
        foreach ($built as &$m) {
            $m['skipped'] = $skipped[$m['kind'] . ':' . $m['name']] ?? null;
        }
        unset($m);
        $ownedBySite = array_flip(array_map(static fn($m) => $m['kind'] . ':' . $m['name'], $built));
        $code = [];
        foreach ($cms->content->types() as $t) {
            if (!isset($ownedBySite['type:' . $t->name]) || $t->owner !== 'site') {
                $code[] = ['kind' => 'type', 'name' => $t->name, 'label' => $t->plural(), 'owner' => $t->owner, 'path' => $t->getPath(), 'fields' => count($t->getFields())];
            }
        }
        foreach ($cms->content->taxonomies() as $t) {
            if (!isset($ownedBySite['taxonomy:' . $t->name]) || $t->owner !== 'site') {
                $code[] = ['kind' => 'taxonomy', 'name' => $t->name, 'label' => $t->plural(), 'owner' => $t->owner, 'path' => $t->getPath(), 'fields' => 0];
            }
        }
        $code = array_values(array_filter($code, static fn($c) => $c['owner'] !== 'site'));

        return Response::json(['data' => $built, 'meta' => [
            'code' => $code,
            'field_types' => array_keys(ContentModels::OPTIONS),
            'options' => ContentModels::OPTIONS,
            'permalinks' => array_keys(\Kestello\Cms\Content\Permalinks::PATTERNS),
            'checks' => $cms->content->checks->catalog(),
            'taxonomies' => array_map(static fn($t) => ['name' => $t->name, 'label' => $t->plural()], array_values($cms->content->taxonomies())),
            'types' => array_map(static fn($t) => ['name' => $t->name, 'label' => $t->plural()], array_values($cms->content->types())),
        ]]);
    }
}
