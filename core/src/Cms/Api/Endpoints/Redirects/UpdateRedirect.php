<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Redirects;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Resources\RedirectResource;
use Kestello\Cms\Api\Schema;

/** Change a redirect rule. */
final class UpdateRedirect extends RedirectEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::patch('/redirects/{id}')->where('id', '\d{1,18}');
    }
    public function scopes(): array
    {
        return ['content:write'];
    }
    public function resource(): ?string
    {
        return RedirectResource::class;
    }

    public function summary(): string
    {
        return 'Change a redirect';
    }

    public function body(): ?Schema
    {
        return CreateRedirect::schema(true);
    }

    public function handle(ApiRequest $request): mixed
    {
        $id = (int) $request->param('id');
        $saved = self::attempt(fn() => self::redirects()->save($request->body, $id));
        self::audit($request, 'redirect.updated', $id, ['source' => $saved['source'], 'target' => $saved['target']]);

        return new RedirectResource($saved);
    }
}
