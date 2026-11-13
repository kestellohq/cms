<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Resources;

use Kestello\Cms\Api\Endpoints\Webhooks\WebhookEndpoint;
use Kestello\Cms\Api\Resource;
use Kestello\Cms\Api\Schema;
use Kestello\Contracts\Auth\Principal;

/**
 * A webhook, as Webhooks presents it, without its secret. Plugins add
 * fields with $cms->api->extend('webhooks').
 */
final class WebhookResource extends Resource
{
    public const string NAME = 'webhooks';

    protected function fields(Principal $principal): array
    {
        return array_intersect_key($this->row, self::properties());
    }

    /** @return array<string, Schema> */
    public static function properties(): array
    {
        return WebhookEndpoint::webhookSchema()->properties();
    }
}
