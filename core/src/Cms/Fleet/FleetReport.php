<?php

declare(strict_types=1);

namespace Kestello\Cms\Fleet;

use Kestello\Cms\Backup\Backups;
use Kestello\Cms\Cms;
use Kestello\Cms\Package\Update\PackageLicenses;
use Kestello\Cms\Package\Update\PackageUpdates;
use Kestello\Cms\Package\Update\UpdateChecker;
use Kestello\Framework\Settings\Settings;
use Throwable;

/**
 * What a site tells a fleet console about itself (GET /api/v1/fleet/report, "fleet" scope):
 * its Kestello version and whether a newer one is out, plugin and theme updates and license
 * problems, Site Health, and its last backup. Only what the site already knows: nothing here
 * asks another server. A part that fails is null; the rest is still sent.
 *
 *   build($cms)       the report of this site
 *   normalize($json)  a report from another site, checked field by field before it is stored
 *
 * @internal
 */
final class FleetReport
{
    public const int FORMAT = 1;

    /** At most this many health problems, updates and license problems are listed. */
    public const int MAX_ITEMS = 20;

    /**
     * @return array{format: int, generated_at: int, site: array{name: string, url: string}, kestello: array<string, mixed>, packages: ?array<string, mixed>, health: ?array<string, mixed>, backups: ?array<string, mixed>}
     */
    public static function build(Cms $cms, ?int $now = null): array
    {
        return [
            'format' => self::FORMAT,
            'generated_at' => $now ?? time(),
            'site' => ['name' => (string) Settings::getInstance()->get('site_name', 'Kestello'), 'url' => rtrim((string) $cms->config->get('app.url', ''), '/')],
            'kestello' => self::kestello($cms),
            'packages' => self::attempt(static fn(): array => self::packages($cms)),
            'health' => self::attempt(static function () use ($cms): array {
                $h = $cms->health->run();
                $problems = array_values(array_filter($h['checks'], static fn(array $c): bool => ($c['status'] ?? 'good') !== 'good'));

                return [
                    'status' => (string) $h['status'],
                    'counts' => array_map('intval', $h['counts']),
                    'problems' => array_map(static fn(array $c): array => ['label' => (string) $c['label'], 'status' => (string) $c['status']], array_slice($problems, 0, self::MAX_ITEMS)),
                ];
            }),
            'backups' => self::attempt(static function () use ($cms): array {
                $all = $cms->container->make(Backups::class)->all();

                return ['count' => count($all), 'last_at' => $all === [] ? null : max(array_map(static fn(array $b): int => (int) $b['created_at'], $all))];
            }),
        ];
    }

    /** @return array{version: string, php: string, update: ?array{version: string, prerelease: bool}, checked_at: ?int, error: ?string} */
    private static function kestello(Cms $cms): array
    {
        $last = self::attempt(static fn(): ?array => $cms->container->make(UpdateChecker::class)->last());
        $latest = is_array($last['latest'] ?? null) ? $last['latest'] : null;

        return [
            'version' => $cms->version(),
            'php' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION,
            'update' => $latest === null ? null : ['version' => (string) $latest['version'], 'prerelease' => (bool) ($latest['prerelease'] ?? false)],
            'checked_at' => isset($last['checked_at']) ? (int) $last['checked_at'] : null,
            'error' => is_string($last['error'] ?? null) ? $last['error'] : null,
        ];
    }

    /** @return array{installed: int, updates: list<array<string, string>>, licenses: list<array<string, string>>} */
    private static function packages(Cms $cms): array
    {
        $extensions = $cms->extensions();
        $all = [...array_values($extensions->plugins()), ...array_values($extensions->themes())];
        $checks = self::attempt(static fn(): array => $cms->container->make(PackageUpdates::class)->results()) ?? [];
        $licenses = $cms->container->has(PackageLicenses::class) ? $cms->container->make(PackageLicenses::class) : null;
        $updates = [];
        $problems = [];
        foreach ($all as $p) {
            $who = ['type' => $p->type()->value, 'slug' => $p->slug(), 'name' => $p->manifest->name];
            $check = $checks[$p->owner()] ?? null;
            if (is_array($check['offer'] ?? null)) {
                $updates[] = $who + ['version' => $p->version(), 'to' => (string) $check['offer']['version']];
            }
            if (($p->manifest->updates['license'] ?? false) === true) {
                if ($licenses !== null && !$licenses->describe($p->owner())['set']) {
                    $problems[] = $who + ['problem' => 'missing'];
                } elseif (is_string($check['renew_url'] ?? null)) {
                    $problems[] = $who + ['problem' => 'refused'];
                }
            }
        }

        return ['installed' => count($all), 'updates' => array_slice($updates, 0, self::MAX_ITEMS), 'licenses' => array_slice($problems, 0, self::MAX_ITEMS)];
    }

