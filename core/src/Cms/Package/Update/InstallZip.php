<?php

declare(strict_types=1);

namespace Kestello\Cms\Package\Update;

use Kestello\Framework\Support\Semver;
use Kestello\Support\Zip\Zip;
use RuntimeException;

/**
 * The zip people download to install Kestello: kestello-<version>.zip, one
 * kestello/ folder holding a site that was never installed, ready to upload
 * to shared hosting and open in a browser.
 *
 * Built from an allowlist, so nothing from a developer's checkout (tests,
 * tooling, Docker, local plugins and themes, config, storage) can slip in.
 * Folders Kestello writes to are left out: file managers drop empty folders
 * when they unzip, so the installer creates them (InstallerController::prepareFolders).
 *
 * A core release zip (CoreRelease) is the other kind: it updates an installed site.
 */
final class InstallZip
{
    /** Top-level files, when present. */
    public const array FILES = ['.htaccess', 'LICENSE', 'README.md', 'CHANGELOG.md', 'SECURITY.md', 'bin/cms'];

    /** Folders, whole, minus what self::keep() refuses. */
    public const array FOLDERS = ['public', 'core', 'content/themes/base', 'content/themes/exhibit'];

    /** Files a site cannot run without; a build missing one is refused. */
    private const array REQUIRED = ['.htaccess', 'bin/cms', 'public/index.php', 'public/.htaccess', 'core/VERSION', 'core/bootstrap.php', 'core/resources/assets/build/.vite/manifest.json', 'content/themes/base/theme.json', 'content/themes/exhibit/theme.json'];

    /**
     * @return array{zip: string, sha256: string, version: string, files: int}
     */
    public static function build(string $root, string $outDir): array
    {
        $root = rtrim((string) realpath($root), '/');
        foreach (self::REQUIRED as $file) {
            if ($root === '' || !is_file("{$root}/{$file}")) {
                throw new RuntimeException("That is not a Kestello checkout ({$file} is missing).");
            }
        }
        $version = trim((string) file_get_contents($root . '/core/VERSION'));
        if (!Semver::isValid($version)) {
            throw new RuntimeException("core/VERSION \"{$version}\" is not a semantic version.");
        }
        if (!is_dir($outDir)) {
            mkdir($outDir, 0775, true);
        }

        $zipPath = rtrim($outDir, '/') . "/kestello-{$version}.zip";
        @unlink($zipPath);
        $zip = Zip::create($zipPath);
        $files = 0;
        foreach (self::FILES as $file) {
            if (is_file("{$root}/{$file}")) {
                $zip->addFile("{$root}/{$file}", "kestello/{$file}");
                $files++;
            }
        }
        foreach (self::FOLDERS as $folder) {
            $zip->addDirectory("{$root}/{$folder}", "kestello/{$folder}", static function (string $relative) use ($folder, &$files): bool {
                $keep = self::keep($folder, $relative);
                $files += (int) $keep;

                return $keep;
            });
        }
        $zip->close();

        $hash = (string) hash_file('sha256', $zipPath);
        file_put_contents($zipPath . '.sha256', $hash . '  ' . basename($zipPath) . "\n");

        return ['zip' => $zipPath, 'sha256' => $hash, 'version' => $version, 'files' => $files];
    }

    /**
     * Whether $relative (inside $folder) goes into the zip.
     */
    public static function keep(string $folder, string $relative): bool
    {
        $parts = explode('/', $relative);
        foreach ($parts as $part) {
            // dependencies and hidden files never ship; the Vite manifests and .htaccess do
            if ($part === 'node_modules' || ($part !== '' && $part[0] === '.' && $part !== '.vite' && $part !== '.htaccess')) {
                return false;
            }
        }

        return match ($folder) {
            // a site's own release folders and pointer, if built from one
            'core' => !($parts[0] === 'releases' || $relative === 'current.php'),
            // the theme's asset tooling is for its developers
            'content/themes/base' => $parts[0] !== 'tools',
            default => true,
        };
    }
}
