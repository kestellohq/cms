<?php

declare(strict_types=1);

namespace Kestello\Cms\Package\Update;

use Kestello\Framework\Support\Semver;

/**
 * Versions from a GitHub repository's releases (GET /repos/{repo}/releases): Kestello's own
 * (prefix "kestello-core") and those of plugins and themes that name a repository (prefix: the slug).
 *
 * A release counts when it is published (not a draft), its tag is v<version> or <prefix>-v<version>
 * (a repository holding several packages, such as kestellohq/plugins: meilisearch-v1.2.0), and it
 * carries <prefix>-<version>.zip and <prefix>-<version>.zip.sha256 downloaded from that repository.
 * Another package's releases in the same repository are left out by their tags and zip names.
 * Anything else is left out, never guessed at.
 *
 * @internal
 */
final class ReleaseFeed
{
    /**
     * @param mixed $json the decoded API response
     * @return list<array{version: string, name: string, notes: string, published_at: string, prerelease: bool, zip: string, sha256: string, page: string}>
     */
    public static function parse(mixed $json, string $repository, string $prefix = 'kestello-core'): array
    {
        if (!is_array($json) || !array_is_list($json)) {
            throw new \UnexpectedValueException('GitHub did not answer with a list of releases.');
        }
        $downloads = 'https://github.com/' . $repository . '/releases/download/';
        $out = [];
        foreach ($json as $release) {
            if (!is_array($release) || ($release['draft'] ?? true) !== false || !is_string($release['tag_name'] ?? null)) {
                continue;
            }
            $version = self::tagVersion($release['tag_name'], $prefix);
            if ($version === null) {
                continue;
            }
            $assets = [];
            foreach (is_array($release['assets'] ?? null) ? $release['assets'] : [] as $asset) {
                $url = is_array($asset) ? ($asset['browser_download_url'] ?? null) : null;
                if (is_string($url) && is_string($asset['name'] ?? null) && $url === $downloads . rawurlencode($release['tag_name']) . '/' . rawurlencode($asset['name'])) {
                    $assets[$asset['name']] = $url;
                }
            }
            $zip = "{$prefix}-{$version}.zip";
            if (!isset($assets[$zip], $assets["{$zip}.sha256"])) {
                continue;
            }
            $out[] = [
                'version' => $version,
                'name' => is_string($release['name'] ?? null) && $release['name'] !== '' ? $release['name'] : ($prefix === 'kestello-core' ? "Kestello {$version}" : $version),
                'notes' => is_string($release['body'] ?? null) ? $release['body'] : '',
                'published_at' => is_string($release['published_at'] ?? null) ? $release['published_at'] : '',
                'prerelease' => ($release['prerelease'] ?? false) === true || str_contains($version, '-'),
                'zip' => $assets[$zip],
                'sha256' => $assets["{$zip}.sha256"],
                'page' => is_string($release['html_url'] ?? null) && str_starts_with($release['html_url'], 'https://github.com/' . $repository . '/') ? $release['html_url'] : 'https://github.com/' . $repository . '/releases/tag/' . rawurlencode($release['tag_name']),
            ];
        }
        usort($out, static fn(array $a, array $b): int => Semver::compare($b['version'], $a['version']));

        return $out;
    }

    /** The version a tag names: v<version>, or <prefix>-v<version>; null for any other tag. */
    public static function tagVersion(string $tag, string $prefix): ?string
    {
        foreach (['v', "{$prefix}-v"] as $start) {
            if (str_starts_with($tag, $start) && self::isVersion(substr($tag, strlen($start)))) {
                return substr($tag, strlen($start));
            }
        }

        return null;
    }

    /**
     * The newest release above $running, if any (pre-releases only when asked).
     *
     * @param list<array{version: string, prerelease: bool}> $releases
     * @return array<string, mixed>|null
     */
    public static function newest(array $releases, string $running, bool $prereleases): ?array
    {
        foreach ($releases as $release) {
            if ($release['prerelease'] && !$prereleases) {
                continue;
            }

            return Semver::compare($release['version'], $running) > 0 ? $release : null;
        }

        return null;
    }

    /** The SHA-256 from a checksum file ("<hex>  <name>"), or null. */
    public static function checksum(string $file): ?string
    {
        return preg_match('/^([0-9a-f]{64})\b/i', trim($file), $m) === 1 ? strtolower($m[1]) : null;
    }

    public static function isVersion(string $version): bool
    {
        return preg_match('/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(-[0-9A-Za-z.-]+)?$/', $version) === 1 && Semver::isValid($version);
    }
}
