<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Content;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Content\ContentType;
use Kestello\Cms\I18n\Labels;

/** Content types the caller can edit, with their fields (the editor renders these). */
final class ListContentTypes extends ContentEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/content/types');
    }
    public function summary(): string
    {
        return 'List content types and their fields';
    }

    public function handle(ApiRequest $request): mixed
    {
        $cms = self::cms();
        $out = [];
        $templates = $cms->container->has(\Kestello\Cms\Design\ThemeTemplates::class) ? $cms->container->make(\Kestello\Cms\Design\ThemeTemplates::class) : null;
        foreach ($cms->content->types() as $type) {
            $p = $request->principal;
            if (!$p->can($type->capability('edit'))) {
                continue;
            }
            $domain = Labels::domain($type->owner);
            $out[] = [
                'name' => $type->name,
                'singular' => Labels::text($type->singular(), $domain),
                'plural' => Labels::text($type->plural(), $domain),
                'description' => Labels::text($type->getDescription(), $domain),
                'icon' => $type->getIcon(),
                'hierarchical' => $type->isHierarchical(),
                'excerpt' => $type->hasExcerpt(),
                'revisions' => $type->keepRevisions() > 0,
                'path' => $type->getPath(),
                'archive' => $type->hasArchive(),
                'owner' => $type->owner,
                'templates' => $templates === null ? [] : (static function () use ($templates, $type): array {
                    try {
                        return $templates->choices($type->name);
                    } catch (\Throwable) {
                        return [];
                    }
                })(),
                'fields' => $cms->content->fields->describe($type->getFields(), $domain),
                'taxonomies' => array_values(array_map(static fn(\Kestello\Cms\Content\Taxonomy $t) => [
                    'name' => $t->name, 'singular' => Labels::text($t->singular(), Labels::domain($t->owner)), 'plural' => Labels::text($t->plural(), Labels::domain($t->owner)), 'hierarchical' => $t->isHierarchical(),
                    'can' => ['assign' => $p->can($t->capability('assign')), 'manage' => $p->can($t->capability('manage')), 'create' => $t->canCreateInline($p)],
                ], $cms->content->taxonomiesFor($type->name))),
                'can' => array_combine(ContentType::ACTIONS, array_map(static fn($a) => $p->can($type->capability($a)), ContentType::ACTIONS)),
            ];
        }

        return $out;
    }
}
