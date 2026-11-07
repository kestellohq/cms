<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Content;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Content\Term;

/** Delete a term (entries lose it; child terms move up a level). */
final class DeleteTerm extends TermEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::delete('/taxonomies/{taxonomy}/terms/{id}')->where('taxonomy', self::TYPE)->where('id', '\d{1,18}');
    }
    public function scopes(): array
    {
        return ['content:write'];
    }
    public function summary(): string
    {
        return 'Delete a term';
    }

    public function handle(ApiRequest $request): mixed
    {
        $tax = self::taxonomy($request, manage: true);
        self::attempt(fn() => self::terms()->delete($tax, self::term($request, $tax), $request->principal));

        return null;
    }
}
