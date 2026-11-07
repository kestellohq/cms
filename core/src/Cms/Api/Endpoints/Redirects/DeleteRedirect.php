<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Redirects;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;

/** Remove a redirect rule. */
final class DeleteRedirect extends RedirectEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::delete('/redirects/{id}')->where('id', '\d{1,18}');
    }
    public function scopes(): array
    {
        return ['content:write'];
    }
    public function summary(): string
    {
        return 'Remove a redirect';
    }

    public function handle(ApiRequest $request): mixed
    {
        $id = (int) $request->param('id');
        if (!self::redirects()->delete($id)) {
            throw ApiProblem::notFound('No such redirect.');
        }
        self::audit($request, 'redirect.deleted', $id);

        return null;
    }
}
