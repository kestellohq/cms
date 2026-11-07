<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Content;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Content\Entry;
use Kestello\Framework\Database\Orm\Caster;

/** Keep the caller's unsaved changes (one autosave per user and entry). */
final class SaveAutosave extends ContentEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::put('/content/{type}/entries/{id}/autosave')->where('type', self::TYPE)->where('id', '\d{1,18}');
    }
    public function scopes(): array
    {
        return ['content:write'];
    }
    public function summary(): string
    {
        return 'Autosave unsaved changes';
    }
    public function rateLimit(): ?array
    {
        return [120, 60];
    }

    public function body(): ?Schema
    {
        return Schema::object([
            'title' => Schema::string()->max(255)->optional(),
            'slug' => Schema::string()->max(191)->optional(),
            'excerpt' => Schema::string()->max(1000)->raw()->nullable()->optional(),
            'data' => Schema::any()->optional(),
        ]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $type = self::type($request);
        $entry = self::entry($request, $type);
        $at = self::attempt(fn() => self::entries()->autosave($entry, $request->body, $request->principal));

        return ['saved_at' => Caster::present(Caster::toDate($at))];
    }
}
