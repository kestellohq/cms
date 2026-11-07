<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Webhooks;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;

/** Change a webhook. Turning a webhook that was turned off after failures back on starts its failure count afresh. */
final class UpdateWebhook extends WebhookEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::patch('/webhooks/{id}')->where('id', '\d{1,18}');
    }
    public function response(): ?Schema
    {
        return self::webhookSchema();
    }
    public function summary(): string
    {
        return 'Change a webhook';
    }
    public function requiresRecentAuth(): bool
    {
        return true;
    }
    public function body(): ?Schema
    {
        return self::input();
    }

    public function handle(ApiRequest $request): mixed
    {
        $input = array_intersect_key($request->body, array_flip(['name', 'url', 'events', 'active']));
        $hook = self::attempt(fn() => self::webhooks()->update($request->intParam('id'), $input));
        self::cms()->audit->record('webhook.updated', ['webhook', $hook['id']], ['changed' => array_keys($input), 'url' => $hook['url'], 'active' => $hook['active']], $request->userId());

        return $hook;
    }
}
