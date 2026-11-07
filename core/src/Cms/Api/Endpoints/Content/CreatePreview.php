<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Content;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Content\Previews;

/**
 * A preview link: kind "editor" (an hour, only for you, with your unsaved
 * changes) or "share" (1 to 30 days, for anyone with the link).
 */
final class CreatePreview extends ContentEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/content/{type}/entries/{id}/previews')->where('type', self::TYPE)->where('id', '\d{1,18}');
    }
    public function scopes(): array
    {
        return ['content:write'];
    }
    public function summary(): string
    {
        return 'Create a preview link';
    }

    public function body(): ?Schema
    {
        return Schema::object([
            'kind' => Schema::enum([Previews::EDITOR, Previews::SHARE])->default(Previews::EDITOR),
            'days' => Schema::int()->min(1)->max(Previews::MAX_DAYS)->default(7)->description('Share links only'),
            'label' => Schema::string()->max(100)->nullable()->optional()->description('Share links only: who the link is for'),
        ]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $type = self::type($request);
        $entry = self::entry($request, $type);
        $link = self::attempt(fn() => self::cms()->container->make(Previews::class)->create(
            $entry,
            $type,
            $request->principal,
            (string) $request->input('kind', Previews::EDITOR),
            (int) $request->input('days', 7),
            $request->input('label'),
        ));
        if ($link['kind'] === Previews::SHARE) {
            self::cms()->audit->record('content.preview_shared', ['entry', (int) $entry->getKey()], ['link' => $link['id'], 'expires_at' => $link['expires_at']], $request->userId());
        }

        return $link;
    }
}
