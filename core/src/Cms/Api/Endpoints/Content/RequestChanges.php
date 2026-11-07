<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Content;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Content\Reviews;

/**
 * Send an entry in review back to draft with a note saying what to change.
 * Approving is publishing it (PATCH the entry with status published).
 */
final class RequestChanges extends ContentEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/content/{type}/entries/{id}/request-changes')->where('type', self::TYPE)->where('id', '\d{1,18}');
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
        return 'Send an entry in review back with a note';
    }
    public function body(): ?Schema
    {
        return Schema::object(['note' => Schema::string()->max(Reviews::MAX_BODY)]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $type = self::type($request);
        $entry = self::entry($request, $type);
        $entry = self::attempt(fn() => self::cms()->container->make(Reviews::class)->requestChanges($entry, (string) $request->input('note'), $request->principal));

        return self::present($request, $type, $entry);
    }
}
