<?php

declare(strict_types=1);

namespace Kestello\Cms\Package\Registry;

use Kestello\Framework\Support\Semver;
use Kestello\Support\Html;
use Kestello\Support\Markdown;

/**
 * What Add new shows: the registry's packages of one type, searched and filtered, and one package
 * in full. Pure: it reads the checked index and package files it is given and decides nothing about
 * trust (installs still go through PackageInstaller and RegistryTrust).
 *
 *   RegistryCatalog::browse($index, 'plugin', 'search', null, 1, '0.1.0', ['events' => '1.0.0'])
 *   RegistryCatalog::details($file, $organisation, $offer, $needsCore, $installed)
 *   RegistryCatalog::html($markdown)   registry text as safe HTML, without images
 *
 * Paid packages (stage B) are left out until sites can buy them.
 *
 * @internal
 */
final class RegistryCatalog
{
    public const int PER_PAGE = 30;
    public const int MAX_QUERY = 100;
    private const string PROFILE = 'registry';

    /**
     * @param array<string, string> $installed slug => installed version, of this type
     * @return array{total: int, page: int, per_page: int, categories: array<string, int>, packages: list<array<string, mixed>>}
     */
    public static function browse(RegistryIndex $index, string $type, string $query, ?string $category, int $page, string $core, array $installed): array
    {
        $words = self::words($query);
        $found = [];
        foreach ($index->packages as $entry) {
            if ($entry['type'] !== $type || $entry['paid'] || ($entry['stable'] ?? $entry['prerelease']) === null) {
                continue;
            }
            $organisation = $index->organisations[$entry['organisation']] ?? null;
            $score = self::score($entry, $organisation['name'] ?? $entry['organisation'], $words);
            if ($score !== null) {
                $found[] = ['entry' => $entry, 'organisation' => $organisation, 'score' => $score];
            }
        }

        // counted before the category filter, so every category shows what picking it would give
        $categories = [];
        foreach ($found as $f) {
            foreach ($f['entry']['categories'] as $c) {
                $categories[$c] = ($categories[$c] ?? 0) + 1;
            }
        }
        ksort($categories);
        if ($category !== null) {
            $found = array_values(array_filter($found, static fn(array $f): bool => in_array($category, $f['entry']['categories'], true)));
        }
        usort($found, static fn(array $a, array $b): int => [$b['score'], strtolower($a['entry']['name']), $a['entry']['slug']] <=> [$a['score'], strtolower($b['entry']['name']), $b['entry']['slug']]);

        $page = max(1, $page);
        $rows = array_slice($found, ($page - 1) * self::PER_PAGE, self::PER_PAGE);

        return [
            'total' => count($found),
            'page' => $page,
            'per_page' => self::PER_PAGE,
            'categories' => $categories,
            'packages' => array_map(static fn(array $f): array => self::row($f['entry'], $f['organisation'], $core, $installed[$f['entry']['slug']] ?? null), $rows),
        ];
    }

    /**
     * One package in full, for its details and the install button.
     *
     * @param array<string, mixed> $file PackageFile::parse()
     * @param array{id: string, name: string, verified: ?string}|null $organisation the index entry
     * @param array{version: string}|null $offer the newest version this site can run (RegistryPackages::offer() for a new install)
     * @param array{version: string, requires_core: ?string}|null $needsCore a newer version that needs another Kestello
     * @param array{version: string, active: bool}|null $installed
     * @return array<string, mixed>
     */
    public static function details(array $file, ?array $organisation, ?array $offer, ?array $needsCore, ?array $installed): array
    {
        $versions = [];
        foreach ($file['versions'] as $v) {
            $versions[$v['version']] = $v;
        }
        $offered = $offer !== null ? ($versions[$offer['version']] ?? null) : null;
        $current = $installed !== null ? ($versions[$installed['version']] ?? null) : null;

        return [
            'slug' => $file['slug'],
            'type' => $file['type'],
            'name' => $file['name'],
            'summary' => $file['summary'],
            'description_html' => self::html($file['description']),
            'license' => $file['license'],
            'homepage' => $file['homepage'],
            'support' => $file['support'],
            'source' => $file['source'],
            'categories' => $file['categories'],
            'organisation' => self::organisation($file['organisation'], $organisation),
            'offer' => $offered === null ? null : [
                'version' => $offered['version'],
                'prerelease' => $offered['prerelease'],
                'released_at' => gmdate('Y-m-d\TH:i:s\Z', $offered['released_at']),
                'requires' => ['core' => $offered['requires_core'], 'php' => $offered['requires_php'], 'extensions' => $offered['extensions']],
                'dependencies' => (object) $offered['dependencies'],
                'permissions' => (object) $offered['permissions'],
                'notes_html' => self::html($offered['notes']),
                'size' => $offered['size'],
            ],
            'needs_core' => $offer === null && $needsCore !== null ? ['version' => $needsCore['version'], 'requires' => $needsCore['requires_core']] : null,
            'installed' => $installed === null ? null : [
                'version' => $installed['version'],
                'active' => $installed['active'],
                'withdrawn' => $current['withdrawn']['reason'] ?? null,
            ],
            'update' => $installed !== null && $offered !== null && Semver::compare($offered['version'], $installed['version']) > 0,
            'versions' => array_map(static fn(array $v): array => [
                'version' => $v['version'],
                'released_at' => gmdate('Y-m-d\TH:i:s\Z', $v['released_at']),
                'prerelease' => $v['prerelease'],
                'withdrawn' => $v['withdrawn']['reason'] ?? null,
            ], array_slice(array_values($versions), 0, 10)),
        ];
    }

