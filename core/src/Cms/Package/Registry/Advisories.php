<?php

declare(strict_types=1);

namespace Kestello\Cms\Package\Registry;

use Kestello\Framework\Support\Semver;

/**
 * Security advisories (registry-format.md §8): advisories/<sha256>.json, pinned by the index.
 * An advisory that can't be read is left out.
 *
 *   $advisories = Advisories::parse($bytes, $index->advisories);
 *   $advisories->affecting('plugin', 'events', '1.4.2')   the ones that apply, most severe first
 *
 * @internal
 */
final readonly class Advisories
{
    public const array SEVERITIES = ['low', 'medium', 'high', 'critical'];
    private const int MAX = 5000;

    /** @param list<array{id: string, type: string, package: string, affected: string, fixed: ?string, severity: string, title: string, url: string, published_at: int}> $all */
    private function __construct(public array $all) {}

    /** @throws RegistryException */
    public static function parse(string $bytes, FileRef $ref): self
    {
        $d = Fields::json($ref->check($bytes), 'advisories');
        $list = $d['advisories'] ?? null;
        if (($d['format'] ?? null) !== 1 || !is_array($list) || !array_is_list($list) || count($list) > self::MAX) {
            throw new RegistryException("The registry's advisories can't be read.");
        }
        $all = [];
        foreach ($list as $a) {
            $advisory = is_array($a) ? self::advisory($a) : null;
            if ($advisory !== null) {
                $all[] = $advisory;
            }
        }

        return new self($all);
    }

    /**
     * The advisories that apply to $version of a package, most severe first. $type is plugin,
     * theme, or core (with $slug "core").
     *
     * @return list<array{id: string, type: string, package: string, affected: string, fixed: ?string, severity: string, title: string, url: string, published_at: int}>
     */
    public function affecting(string $type, string $slug, string $version): array
    {
        $found = array_values(array_filter(
            $this->all,
            static fn(array $a): bool => $a['type'] === $type && $a['package'] === $slug && Semver::satisfiesRelease($version, $a['affected']),
        ));
        usort($found, static fn(array $a, array $b): int => array_search($b['severity'], self::SEVERITIES, true) <=> array_search($a['severity'], self::SEVERITIES, true));

        return $found;
    }

    /**
     * @param array<mixed> $a
     * @return array{id: string, type: string, package: string, affected: string, fixed: ?string, severity: string, title: string, url: string, published_at: int}|null
     */
    private static function advisory(array $a): ?array
    {
        $id = is_string($a['id'] ?? null) && preg_match('/^KSA-\d{4}-\d{4,6}$/', $a['id']) === 1 ? $a['id'] : null;
        $type = in_array($a['type'] ?? null, ['plugin', 'theme', 'core'], true) ? $a['type'] : null;
        $package = $type === 'core' ? (($a['package'] ?? null) === 'core' ? 'core' : null) : Fields::packageName($a['package'] ?? null);
        $affected = Fields::constraint($a['affected'] ?? null);
        $fixed = isset($a['fixed']) ? Fields::version($a['fixed']) : null;
        $severity = in_array($a['severity'] ?? null, self::SEVERITIES, true) ? $a['severity'] : null;
        $title = Fields::line($a['title'] ?? null, 200);
        $url = Fields::https($a['url'] ?? null);
        $published = Fields::time($a['published_at'] ?? null);
        if ($id === null || $type === null || $package === null || $affected === null || (isset($a['fixed']) && $fixed === null) || $severity === null || $title === null || $url === null || $published === null) {
            return null;
        }

        return ['id' => $id, 'type' => $type, 'package' => $package, 'affected' => $affected, 'fixed' => $fixed, 'severity' => $severity, 'title' => $title, 'url' => $url, 'published_at' => $published];
    }
}
