<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Design;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;

/** Publish the draft of a template, part or pattern as it was saved. */
final class PublishTemplateDraft extends TemplateEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/design/templates/{kind}/{key}/publish')->where('kind', self::KIND)->where('key', self::KEY);
    }
    public function summary(): string
    {
        return 'Publish a draft';
    }

    public function handle(ApiRequest $request): mixed
    {
        $kind = (string) $request->param('kind');
        $key = (string) $request->param('key');
        $this->attempt(fn() => $this->templates()->publishDraft($kind, $key, $request->userId()));
        $this->changed('published', $kind, $key, $request->userId());

        return $this->document($request, $kind, $key);
    }
}
