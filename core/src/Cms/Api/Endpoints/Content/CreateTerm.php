<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Content;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Content\Term;

/** Add a term. */
final class CreateTerm extends TermEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/taxonomies/{taxonomy}/terms')->where('taxonomy', self::TYPE);
    }
    public function scopes(): array
    {
        return ['content:write'];
    }
    public function summary(): string
    {
        return 'Create a term';
    }
    public function body(): ?Schema
    {
        return self::termBody();
    }

    public function handle(ApiRequest $request): mixed
    {
        $tax = self::taxonomy($request, manage: true);
        $term = self::attempt(fn() => self::terms()->create($tax, $request->body, $request->principal));

        return self::row($term, $tax, 0);
    }
}
