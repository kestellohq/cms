<?php

declare(strict_types=1);

namespace Kestello\Cms\Package\Update;

use Kestello\Cms\Cms;
use Kestello\Cms\Extension\PackageType;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Database\MigrationRunner;
use Throwable;

/** What a fresh boot says about a package, and whether that is healthy. */
final class UpdateProbe
{
    public const string OWNER = '/^(plugin|theme):[a-z0-9]+(?:-[a-z0-9]+)*$|^core$/';
    public const array PAGES = ['/', '/api/v1/status'];

    /**
     * The first boot of new core code runs core's pending migrations (plugins
     * run theirs while booting). Returns what went wrong, or null.
     */
    public static function prepare(Cms $cms, string $owner): ?string
    {
        if ($owner !== 'core') {
            return null;
        }
        try {
            (new MigrationRunner($cms->paths->coreMigrations()))->migrate();
            return null;
        } catch (Throwable $e) {
            return 'Core migrations failed: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
        }
    }

    /** @return array{owner: string, version: ?string, enabled: bool, running: bool, suspended: ?string, rejected: list<string>, pages: array<string, array{status: int, error: ?string}>} */
    public static function report(Cms $cms, string $owner, bool $pages = true): array
    {
        $ext = $cms->extensions();
        $version = null;
        $enabled = true;
        $running = true;
        $rejected = [];
        if ($owner !== 'core') {
            [$kind, $slug] = explode(':', $owner, 2);
            $type = PackageType::from($kind);
            $version = $ext->stateFor($type, $slug)?->version;
            if ($type === PackageType::Plugin) {
                $enabled = $ext->isPluginEnabled($slug);
                $running = isset($ext->activePlugins()[$slug]);
                $rejected = array_values($ext->rejectedPlugins()[$slug] ?? []);
            } else {
                $enabled = in_array($slug, array_map(static fn($p) => $p->slug(), $ext->themeChain()), true) || $ext->configuredTheme() === $slug;
                $running = in_array($slug, array_map(static fn($p) => $p->slug(), $ext->themeChain()), true);
            }
        } else {
            $version = $cms->version();
        }

        $results = [];
        if ($pages) {
            foreach (self::PAGES as $path) {
                try {
                    $response = $cms->routes->dispatch(new Request('GET', $path, $path, ['Accept' => 'text/html'], [], [], [], [], ['REMOTE_ADDR' => '127.0.0.1'], ''));
                    $results[$path] = ['status' => $response->statusCode(), 'error' => null];
                } catch (Throwable $e) {
                    $results[$path] = ['status' => 500, 'error' => $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')'];
                }
            }
        }

        return [
            'owner' => $owner,
            'plugins' => array_keys($ext->activePlugins()),
            'suspensions' => array_map(static fn(array $s): string => $s['reason'], $ext->suspensions()->all()),
            'version' => $version,
            'enabled' => $enabled,
            'running' => $running,
            'suspended' => $ext->suspensions()->reason($owner),
            'rejected' => $rejected,
            'pages' => $results,
        ];
    }

    /**
     * @param array<string, mixed> $report
     * @param list<string> $pluginsBefore plugins running before the update: each must still run
     * @return list<string> what is wrong (empty: healthy)
     */
    public static function problems(array $report, string $expectedVersion, bool $wasRunning, array $pluginsBefore = []): array
    {
        $problems = [];
        if (isset($report['probe_error'])) {
            return [(string) $report['probe_error']];
        }
        if (is_string($report['suspended'] ?? null)) {
            $problems[] = $report['suspended'];
        }
        foreach ((array) ($report['rejected'] ?? []) as $why) {
            $problems[] = 'It cannot run: ' . $why;
        }
        if ($wasRunning && ($report['enabled'] ?? false) && ($report['version'] ?? null) !== $expectedVersion && $problems === []) {
            $problems[] = "The upgrade to {$expectedVersion} did not finish (the site still records " . ($report['version'] ?? 'nothing') . ').';
        }
        if ($wasRunning && !($report['running'] ?? false) && $problems === []) {
            $problems[] = 'It did not start after the update.';
        }
        $running = (array) ($report['plugins'] ?? []);
        foreach ($pluginsBefore as $slug) {
            if (!in_array($slug, $running, true) && "plugin:{$slug}" !== ($report['owner'] ?? '')) {
                $why = $report['suspensions']["plugin:{$slug}"] ?? null;
                $problems[] = "The plugin {$slug} stopped running" . (is_string($why) ? ": {$why}" : '.');
            }
        }
        foreach ((array) ($report['pages'] ?? []) as $path => $page) {
            if ((int) ($page['status'] ?? 0) >= 500 || (int) ($page['status'] ?? 0) === 0) {
                $problems[] = "{$path} answered " . ((int) ($page['status'] ?? 0) ?: 'nothing') . (isset($page['error']) && $page['error'] !== null ? ": {$page['error']}" : '') . '.';
            }
        }

        return $problems;
    }
}
