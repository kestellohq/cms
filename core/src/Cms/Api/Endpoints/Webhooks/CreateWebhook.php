<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Webhooks;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;

/**
 * Add a webhook. The answer holds its secret ("whsec_…"), the only time it
 * is shown: the receiver needs it to check signatures.
 */
final class CreateWebhook extends WebhookEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/webhooks');
    }
    public function response(): ?Schema
    {
        return self::webhookSchema()->extend(['secret' => Schema::string()->description('whsec_…, shown only now')]);
    }
    public function summary(): string
    {
        return 'Add a webhook';
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
        $made = self::attempt(fn() => self::webhooks()->create($request->body, $request->userId()));
        self::cms()->audit->record('webhook.created', ['webhook', $made['webhook']['id']], ['name' => $made['webhook']['name'], 'url' => $made['webhook']['url'], 'events' => $made['webhook']['events']], $request->userId());

        return $made['webhook'] + ['secret' => $made['secret']];
    }
}
