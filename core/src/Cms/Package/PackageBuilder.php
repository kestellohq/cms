<?php

declare(strict_types=1);

namespace Kestello\Cms\Package;

use Kestello\Cms\Extension\Manifest;
use Kestello\Cms\Extension\PackageChecker;
use Kestello\Cms\Extension\PackageType;
use Kestello\Support\Zip\Zip;
use RuntimeException;

/**
 * Builds <slug>-<version>.zip (the package in a <slug>/ folder) and its
 * .sha256 from a plugin or theme directory. plugin:check errors stop the
 * build. What goes in is what a site runs: see ships().
 */
final class PackageBuilder
{
    /** @return array{zip: string, sha256: string, type: PackageType, findings: list<array<string, mixed>>} */
    public static function build(string $directory, string $outDir): array
    {
        $directory = rtrim((string) realpath($directory), '/');
        $type = is_file($directory . '/plugin.json') ? PackageType::Plugin : (is_file($directory . '/theme.json') ? PackageType::Theme : null);
        if ($directory === '' || $type === null) {
            throw new RuntimeException('There is no plugin.json or theme.json in that directory.');
        }
        $manifest = Manifest::load($directory, $type);
        $findings = (new PackageChecker())->check($directory, $type);
        $errors = array_filter($findings, static fn(array $f): bool => $f['level'] === 'error');
        if ($errors !== []) {
            throw new RuntimeException("plugin:check found problems:\n" . implode("\n", array_map(static fn(array $f): string => "  {$f['file']}:{$f['line']} {$f['message']}", $errors)));
        }
        if (!is_dir($outDir)) {
            mkdir($outDir, 0775, true);
        }
        $zipPath = rtrim($outDir, '/') . "/{$manifest->slug}-{$manifest->version}.zip";
        @unlink($zipPath);
        $zip = Zip::create($zipPath);
        $zip->addDirectory($directory, $manifest->slug, self::ships(...));
        $zip->close();
        $hash = (string) hash_file('sha256', $zipPath);
        file_put_contents($zipPath . '.sha256', $hash . '  ' . basename($zipPath) . "\n");

        return ['zip' => $zipPath, 'sha256' => $hash, 'type' => $type, 'findings' => $findings];
    }

    /**
     * Does this path (relative to the package folder) go in the zip? Development files stay
     * out: dotfiles (.env, .git …) except the built Vue manifest in .vite, node_modules
     * anywhere, and the package's own tests/ and dist/ (earlier builds) at the top.
     */
    public static function ships(string $relative): bool
    {
        $parts = explode('/', $relative);
        if (in_array($parts[0], ['tests', 'dist'], true)) {
            return false;
        }
        foreach ($parts as $part) {
            if ($part === 'node_modules' || ($part !== '' && $part[0] === '.' && $part !== '.vite')) {
                return false;
            }
        }

        return true;
    }
}
