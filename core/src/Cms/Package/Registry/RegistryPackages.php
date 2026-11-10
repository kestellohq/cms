<?php

declare(strict_types=1);

namespace Kestello\Cms\Package\Registry;

use Closure;
use Kestello\Cms\Package\Update\PackageFeed;
use RuntimeException;

/**
 * Versions to install or update to from the registry, and their downloads.
 *
 *   $packages->releases('plugin', 'events')         what this site could install, newest first
 *   $packages->choose('plugin', 'events', null)      the version to install (or update to from $installed)
 *   $packages->offer('plugin', 'events', null)       the same, with the newer version that needs another Kestello
 *   $packages->download('plugin', 'events', '1.5.0') the zip, in the temporary folder, size and SHA-256 checked
 *
 * Left out of releases(): withdrawn versions, paid versions (their zips come from the seller, a
 * later stage), and versions needing a PHP extension this server lacks. Which one is offered is
 * PackageFeed::choose(), as for every other source. Nothing here decides trust: the downloaded
 * zip still goes through PackageInstaller, which asks RegistryTrust about the exact bytes.
 *
 * @internal
 */
final class RegistryPackages
{
    /**
     * @param Closure(string $extension): bool|null $extensionLoaded (tests)
     */
    public function __construct(
        private readonly RegistryClient $client,
        private readonly string $core,
        private readonly string $php,
        private readonly string $tmpDir,
        private readonly ?Closure $extensionLoaded = null,
    ) {}

    /**
     * @return list<array{version: string, notes: string, published_at: string, prerelease: bool, requires_core: ?string, requires_php: ?string, download: string, sha256: string, size: int}>
     * @throws RegistryException when the registry can't be used, or doesn't list the package
     */
    public function releases(string $type, string $slug): array
    {
        $package = $this->client->package($slug);
        if ($package === null || $package['type'] !== $type) {
            throw new RegistryException("The registry doesn't list the {$type} \"{$slug}\".");
        }
        $out = [];
        foreach ($package['versions'] as $v) {
            if ($v['withdrawn'] !== null || $v['download'] === null || array_filter($v['extensions'], fn(string $e): bool => !$this->loaded($e)) !== []) {
                continue;
            }
            $out[] = [
                'version' => $v['version'], 'notes' => $v['notes'], 'published_at' => gmdate('Y-m-d\TH:i:s\Z', $v['released_at']),
                'prerelease' => $v['prerelease'], 'requires_core' => $v['requires_core'], 'requires_php' => $v['requires_php'],
                'download' => $v['download'], 'sha256' => $v['sha256'], 'size' => $v['size'],
            ];
        }

        return $out;
    }

    /**
     * The version to install, or to update to from $installed: the newest this Kestello and PHP can
     * run, stable unless $prereleases. Null when there is none.
     *
     * @throws RegistryException
     */
    public function choose(string $type, string $slug, ?string $installed, bool $prereleases = false): ?string
    {
        return $this->offer($type, $slug, $installed, $prereleases)['offer']['version'] ?? null;
    }

    /**
     * choose() with the reason when nothing is offered: needs_core is the newest version left out
     * because it needs another Kestello.
     *
     * @return array{offer: ?array{version: string, requires_core: ?string}, needs_core: ?array{version: string, requires_core: ?string}}
     * @throws RegistryException
     */
    public function offer(string $type, string $slug, ?string $installed, bool $prereleases = false): array
    {
        return PackageFeed::choose($this->releases($type, $slug), $installed ?? '0.0.0-0', $this->core, $this->php, $prereleases);
    }

    /**
     * Download a version for review and install. The caller removes the file.
     *
     * @throws RegistryException|RuntimeException
     */
    public function download(string $type, string $slug, string $version): string
    {
        $release = null;
        foreach ($this->releases($type, $slug) as $r) {
            if ($r['version'] === $version) {
                $release = $r;
            }
        }
        if ($release === null) {
            throw new RegistryException("The registry has no version {$version} of {$slug} that this site can install.");
        }
        if (!is_dir($this->tmpDir) && !mkdir($this->tmpDir, 0o700, true) && !is_dir($this->tmpDir)) {
            throw new RuntimeException("Could not create {$this->tmpDir}.");
        }
        $path = rtrim($this->tmpDir, '/') . "/{$slug}-" . bin2hex(random_bytes(6)) . '.zip';
        $this->client->download($release['download'], $release['size'], $release['sha256'], $path);

        return $path;
    }

    private function loaded(string $extension): bool
    {
        return $this->extensionLoaded !== null ? ($this->extensionLoaded)($extension) : extension_loaded($extension);
    }
}
