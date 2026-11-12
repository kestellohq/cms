<?php

declare(strict_types=1);

namespace Kestello\Cms\Package\Registry;

use Kestello\Framework\Support\Semver;

/**
 * One package in full (registry-format.md §6): p/<slug>/<sha256>.json. Not signed itself: the
 * index pins its hash, and it must agree with the index entry it was fetched for.
 *
 *   $package = PackageFile::parse($bytes, $index->packages['events']);
 *   $package['versions']   newest first
 *
 * A version that can't be read is left out; anything wrong with the package as a whole, or a
 * version listed twice, refuses the file.
 *
 * @internal
 */
final class PackageFile
{
    public const int MAX_VERSIONS = 200;
    public const int MAX_ZIP_BYTES = 128 << 20;

    /**
     * @param array{slug: string, type: string, organisation: string, paid: bool, file: FileRef} $entry the index entry
     * @return array{
     *   slug: string, type: string, organisation: string, name: string, summary: string, description: string, license: string,
     *   homepage: ?string, support: ?string, source: ?string, categories: list<string>, icon: ?string,
     *   screenshots: list<array{image: string, caption: string}>,
     *   commerce: ?array{model: string, price: string, store_url: string, license: string},
     *   versions: list<array{version: string, prerelease: bool, released_at: int, requires_core: string, requires_php: ?string, extensions: list<string>, dependencies: array<string, string>, permissions: array<mixed>, notes: string, download: ?string, sha256: string, size: int, key: string, withdrawn: ?array{at: int, reason: string}}>
     * }
     * @throws RegistryException
     */
    public static function parse(string $bytes, array $entry): array
    {
        $slug = $entry['slug'];
        $d = Fields::json($entry['file']->check($bytes), "file for {$slug}");
        if (($d['format'] ?? null) !== 1 || ($d['slug'] ?? null) !== $slug || ($d['type'] ?? null) !== $entry['type'] || ($d['organisation'] ?? null) !== $entry['organisation']) {
            throw new RegistryException("The registry's file for {$slug} doesn't agree with the list (format, name, type or organisation).");
        }

        $name = Fields::line($d['name'] ?? null, 80);
        $summary = Fields::line($d['summary'] ?? null, 160);
        $description = Fields::text($d['description'] ?? null, 20_000);
        $license = Fields::line($d['license'] ?? null, 100);
        $categories = Fields::listOf($d['categories'] ?? null, 10, Fields::packageName(...));
        $screenshots = Fields::listOf($d['screenshots'] ?? null, 10, self::screenshot(...));
        $commerce = self::commerce($d['commerce'] ?? null);
        $urls = [];
        foreach (['homepage', 'support', 'source'] as $k) {
            $urls[$k] = isset($d[$k]) ? Fields::https($d[$k]) : null;
            if (isset($d[$k]) && $urls[$k] === null) {
                throw new RegistryException("The registry's file for {$slug} has a {$k} address that isn't https.");
            }
        }
        $icon = isset($d['icon']) ? Fields::path($d['icon']) : null;
        if ($name === null || $summary === null || $description === null || $license === null || $categories === null || $screenshots === null
            || (isset($d['icon']) && $icon === null) || $commerce === false || ($commerce !== null) !== $entry['paid']) {
            throw new RegistryException("The registry's file for {$slug} can't be read.");
        }

        $list = $d['versions'] ?? null;
        if (!is_array($list) || !array_is_list($list) || count($list) > self::MAX_VERSIONS) {
            throw new RegistryException("The registry's file for {$slug} has no valid list of versions (at most " . self::MAX_VERSIONS . ').');
        }
        $versions = [];
        foreach ($list as $v) {
            $version = is_array($v) ? self::version($v, $commerce !== null) : null;
            if ($version === null) {
                continue;
            }
            if (isset($versions[$version['version']])) {
                throw new RegistryException("The registry's file for {$slug} lists version {$version['version']} twice.");
            }
            $versions[$version['version']] = $version;
        }
        $versions = array_values($versions);
        usort($versions, static fn(array $a, array $b): int => Semver::compare($b['version'], $a['version']));

        return [
            'slug' => $slug, 'type' => $entry['type'], 'organisation' => $entry['organisation'],
            'name' => $name, 'summary' => $summary, 'description' => $description, 'license' => $license,
            'homepage' => $urls['homepage'], 'support' => $urls['support'], 'source' => $urls['source'],
            'categories' => $categories, 'icon' => $icon, 'screenshots' => $screenshots,
            'commerce' => $commerce, 'versions' => $versions,
        ];
    }

