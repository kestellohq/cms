<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Design;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;

/** One template, part or pattern: published version, draft, history, where it is used. */
final class GetTemplate extends TemplateEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/design/templates/{kind}/{key}')->where('kind', self::KIND)->where('key', self::KEY);
    }
    public function scopes(): array
    {
        return ['content:read'];
    }
    public function summary(): string
    {
        return 'A template, part or pattern';
    }

    public function handle(ApiRequest $request): mixed
    {
        return $this->document($request, (string) $request->param('kind'), (string) $request->param('key'));
    }
}
