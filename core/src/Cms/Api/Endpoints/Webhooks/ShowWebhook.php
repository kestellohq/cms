<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Webhooks;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Resources\WebhookResource;

final class ShowWebhook extends WebhookEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/webhooks/{id}')->where('id', '\d{1,18}');
    }
    public function resource(): ?string
    {
        return WebhookResource::class;
    }
    public function summary(): string
    {
        return 'Show a webhook';
    }

    public function handle(ApiRequest $request): mixed
    {
        return new WebhookResource(self::webhooks()->find($request->intParam('id')) ?? throw ApiProblem::notFound('No such webhook.'));
    }
}
