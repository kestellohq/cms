<?php

declare(strict_types=1);

namespace Kestello\Cms\Package\Update;

use Kestello\Framework\Support\Semver;
use UnexpectedValueException;

/**
 * A vendor's update feed for one plugin or theme ("updates": {"url": …} in its manifest),
 * and the choice of what to offer from any list of releases. Pure: no HTTP, no files.
 *
 *   {
 *     "format": 1, "type": "plugin", "slug": "events",
 *     "links": ["account.acme.example"],
 *     "releases": [{ "version": "1.5.0", "published_at": "…", "requires": {"core": "^0.1", "php": ">=8.4"},
 *                    "notes": "Markdown", "download": "https://…", "sha256": "<64 hex>" }]
 *   }
 *
 * A feed for another package, or one that isn't format 1, is refused whole. Single releases
 * that are malformed are left out, never guessed at.
 *
 * @internal
 */
final class PackageFeed
{
    public const int MAX_BYTES = 1 << 20;
    public const int MAX_RELEASES = 100;

    /**
     * @return array{releases: list<array{version: string, notes: string, published_at: string, prerelease: bool, requires_core: ?string, requires_php: ?string, zip: string, sha256: string}>, links: list<string>}
     * @throws UnexpectedValueException when the feed is not for this package or not a feed at all
     */
    public static function parse(string $body, string $type, string $slug): array
    {
        if (strlen($body) > self::MAX_BYTES) {
            throw new UnexpectedValueException('The update feed is larger than 1 MB.');
        }
        $json = json_decode($body, true, 16);
        if (!is_array($json) || ($json['format'] ?? null) !== 1 || !is_array($json['releases'] ?? null) || !array_is_list($json['releases'])) {
            throw new UnexpectedValueException('The address does not answer with a Kestello update feed (format 1).');
        }
        if (($json['type'] ?? null) !== $type || ($json['slug'] ?? null) !== $slug) {
            throw new UnexpectedValueException("The update feed is for another package, not the {$type} \"{$slug}\".");
        }
        if (count($json['releases']) > self::MAX_RELEASES) {
            throw new UnexpectedValueException('The update feed lists more than ' . self::MAX_RELEASES . ' releases.');
        }

        $releases = [];
        foreach ($json['releases'] as $r) {
            $release = is_array($r) ? self::release($r) : null;
            if ($release !== null) {
                $releases[] = $release;
            }
        }
        usort($releases, static fn(array $a, array $b): int => Semver::compare($b['version'], $a['version']));

        $links = [];
        foreach (is_array($json['links'] ?? null) ? array_slice($json['links'], 0, 10) : [] as $host) {
            if (is_string($host) && preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host) === 1) {
                $links[] = $host;
            }
        }

        return ['releases' => $releases, 'links' => $links];
    }

    /**
     * What to offer: the newest release above $installed that the running Kestello and PHP can
     * take, and the newest one above it that needs a newer Kestello (to say so).
     *
     * @param list<array{version: string, prerelease: bool, requires_core: ?string, requires_php?: ?string}> $releases newest first
     * @return array{offer: ?array<string, mixed>, needs_core: ?array<string, mixed>}
     */
    public static function choose(array $releases, string $installed, string $core, string $php, bool $prereleases): array
    {
        $needsCore = null;
        foreach ($releases as $release) {
            if (Semver::compare($release['version'], $installed) <= 0) {
                break;
            }
            if ($release['prerelease'] && !$prereleases) {
                continue;
            }
            if ($release['requires_core'] !== null && !Semver::satisfiesRelease($core, $release['requires_core'])) {
                $needsCore ??= $release;
                continue;
            }
            if (($release['requires_php'] ?? null) !== null && !Semver::satisfies($php, (string) $release['requires_php'])) {
                continue;
            }

            return ['offer' => $release, 'needs_core' => $needsCore];
        }

        return ['offer' => null, 'needs_core' => $needsCore];
    }

    /**
     * @param array<mixed> $r
     * @return array{version: string, notes: string, published_at: string, prerelease: bool, requires_core: ?string, requires_php: ?string, zip: string, sha256: string}|null
     */
    private static function release(array $r): ?array
    {
        $version = $r['version'] ?? null;
        $download = $r['download'] ?? null;
        $sha = $r['sha256'] ?? null;
        if (!is_string($version) || !ReleaseFeed::isVersion($version) || !is_string($download) || !self::isHttps($download) || !is_string($sha) || preg_match('/^[a-f0-9]{64}$/i', $sha) !== 1) {
            return null;
        }
        $requires = is_array($r['requires'] ?? null) ? $r['requires'] : [];
        $core = is_string($requires['core'] ?? null) && Semver::isValidConstraint($requires['core']) ? $requires['core'] : null;
        $php = is_string($requires['php'] ?? null) && Semver::isValidConstraint($requires['php']) ? $requires['php'] : null;

        return [
            'version' => $version,
            'notes' => is_string($r['notes'] ?? null) ? mb_substr($r['notes'], 0, 20_000) : '',
            'published_at' => is_string($r['published_at'] ?? null) ? substr($r['published_at'], 0, 40) : '',
            'prerelease' => str_contains($version, '-'),
            'requires_core' => $core,
            'requires_php' => $php,
            'zip' => $download,
            'sha256' => strtolower($sha),
        ];
    }

    /** An https address with no user name or password (the download may carry a signed query). */
    public static function isHttps(string $url): bool
    {
        $parts = parse_url($url);

        return strlen($url) <= 2000 && is_array($parts) && ($parts['scheme'] ?? '') === 'https' && ($parts['host'] ?? '') !== ''
            && !isset($parts['user']) && !isset($parts['pass']) && (!isset($parts['port']) || $parts['port'] === 443);
    }
}
