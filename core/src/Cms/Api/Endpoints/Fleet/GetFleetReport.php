<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Fleet;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Cms;
use Kestello\Cms\Fleet\FleetReport;

/**
 * What this site tells a fleet console: Kestello version and update, plugin and theme updates
 * and license problems, Site Health and the last backup. A token with only the "fleet" scope
 * reads this and nothing else.
 */
final class GetFleetReport extends Endpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/fleet/report');
    }
    public function summary(): string
    {
        return 'Report this site to a fleet console';
    }
    public function capability(): ?string
    {
        return 'system.manage';
    }
    public function scopes(): array
    {
        return ['fleet'];
    }
    public function tags(): array
    {
        return ['fleet'];
    }

    public function handle(ApiRequest $request): mixed
    {
        return FleetReport::build(Cms::instance() ?? throw new \LogicException('Not booted.'));
    }
}
