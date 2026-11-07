<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Content;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;

/** Taxonomies the caller can use, with the content types they apply to. */
final class ListTaxonomies extends ContentEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/taxonomies');
    }
    public function summary(): string
    {
        return 'List taxonomies';
    }

    public function handle(ApiRequest $request): mixed
    {
        $p = $request->principal;
        $out = [];
        foreach (self::cms()->content->taxonomies() as $tax) {
            if (!$p->can($tax->capability('assign')) && !$p->can($tax->capability('manage'))) {
                continue;
            }
            $domain = \Kestello\Cms\I18n\Labels::domain($tax->owner);
            $out[] = [
                'name' => $tax->name, 'singular' => \Kestello\Cms\I18n\Labels::text($tax->singular(), $domain), 'plural' => \Kestello\Cms\I18n\Labels::text($tax->plural(), $domain),
                'description' => $tax->getDescription(),
                'hierarchical' => $tax->isHierarchical(), 'path' => $tax->getPath(), 'types' => $tax->getTypes(), 'owner' => $tax->owner,
                'can' => ['assign' => $p->can($tax->capability('assign')), 'manage' => $p->can($tax->capability('manage')), 'create' => $tax->canCreateInline($p)],
            ];
        }

        return $out;
    }
}
