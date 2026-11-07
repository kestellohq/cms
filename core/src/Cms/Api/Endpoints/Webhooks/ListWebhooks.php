<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Webhooks;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;

/** Every webhook, with its last delivery. Secrets are never shown again after they are made. */
final class ListWebhooks extends WebhookEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/webhooks');
    }
    public function response(): ?Schema
    {
        return self::webhookSchema();
    }
    public function summary(): string
    {
        return 'List webhooks';
    }

    public function handle(ApiRequest $request): mixed
    {
        return self::webhooks()->all();
    }
}