    /**
     * Registry text (a package's description or release notes) as safe HTML. Images are left out:
     * the admin never loads anything from a publisher's server (registry-format.md §6), so
     * browsing tells nobody which sites are looking. Links get rel="nofollow noopener noreferrer".
     */
    public static function html(string $markdown): string
    {
        if (trim($markdown) === '') {
            return '';
        }
        self::profile();

        return trim(Markdown::toHtml($markdown, self::PROFILE));
    }

    /** @return list<string> lower-case words of a search, at most 10 */
    public static function words(string $query): array
    {
        $query = mb_strtolower(mb_substr(trim($query), 0, self::MAX_QUERY));
        $words = preg_split('/[\s,]+/u', $query, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_slice(array_values(array_unique($words)), 0, 10);
    }

    /**
     * How well an entry matches every word: null when a word is missing. Names count most, then
     * the summary, then the organisation and categories.
     *
     * @param array{slug: string, name: string, summary: string, categories: list<string>} $entry
     * @param list<string> $words
     */
    public static function score(array $entry, string $organisation, array $words): ?int
    {
        if ($words === []) {
            return 0;
        }
        $name = mb_strtolower($entry['name']);
        $slug = $entry['slug'];
        $summary = mb_strtolower($entry['summary']);
        $other = mb_strtolower($organisation . ' ' . implode(' ', $entry['categories']));
        $score = 0;
        foreach ($words as $w) {
            $points = match (true) {
                $name === $w || $slug === $w => 100,
                str_starts_with($name, $w) || str_starts_with($slug, $w) => 50,
                str_contains($name, $w) || str_contains($slug, $w) => 20,
                str_contains($summary, $w) => 5,
                str_contains($other, $w) => 2,
                default => 0,
            };
            if ($points === 0) {
                return null;   // every word must be found somewhere
            }
            $score += $points;
        }

        return $score;
    }

    /**
     * @param array{slug: string, type: string, name: string, summary: string, organisation: string, categories: list<string>, stable: ?string, prerelease: ?string, requires_core: ?string, updated_at: int} $entry
     * @param array{id: string, name: string, verified: ?string}|null $organisation
     * @return array<string, mixed>
     */
    private static function row(array $entry, ?array $organisation, string $core, ?string $installed): array
    {
        $version = (string) ($entry['stable'] ?? $entry['prerelease']);

        return [
            'slug' => $entry['slug'],
            'name' => $entry['name'],
            'summary' => $entry['summary'],
            'organisation' => self::organisation($entry['organisation'], $organisation),
            'categories' => $entry['categories'],
            'version' => $version,
            'prerelease' => $entry['stable'] === null,
            'requires_core' => $entry['requires_core'],
            'compatible' => $entry['requires_core'] === null || Semver::satisfiesRelease($core, $entry['requires_core']),
            'updated_at' => gmdate('Y-m-d\TH:i:s\Z', $entry['updated_at']),
            'installed' => $installed,
            'update' => $installed !== null && Semver::isValid($installed) && Semver::compare($version, $installed) > 0,
        ];
    }

    /**
     * @param array{id: string, name: string, verified: ?string}|null $entry
     * @return array{id: string, name: string, verified: ?string}
     */
    private static function organisation(string $id, ?array $entry): array
    {
        return ['id' => $id, 'name' => $entry['name'] ?? $id, 'verified' => $entry['verified'] ?? null];
    }

    /** rich-text without images, and every link marked as not vouched for */
    private static function profile(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        Html::profile(self::PROFILE, [
            'elements' => [
                'p' => [], 'br' => [], 'strong' => [], 'b' => [], 'em' => [], 'i' => [], 's' => [], 'del' => [], 'code' => [], 'pre' => [],
                'blockquote' => [], 'ul' => [], 'ol' => ['start'], 'li' => [], 'a' => ['href', 'rel'], 'hr' => [],
                'h1' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => [],
                'table' => [], 'thead' => [], 'tbody' => [], 'tr' => [], 'th' => [], 'td' => [],
            ],
            'link_schemes' => ['https', 'http', 'mailto'],
            'image_schemes' => [],
            'nofollow' => true,
        ]);
        $done = true;
    }
}
