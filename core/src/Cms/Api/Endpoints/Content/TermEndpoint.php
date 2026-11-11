<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Content;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Content\Taxonomy;
use Kestello\Cms\Content\Term;
use Kestello\Cms\Content\Terms;

/** Shared plumbing for the taxonomy endpoints. */
abstract class TermEndpoint extends ContentEndpoint
{
    protected static function taxonomy(ApiRequest $request, bool $manage = false): Taxonomy
    {
        $tax = self::cms()->content->getTaxonomy((string) $request->param('taxonomy')) ?? throw ApiProblem::notFound('No such taxonomy.');
        $p = $request->principal;
        if (!$p->can($tax->capability('manage')) && ($manage || !$p->can($tax->capability('assign')))) {
            throw ApiProblem::forbidden("You can't " . ($manage ? 'manage ' : 'use ') . strtolower($tax->plural()) . '.');
        }

        return $tax;
    }

    protected static function term(ApiRequest $request, Taxonomy $tax): Term
    {
        return Term::query()->of($tax->name)->find($request->intParam('id')) ?? throw ApiProblem::notFound('No such ' . strtolower($tax->singular()) . '.');
    }

    protected static function terms(): Terms
    {
        return new Terms();
    }

    /**
     * @param list<array{locale: string, id: int, title: string}>|null $translations null: read them
     * @return array<string, mixed>
     */
    protected static function row(Term $term, Taxonomy $tax, ?int $count = null, ?array $translations = null): array
    {
        return [
            'id' => $term->getKey(), 'taxonomy' => $tax->name, 'name' => (string) $term->name, 'slug' => (string) $term->slug,
            'parent_id' => $term->parent_id, 'description' => $term->description, 'menu_order' => (int) $term->menu_order,
            'path' => $term->url($tax), 'count' => $count, 'locale' => $term->language(),
            'translations' => $translations ?? (\Kestello\Cms\Content\Translations::ofTerms([$term])[(int) $term->getKey()] ?? []),
        ];
    }

    protected static function termBody(bool $create = false): \Kestello\Cms\Api\Schema
    {
        $S = \Kestello\Cms\Api\Schema::class;

        return $S::object(($create ? ['locale' => $S::string()->max(12)->optional()->description("The language it is written in: '' (the main one, the default) or one of the site's other languages. Fixed once created")] : []) + [
            'name' => $S::string()->max(191)->optional(),
            'slug' => $S::string()->max(191)->optional(),
            'parent_id' => $S::int()->min(1)->nullable()->optional(),
            'description' => $S::string()->max(2000)->raw()->nullable()->optional(),
            'menu_order' => $S::int()->min(-100000)->max(100000)->optional(),
        ]);
    }
}
