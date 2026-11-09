<?php

declare(strict_types=1);

namespace Kestello\Cms\Fleet;

use Kestello\Cms\Cms;
use Kestello\Cms\Extension\Provider;
use Kestello\Framework\Http\Client\HttpClient;
use Kestello\Support\AppKey;

/**
 * Core module: the fleet. Every site can report itself (GET /api/v1/fleet/report, "fleet" scope);
 * a site with app.fleet.enabled also gets Site → Fleet, which watches other sites with their
 * fleet tokens and asks each for its report every hour.
 */
final class FleetModule extends Provider
{
    public function boot(Cms $cms): void
    {
        $cms->container->singleton(FleetSites::class, static fn(): FleetSites => new FleetSites($cms->container->make(HttpClient::class), AppKey::secretStore()));
        if ($cms->config->get('app.fleet.enabled', false) !== true) {
            return;
        }
        $cms->schedule->call(static function () use ($cms): string {
            $r = $cms->container->make(FleetSites::class)->checkAll();

            return "{$r['checked']} fleet sites checked, {$r['failed']} failed.";
        }, 'fleet.check')->hourly()->description('Asks every site in the fleet for its report.');
        $cms->admin->page('system/fleet', '@core/admin/system/Fleet', 'Fleet', 'fleet.manage', ['label' => 'Fleet', 'icon' => 'squares-four', 'group' => 'site', 'order' => 90]);
    }
}
