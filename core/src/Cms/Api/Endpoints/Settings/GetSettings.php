<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Settings;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Endpoint;
use Kestello\Framework\Core\Response;

/** Site settings with the schema of each group (the admin renders tabs from it). */
final class GetSettings extends Endpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/settings');
    }
    public function capability(): ?string
    {
        return 'settings.manage';
    }
    public function scopes(): array
    {
        return ['settings:write'];
    }
    public function summary(): string
    {
        return 'Read site settings';
    }
    public function tags(): array
    {
        return ['settings'];
    }

    public function handle(ApiRequest $request): mixed
    {
        return Response::json(['data' => (object) SiteSettings::read($request->principal), 'meta' => ['groups' => SiteSettings::describe($request->principal)]]);
    }
}
