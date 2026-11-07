<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Webhooks;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;

/** The events webhooks can subscribe to: core ones and those plugins offer. */
final class ListWebhookEvents extends WebhookEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/webhooks/events');
    }
    public function response(): ?Schema
    {
        return Schema::object(['name' => Schema::string(), 'label' => Schema::string(), 'group' => Schema::string(), 'description' => Schema::string(), 'owner' => Schema::string()]);
    }
    public function summary(): string
    {
        return 'List the events webhooks can subscribe to';
    }

    public function handle(ApiRequest $request): mixed
    {
        $out = [];
        foreach (self::webhooks()->events() as $name => $e) {
            $out[] = ['name' => $name] + $e;
        }

        return $out;
    }
}