    /** @return array{image: string, caption: string}|null */
    private static function screenshot(mixed $s): ?array
    {
        $image = is_array($s) ? Fields::path($s['image'] ?? null) : null;
        $caption = is_array($s) ? Fields::text($s['caption'] ?? null, 160) : null;

        return $image !== null && $caption !== null ? ['image' => $image, 'caption' => $caption] : null;
    }

    /**
     * null for a free package; false when it can't be read.
     *
     * @return array{model: string, price: string, store_url: string, license: string}|false|null
     */
    private static function commerce(mixed $c): array|false|null
    {
        if ($c === null) {
            return null;
        }
        if (!is_array($c)) {
            return false;
        }
        $price = Fields::line($c['price'] ?? null, 40);
        $store = Fields::https($c['store_url'] ?? null);
        // new models and license sources are new values, read by newer versions of Kestello
        if (($c['model'] ?? null) !== 'yearly-per-site' || !in_array($c['license'] ?? null, ['seller', 'marketplace'], true) || $price === null || $store === null) {
            return false;
        }

        return ['model' => 'yearly-per-site', 'price' => $price, 'store_url' => $store, 'license' => $c['license']];
    }

    /**
     * @param array<mixed> $v
     * @return array{version: string, prerelease: bool, released_at: int, requires_core: string, requires_php: ?string, extensions: list<string>, dependencies: array<string, string>, permissions: array<mixed>, notes: string, download: ?string, sha256: string, size: int, key: string, withdrawn: ?array{at: int, reason: string}}|null
     */
    private static function version(array $v, bool $paid): ?array
    {
        $version = Fields::version($v['version'] ?? null);
        $released = Fields::time($v['released_at'] ?? null);
        $requires = is_array($v['requires'] ?? null) ? $v['requires'] : [];
        $core = Fields::constraint($requires['core'] ?? null);
        $php = isset($requires['php']) ? Fields::constraint($requires['php']) : null;
        $extensions = Fields::listOf($requires['extensions'] ?? null, 30, static fn(mixed $e): ?string => is_string($e) && preg_match('/^[a-z0-9_]{1,40}$/i', $e) === 1 ? $e : null);
        $notes = Fields::text($v['notes'] ?? null, 20_000);
        // free versions are copied into the registry; paid ones come from the seller's feed (§5.4)
        $download = isset($v['download']) ? Fields::path($v['download']) : null;
        $sha = Fields::sha256($v['sha256'] ?? null);
        $size = $v['size'] ?? null;
        $key = Fields::keyId($v['key'] ?? null);
        $permissions = $v['permissions'] ?? [];
        if ($version === null || $released === null || $core === null || (isset($requires['php']) && $php === null) || $extensions === null || $notes === null
            || ($paid ? isset($v['download']) : $download === null) || $sha === null || !is_int($size) || $size < 1 || $size > self::MAX_ZIP_BYTES
            || $key === null || !is_array($permissions) || (array_is_list($permissions) && $permissions !== [])
            || (isset($v['dependencies']) && !is_array($v['dependencies']))) {
            return null;
        }

        $dependencies = [];
        foreach ($v['dependencies'] ?? [] as $dep => $constraint) {
            // the manifest's own rule: a dependency may be a package from outside the registry
            $clean = Fields::constraint($constraint);
            if (!is_string($dep) || strlen($dep) > 64 || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $dep) !== 1 || $clean === null) {
                return null;
            }
            $dependencies[$dep] = $clean;
        }

        $withdrawn = null;
        if (isset($v['withdrawn'])) {
            $at = is_array($v['withdrawn']) ? Fields::time($v['withdrawn']['at'] ?? null) : null;
            $reason = is_array($v['withdrawn']) ? Fields::line($v['withdrawn']['reason'] ?? null, 200) : null;
            if ($at === null || $reason === null) {
                return null;
            }
            $withdrawn = ['at' => $at, 'reason' => $reason];
        }

        return [
            'version' => $version, 'prerelease' => str_contains($version, '-'), 'released_at' => $released,
            'requires_core' => $core, 'requires_php' => $php, 'extensions' => $extensions, 'dependencies' => $dependencies,
            'permissions' => $permissions, 'notes' => $notes, 'download' => $download, 'sha256' => $sha, 'size' => $size,
            'key' => $key, 'withdrawn' => $withdrawn,
        ];
    }
}
