<?php

declare(strict_types=1);

namespace Kestello\Cms\Package\Registry;

use Kestello\Cms\Health\HealthResult;

/**
 * The Site Health check "Security advisories": the registry's advisories that apply to what
 * this site runs, Kestello itself and the plugins and themes installed from the registry.
 *
 *   InstalledAdvisories::find($advisories, $installed, $coreVersion)   what applies, most severe first
 *   InstalledAdvisories::result($found)                                the check's answer
 *
 * Only packages installed from the registry are matched: a package uploaded as a zip can share a
 * name with an unrelated one in the registry. High and critical advisories make the check
 * critical, so administrators are told by Site Health alerts; low and medium ones are recommended.
 *
 * @internal
 */
final class InstalledAdvisories
{
    /** The Updates screen, where every fix is installed. */
    public const array ACTION = ['Open Updates', '/admin/system/updates'];

    /**
     * @param list<array{type: string, slug: string, name: string, version: string, from_registry: bool}> $installed
     * @return list<array{name: string, version: string, advisory: array{id: string, type: string, package: string, affected: string, fixed: ?string, severity: string, title: string, url: string, published_at: int}}>
     */
    public static function find(Advisories $advisories, array $installed, string $coreVersion): array
    {
        $found = [];
        foreach ($advisories->affecting('core', 'core', $coreVersion) as $a) {
            $found[] = ['name' => 'Kestello', 'version' => $coreVersion, 'advisory' => $a];
        }
        foreach ($installed as $p) {
            if (!$p['from_registry']) {
                continue;
            }
            foreach ($advisories->affecting($p['type'], $p['slug'], $p['version']) as $a) {
                $found[] = ['name' => $p['name'], 'version' => $p['version'], 'advisory' => $a];
            }
        }
        $rank = array_flip(Advisories::SEVERITIES);
        usort($found, static fn(array $x, array $y): int => [$rank[$y['advisory']['severity']] ?? 0, $y['advisory']['published_at']] <=> [$rank[$x['advisory']['severity']] ?? 0, $x['advisory']['published_at']]);

        return $found;
    }

    /**
     * @param list<array{name: string, version: string, advisory: array{id: string, severity: string, title: string, fixed: ?string}}> $found
     */
    public static function result(array $found): HealthResult
    {
        if ($found === []) {
            return HealthResult::good('No security advisory applies to this Kestello version or to the plugins and themes installed from the registry.');
        }
        $details = [];
        $lines = [];
        foreach ($found as $f) {
            $a = $f['advisory'];
            $details[$a['id']] = "{$f['name']} {$f['version']} ({$a['severity']})";
            $lines[] = "{$f['name']} {$f['version']}: {$a['title']} ({$a['id']}, {$a['severity']}). "
                . ($a['fixed'] !== null ? "Update to {$a['fixed']} or later." : 'No fixed version yet: consider deactivating it.');
        }
        $serious = array_filter($found, static fn(array $f): bool => in_array($f['advisory']['severity'], ['high', 'critical'], true));
        $message = (count($found) === 1 ? 'A security advisory applies to this site. ' : count($found) . ' security advisories apply to this site. ') . implode(' ', $lines);

        return $serious !== []
            ? HealthResult::critical($message, $details, self::ACTION)
            : HealthResult::recommended($message, $details, self::ACTION);
    }

    /** When the registry's advisories can't be read: say so, without crying wolf. */
    public static function unknown(?string $error): HealthResult
    {
        return $error === null
            ? HealthResult::good('Security advisories are checked once the site has read the plugin and theme registry.')
            : HealthResult::recommended("Security advisories could not be checked: {$error}");
    }
}
