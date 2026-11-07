<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Endpoint;
use Kestello\Framework\Core\Config;
use Kestello\Framework\Settings\Settings;

/** Public site information for headless frontends. */
final class GetSite extends Endpoint
{
    public function __construct(private readonly Config $config) {}

    public function route(): ApiRoute
    {
        return ApiRoute::get('/site');
    }
    public function isPublic(): bool
    {
        return true;
    }
    public function summary(): string
    {
        return 'Public site information';
    }

    public function handle(ApiRequest $request): mixed
    {
        $settings = Settings::getInstance();

        return [
            'name' => (string) $settings->get('site_name', $this->config->get('app.name', 'Kestello')),
            'description' => (string) $settings->get('site_description', $this->config->get('app.description', '')),
            'url' => (string) $this->config->get('app.url', ''),
            'timezone' => (string) $this->config->get('app.timezone', 'UTC'),
        ];
    }
}
