<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Webhooks;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Resources\WebhookResource;

/** Every webhook, with its last delivery. Secrets are never shown again after they are made. */
final class ListWebhooks extends WebhookEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/webhooks');
    }
    public function resource(): ?string
    {
        return WebhookResource::class;
    }
    public function summary(): string
    {
        return 'List webhooks';
    }

    public function handle(ApiRequest $request): mixed
    {
        return WebhookResource::collection(self::webhooks()->all());
    }
}
