<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Content;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Content\Entry;

/** Take an entry out of the trash (its status is kept). */
final class RestoreEntry extends ContentEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/content/{type}/entries/{id}/restore')->where('type', self::TYPE)->where('id', '\d{1,18}');
    }
    public function resource(): ?string
    {
        return \Kestello\Cms\Api\Resources\EntryResource::class;
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
        return 'Restore an entry from the trash';
    }

    public function handle(ApiRequest $request): mixed
    {
        $type = self::type($request);
        $entry = self::entry($request, $type, withTrashed: true);
        if (!$entry->trashed()) {
            throw ApiProblem::conflict('Not in the trash.');
        }
        self::attempt(fn() => self::entries()->restore($entry, $request->principal));

        return self::present($request, $type, $entry);
    }
}
