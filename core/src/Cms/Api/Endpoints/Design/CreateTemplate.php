<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Design;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;

/** A new template, part or pattern of the site's own (saved as a draft unless "publish"). */
final class CreateTemplate extends TemplateEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/design/templates/{kind}')->where('kind', self::KIND);
    }
    public function summary(): string
    {
        return 'Create a template, part or pattern';
    }

    public function body(): ?Schema
    {
        return SaveTemplate::schema(['key' => Schema::string()->max(61)]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $kind = (string) $request->param('kind');
        $key = (string) $request->body['key'];
        $publish = (bool) ($request->body['publish'] ?? false);
        $this->attempt(fn() => $this->templates()->save($kind, $key, $request->body, $request->userId(), $publish, create: true));
        $this->changed($publish ? 'published' : 'created', $kind, $key, $request->userId());

        return $this->document($request, $kind, $key);
    }
}
