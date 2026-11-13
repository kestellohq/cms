<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Content;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Content\Entry;

/** Move to the trash, or with ?permanent=true delete an entry that is already in the trash. */
final class DeleteEntry extends ContentEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::delete('/content/{type}/entries/{id}')->where('type', self::TYPE)->where('id', '\d{1,18}');
    }
    public function scopes(): array
    {
        return ['content:write'];
    }
    public function problems(): array
    {
        return [...parent::problems(), 409];
    }

    public function summary(): string
    {
        return 'Trash or permanently delete an entry';
    }
    public function query(): ?Schema
    {
        return Schema::object(['permanent' => Schema::bool()->default(false)]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $type = self::type($request);
        $entry = self::entry($request, $type, withTrashed: true);
        if ($request->query('permanent')) {
            self::attempt(fn() => self::entries()->destroy($entry, $request->principal));
            return null;
        }
        if ($entry->trashed()) {
            throw ApiProblem::conflict('Already in the trash.');
        }
        self::attempt(fn() => self::entries()->trash($entry, $request->principal));

        return self::present($request, $type, $entry, full: false);
    }
}
