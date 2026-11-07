<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\System;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Framework\Core\Response;
use Kestello\Framework\Database\DatabaseManager;

/** Versions and environment for support requests ("copy debug info"). No secrets or paths outside the site. */
final class GetSystemInfo extends SystemEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/system/info');
    }
    public function summary(): string
    {
        return 'Versions and environment';
    }

    public function handle(ApiRequest $request): mixed
    {
        $cms = $this->cms();
        $db = DatabaseManager::getConnection();
        try {
            $ext = $cms->extensions();
        } catch (\Throwable) {
            $ext = null; // early boot / tests
        }
        $plugins = [];
        foreach ($ext?->activePlugins() ?? [] as $slug => $package) {
            $plugins[] = ['slug' => (string) $slug, 'version' => $package->version()];
        }
        $theme = $ext?->activeTheme();

        return Response::json(['data' => [
            'kestello' => $cms->version(),
            'php' => ['version' => PHP_VERSION, 'sapi' => PHP_SAPI, 'extensions' => array_values(array_map('strtolower', get_loaded_extensions())), 'memory_limit' => ini_get('memory_limit'), 'upload_max_filesize' => ini_get('upload_max_filesize'), 'max_execution_time' => ini_get('max_execution_time')],
            'server' => ['software' => (string) ($_SERVER['SERVER_SOFTWARE'] ?? ''), 'os' => PHP_OS_FAMILY, 'timezone' => date_default_timezone_get()],
            'database' => ['driver' => $db->driver()],
            'drivers' => array_map(static fn(array $s) => ['subsystem' => $s['name'], 'driver' => $s['driver'], 'degraded' => $s['degraded'] !== null], $cms->container->make(\Kestello\Cms\Platform\PlatformManager::class)->describeAll()),
            'storage' => array_map(static fn(string $n) => ['disk' => $n, 'driver' => (string) ($cms->storage->config($n)['driver'] ?? '')], $cms->storage->names()),
            'locale' => \Kestello\Support\Translator::locale(),
            'debug' => (bool) $cms->config->get('app.debug', false),
            'plugins' => $plugins,
            'theme' => $theme === null ? null : ['slug' => $theme->slug(), 'version' => $theme->version()],
        ]]);
    }
}
