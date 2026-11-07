<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Webhooks;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;

/** Send a webhook.ping now and answer with the delivery, so you see at once what the receiver said. */
final class PingWebhook extends WebhookEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/webhooks/{id}/ping')->where('id', '\d{1,18}');
    }
    public function response(): ?Schema
    {
        return self::deliverySchema();
    }
    public function summary(): string
    {
        return 'Send a test message';
    }
    public function rateLimit(): ?array
    {
        return [10, 60];
    }

    public function handle(ApiRequest $request): mixed
    {
        return self::attempt(fn() => self::webhooks()->ping($request->intParam('id')));
    }
}
