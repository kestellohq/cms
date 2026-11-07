<?php

declare(strict_types=1);

namespace Kestello\Cms\Package\Update;

use Kestello\Framework\Http\Client\HttpClient;
use Throwable;

/**
 * Asks the site over HTTP (maintenance mode lets the bypass token through):
 * /system/update/probe for the package's state (for core it runs core's
 * migrations first), then the home page and the status endpoint. For hosts without proc_open. The site must reach itself
 * at app.updates.health_url (default app.url).
 */
final class HttpProbe implements HealthProbe
{
    public function __construct(private readonly HttpClient $http, private readonly string $base) {}

    public function run(string $owner, string $bypass): array
    {
        $base = rtrim($this->base, '/');
        if ($base === '') {
            return ['probe_error' => 'The site address is not set (app.url), so the update could not be checked.'];
        }
        $options = ['headers' => ['X-Kestello-Bypass' => $bypass, 'Accept' => 'text/html'], 'timeout' => 30, 'max_redirects' => 0, 'allow_private' => true];
        $pages = [];
        try {
            // first: it runs core's migrations, which the pages may need
            $state = $this->http->get($base . '/system/update/probe?owner=' . rawurlencode($owner), $options);
            foreach (UpdateProbe::PAGES as $path) {
                $response = $this->http->get($base . $path, $options);
                $pages[$path] = ['status' => $response->status, 'error' => null];
            }
        } catch (Throwable $e) {
            return ['probe_error' => "The site could not be reached at {$base} to check the update ({$e->getMessage()}). Set app.updates.health_url to an address the server can reach itself at."];
        }
        $report = json_decode($state->body, true);
        if ($state->status !== 200 || !is_array($report)) {
            return ['probe_error' => "The site's update check answered {$state->status}.", 'pages' => $pages];
        }

        return ['pages' => $pages] + $report;
    }
}
