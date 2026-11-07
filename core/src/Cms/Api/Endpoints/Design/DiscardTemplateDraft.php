<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Design;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;

/** Throw a draft away; the published version stays. A draft of something never published goes entirely. */
final class DiscardTemplateDraft extends TemplateEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::delete('/design/templates/{kind}/{key}/draft')->where('kind', self::KIND)->where('key', self::KEY);
    }
    public function summary(): string
    {
        return 'Discard a draft';
    }

    public function handle(ApiRequest $request): mixed
    {
        $kind = (string) $request->param('kind');
        $key = (string) $request->param('key');
        if (!$this->templates()->discardDraft($kind, $key)) {
            throw ApiProblem::notFound('There is no draft.');
        }
        try {
            return $this->document($request, $kind, $key);
        } catch (ApiProblem) {
            return ['kind' => $kind, 'key' => $key, 'deleted' => true];
        }
    }
}
