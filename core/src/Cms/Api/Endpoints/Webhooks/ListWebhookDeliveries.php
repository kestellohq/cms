<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Webhooks;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;

/** A webhook's deliveries, newest first, with what was sent and what came back. Page with meta.next_before_id. */
final class ListWebhookDeliveries extends WebhookEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/webhooks/{id}/deliveries')->where('id', '\d{1,18}');
    }
    public function response(): ?Schema
    {
        return self::deliverySchema();
    }
    public function summary(): string
    {
        return "List a webhook's deliveries";
    }

    public function query(): ?Schema
    {
        return Schema::object([
            'status' => Schema::enum(['pending', 'sending', 'succeeded', 'retrying', 'failed'])->optional(),
            'before_id' => Schema::int()->min(1)->optional(),
            'limit' => Schema::int()->min(1)->max(100)->default(25),
        ]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $id = $request->intParam('id');
        self::webhooks()->find($id) ?? throw ApiProblem::notFound('No such webhook.');
        $limit = (int) $request->query('limit', 25);
        $before = $request->query('before_id');
        $rows = self::webhooks()->deliveries($id, $limit, $before === null ? null : (int) $before, $request->query('status'));

        return \Kestello\Framework\Core\Response::json(['data' => $rows, 'meta' => ['next_before_id' => count($rows) === $limit ? end($rows)['id'] : null]]);
    }
}
