<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Content;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Content\Revision;

/** Bring back a revision's content (a new revision is written; the status stays). */
final class RestoreRevision extends ContentEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/content/{type}/entries/{id}/revisions/{revision}/restore')->where('type', self::TYPE)->where('id', '\d{1,18}')->where('revision', '\d{1,18}');
    }
    public function resource(): ?string
    {
        return \Kestello\Cms\Api\Resources\EntryResource::class;
    }
    public function scopes(): array
    {
        return ['content:write'];
    }
    public function summary(): string
    {
        return 'Restore a revision';
    }

    public function handle(ApiRequest $request): mixed
    {
        $type = self::type($request);
        $entry = self::entry($request, $type);
        $revision = Revision::query()->where('entry_id', $entry->getKey())->find((int) $request->param('revision')) ?? throw ApiProblem::notFound('No such revision.');
        self::attempt(fn() => self::entries()->restoreRevision($entry, $revision, $request->principal));

        return self::present($request, $type, $entry);
    }
}
