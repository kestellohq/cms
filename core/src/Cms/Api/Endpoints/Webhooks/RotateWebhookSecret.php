<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Webhooks;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;

/** A new secret, shown once. Deliveries carry a signature with the old one too for 24 hours, so the receiver can switch over. */
final class RotateWebhookSecret extends WebhookEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/webhooks/{id}/secret')->where('id', '\d{1,18}');
    }
    public function response(): ?Schema
    {
        return Schema::object(['secret' => Schema::string(), 'previous_valid_until' => Schema::string()->format('date-time')->nullable()]);
    }
    public function summary(): string
    {
        return "Rotate a webhook's secret";
    }
    public function requiresRecentAuth(): bool
    {
        return true;
    }

    public function handle(ApiRequest $request): mixed
    {
        $id = $request->intParam('id');
        $secret = self::attempt(fn() => self::webhooks()->rotate($id));
        self::cms()->audit->record('webhook.secret_rotated', ['webhook', $id], [], $request->userId());

        return ['secret' => $secret, 'previous_valid_until' => self::webhooks()->find($id)['secret_rotating_until'] ?? null];
    }
}
