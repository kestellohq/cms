<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Webhooks;

use Closure;
use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Cms;
use Kestello\Cms\Webhooks\WebhookException;
use Kestello\Cms\Webhooks\Webhooks;

/** Shared by the /webhooks endpoints: capability webhooks.manage, scope webhooks:manage. */
abstract class WebhookEndpoint extends Endpoint
{
    public function capability(): ?string
    {
        return 'webhooks.manage';
    }
    public function scopes(): array
    {
        return ['webhooks:manage'];
    }
    public function tags(): array
    {
        return ['webhooks'];
    }

    protected static function webhooks(): Webhooks
    {
        return Cms::instance()->webhooks;
    }

    protected static function cms(): Cms
    {
        return Cms::instance();
    }

    protected static function attempt(Closure $work): mixed
    {
        try {
            return $work();
        } catch (WebhookException $e) {
            throw $e->status === 404 ? ApiProblem::notFound($e->getMessage()) : ApiProblem::validation($e->errors);
        }
    }

    public static function webhookSchema(): Schema
    {
        $date = static fn() => Schema::string()->format('date-time')->nullable();

        return Schema::object([
            'id' => Schema::int(),
            'name' => Schema::string(),
            'url' => Schema::string()->format('uri'),
            'events' => Schema::list(Schema::string())->description('Event names, or ["*"] for every event'),
            'active' => Schema::bool(),
            'disabled_reason' => Schema::string()->nullable()->description('Set when it was turned off after failures'),
            'failure_streak' => Schema::int(),
            'secret_rotating_until' => $date()->description('Until then, deliveries are also signed with the previous secret'),
            'last_success_at' => $date(),
            'last_failure_at' => $date(),
            'last_delivery' => Schema::object([
                'id' => Schema::int(), 'event' => Schema::string(), 'status' => Schema::string(),
                'response_status' => Schema::int()->nullable(), 'created_at' => $date(),
            ])->nullable(),
            'created_at' => $date(),
            'updated_at' => $date(),
        ]);
    }

    public static function deliverySchema(): Schema
    {
        $date = static fn() => Schema::string()->format('date-time')->nullable();

        return Schema::object([
            'id' => Schema::int(),
            'webhook_id' => Schema::int(),
            'event_id' => Schema::string()->description('evt_…, the same for every webhook and every redelivery of one event'),
            'event' => Schema::string(),
            'status' => Schema::enum(['pending', 'sending', 'succeeded', 'retrying', 'failed']),
            'attempts' => Schema::int(),
            'response_status' => Schema::int()->nullable(),
            'response_body' => Schema::string()->nullable()->description('The start of the answer, credentials hidden'),
            'error' => Schema::string()->nullable(),
            'duration_ms' => Schema::int()->nullable(),
            'payload' => Schema::any()->description('The message that was sent'),
            'next_attempt_at' => $date(),
            'created_at' => $date(),
            'delivered_at' => $date(),
        ]);
    }

    protected static function input(): Schema
    {
        return Schema::object([
            'name' => Schema::string()->max(100)->optional(),
            'url' => Schema::string()->max(2048)->optional(),
            'events' => Schema::list(Schema::string()->max(100))->optional(),
            'active' => Schema::bool()->optional(),
        ]);
    }
}
