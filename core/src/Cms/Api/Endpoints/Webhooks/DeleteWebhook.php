<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Webhooks;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;

/** Delete a webhook and its delivery log. */
final class DeleteWebhook extends WebhookEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::delete('/webhooks/{id}')->where('id', '\d{1,18}');
    }
    public function summary(): string
    {
        return 'Delete a webhook';
    }

    public function status(): int
    {
        return 204;
    }

    public function handle(ApiRequest $request): mixed
    {
        $hook = self::webhooks()->find($request->intParam('id')) ?? throw ApiProblem::notFound('No such webhook.');
        self::webhooks()->delete($hook['id']);
        self::cms()->audit->record('webhook.deleted', ['webhook', $hook['id']], ['name' => $hook['name'], 'url' => $hook['url']], $request->userId());

        return null;
    }
}