    /**
     * A report from another site, kept only as far as it has the expected shape: strings are
     * cut short, lists capped, anything else dropped.
     *
     * @return array<string, mixed>
     * @throws \UnexpectedValueException when it isn't a fleet report
     */
    public static function normalize(mixed $json): array
    {
        if (!is_array($json) || ($json['format'] ?? null) !== self::FORMAT || !is_array($json['kestello'] ?? null)) {
            throw new \UnexpectedValueException('The site did not answer with a fleet report.');
        }
        $s = static fn(mixed $v, int $max = 120): ?string => is_scalar($v) ? mb_substr((string) $v, 0, $max) : null;
        $i = static fn(mixed $v): ?int => is_int($v) ? $v : null;
        $list = static fn(mixed $v, \Closure $one): array => is_array($v) && array_is_list($v) ? array_values(array_filter(array_map($one, array_slice($v, 0, self::MAX_ITEMS)))) : [];
        $who = static fn(array $p): array => ['type' => $s($p['type'] ?? null, 10) ?? '', 'slug' => $s($p['slug'] ?? null, 64) ?? '', 'name' => $s($p['name'] ?? null, 80) ?? ''];

        $k = $json['kestello'];
        $update = is_array($k['update'] ?? null) ? ['version' => $s($k['update']['version'] ?? null, 50) ?? '', 'prerelease' => ($k['update']['prerelease'] ?? false) === true] : null;
        $p = is_array($json['packages'] ?? null) ? $json['packages'] : [];
        $h = is_array($json['health'] ?? null) ? $json['health'] : null;
        $b = is_array($json['backups'] ?? null) ? $json['backups'] : null;

        return [
            'generated_at' => $i($json['generated_at'] ?? null),
            'site' => ['name' => $s($json['site']['name'] ?? null, 120) ?? '', 'url' => $s($json['site']['url'] ?? null, 300) ?? ''],
            'kestello' => ['version' => $s($k['version'] ?? null, 50) ?? '?', 'php' => $s($k['php'] ?? null, 20) ?? '', 'update' => $update, 'checked_at' => $i($k['checked_at'] ?? null), 'error' => $s($k['error'] ?? null, 300)],
            'packages' => $p === [] ? null : [
                'installed' => $i($p['installed'] ?? null) ?? 0,
                'updates' => $list($p['updates'] ?? null, static fn(mixed $u): ?array => is_array($u) ? $who($u) + ['version' => $s($u['version'] ?? null, 50) ?? '', 'to' => $s($u['to'] ?? null, 50) ?? ''] : null),
                'licenses' => $list($p['licenses'] ?? null, static fn(mixed $l): ?array => is_array($l) && in_array($l['problem'] ?? null, ['missing', 'refused'], true) ? $who($l) + ['problem' => $l['problem']] : null),
            ],
            'health' => $h === null ? null : [
                'status' => in_array($h['status'] ?? null, ['good', 'recommended', 'critical'], true) ? $h['status'] : 'good',
                'counts' => ['good' => $i($h['counts']['good'] ?? null) ?? 0, 'recommended' => $i($h['counts']['recommended'] ?? null) ?? 0, 'critical' => $i($h['counts']['critical'] ?? null) ?? 0],
                'problems' => $list($h['problems'] ?? null, static fn(mixed $c): ?array => is_array($c) ? ['label' => $s($c['label'] ?? null) ?? '', 'status' => in_array($c['status'] ?? null, ['recommended', 'critical'], true) ? $c['status'] : 'recommended'] : null),
            ],
            'backups' => $b === null ? null : ['count' => $i($b['count'] ?? null) ?? 0, 'last_at' => $i($b['last_at'] ?? null)],
        ];
    }

    /**
     * @template T
     * @param \Closure(): T $fn
     * @return T|null
     */
    private static function attempt(\Closure $fn): mixed
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            error_log('[kestello] fleet report: ' . $e->getMessage());

            return null;
        }
    }
}
