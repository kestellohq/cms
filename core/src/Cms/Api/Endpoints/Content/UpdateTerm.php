<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Content;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Resources\TermResource;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Content\Term;

/** Rename, re-slug, move or describe a term. */
final class UpdateTerm extends TermEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::patch('/taxonomies/{taxonomy}/terms/{id}')->where('taxonomy', self::TYPE)->where('id', '\d{1,18}');
    }
    public function scopes(): array
    {
        return ['content:write'];
    }
    public function summary(): string
    {
        return 'Update a term';
    }
    public function body(): ?Schema
    {
        return self::termBody();
    }

    public function resource(): ?string
    {
        return TermResource::class;
    }

    public function handle(ApiRequest $request): mixed
    {
        $tax = self::taxonomy($request, manage: true);
        $term = self::attempt(fn() => self::terms()->update($tax, self::term($request, $tax), $request->body, $request->principal));

        return new TermResource(self::row($term, $tax, self::terms()->counts($tax)[$term->getKey()] ?? 0));
    }
}
