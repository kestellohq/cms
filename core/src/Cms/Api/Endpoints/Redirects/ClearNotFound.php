<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Redirects;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;
use Kestello\Framework\Database\QueryBuilder;

/** Forget logged addresses: one (?id=) or all of them. */
final class ClearNotFound extends RedirectEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::delete('/redirects/not-found');
    }
    public function scopes(): array
    {
        return ['content:write'];
    }
    public function summary(): string
    {
        return 'Clear the not-found log';
    }

    public function query(): ?Schema
    {
        return Schema::object(['id' => Schema::int()->min(1)->optional()]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $id = $request->query('id');
        $removed = QueryBuilder::table('not_found_log')->when($id !== null, fn($q) => $q->where('id', (int) $id))->delete();
        if ($id === null) {
            self::audit($request, 'redirect.log_cleared', null, ['removed' => $removed]);
        }

        return null;
    }
}
