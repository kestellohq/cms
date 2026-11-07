<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Webhooks;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;

/** Send a delivery's message again now, as a new delivery with the same event id. */
final class RedeliverWebhook extends WebhookEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/webhooks/{id}/deliveries/{delivery}/redeliver')->where('id', '\d{1,18}')->where('delivery', '\d{1,18}');
    }
    public function response(): ?Schema
    {
        return self::deliverySchema();
    }
    public function summary(): string
    {
        return 'Send a delivery again';
    }
    public function rateLimit(): ?array
    {
        return [20, 60];
    }

    public function handle(ApiRequest $request): mixed
    {
        return self::attempt(fn() => self::webhooks()->redeliver($request->intParam('id'), (int) $request->param('delivery')));
    }
}
