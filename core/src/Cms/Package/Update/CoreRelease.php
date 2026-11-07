<?php

declare(strict_types=1);

namespace Kestello\Cms\Package\Update;

use Kestello\Cms\Content\ContentException;
use Kestello\Cms\Package\PackageSignature;
use Kestello\Framework\Support\Semver;
use Kestello\Support\Zip\Zip;
use Kestello\Support\Zip\ZipEntry;
use RuntimeException;
use Throwable;

/**
 * A core release zip: kestello-core-<version>.zip holding the core folder as
 * core/… (VERSION, core.json, bootstrap.php, src/…) and a SIGNATURE (the same
 * format as packages, PackageSignature).
 *
 * On a site it is unpacked to core/releases/<version>/, and core/current.php
 * (which the entry scripts read) is switched to it.
 */
final class CoreRelease
{
    private const int MAX_BYTES = 256 << 20;
    private const array REQUIRED = ['VERSION', 'core.json', 'bootstrap.php', 'src/Kernel.php'];

    /**
     * @return array{zip: string, sha256: string, version: string}
     */
    public static function build(string $coreDir, string $outDir): array
    {
        $coreDir = rtrim((string) realpath($coreDir), '/');
        foreach (self::REQUIRED as $file) {
            if ($coreDir === '' || !is_file("{$coreDir}/{$file}")) {
                throw new RuntimeException("That is not a core folder ({$file} is missing).");
            }
        }
        $version = trim((string) file_get_contents($coreDir . '/VERSION'));
        if (!Semver::isValid($version)) {
            throw new RuntimeException("VERSION \"{$version}\" is not a semantic version.");
        }
        if (!is_dir($outDir)) {
            mkdir($outDir, 0775, true);
        }
        $zipPath = rtrim($outDir, '/') . "/kestello-core-{$version}.zip";
        @unlink($zipPath);
        $zip = Zip::create($zipPath);
        $zip->addDirectory($coreDir, 'core', static function (string $relative): bool {
            // a site's own release folders and pointer never go into a release
            if ($relative === 'releases' || str_starts_with($relative, 'releases/') || $relative === 'current.php') {
                return false;
            }
            foreach (explode('/', $relative) as $part) {
                if ($part === 'node_modules' || ($part !== '' && $part[0] === '.' && $part !== '.vite')) {
                    return false;
                }
            }
            return true;
        });
        $zip->close();
        $hash = (string) hash_file('sha256', $zipPath);
        file_put_contents($zipPath . '.sha256', $hash . '  ' . basename($zipPath) . "\n");

        return ['zip' => $zipPath, 'sha256' => $hash, 'version' => $version];
    }

    /**
     * Unpack a release into $tmpRoot and describe it.
     *
     * @param array<string, array{name: string, public_key: string}> $trusted key id => publisher (PackageInstaller::trustedKeys())
     * @return array{root: string, dir: string, version: string, php: ?string, extensions: list<string>, signature: array<string, mixed>}
     * @throws ContentException
     */
    public static function stage(string $zipPath, string $tmpRoot, array $trusted): array
    {
        try {
            $zip = Zip::open($zipPath);
            $names = array_map(static fn(ZipEntry $e): string => $e->name, $zip->entries());
        } catch (Throwable $e) {
            throw ContentException::invalid(['file' => ['This is not a zip file: ' . $e->getMessage()]]);
        }
        foreach ($names as $name) {
            if ($name !== PackageSignature::FILE && !str_starts_with($name, 'core/')) {
                throw ContentException::invalid(['file' => ['This is not a Kestello core release (everything in it must be in a core folder).']]);
            }
        }
        foreach (self::REQUIRED as $file) {
            if (!in_array("core/{$file}", $names, true)) {
                throw ContentException::invalid(['file' => ["This is not a Kestello core release (core/{$file} is missing)."]]);
            }
        }
        $signature = PackageSignature::verify($zip, $trusted);

        $root = rtrim($tmpRoot, '/') . '/core-' . bin2hex(random_bytes(6));
        mkdir($root, 0700, true);
        try {
            $zip->extractTo($root, self::MAX_BYTES, 200, static fn(ZipEntry $e): bool => $e->name !== PackageSignature::FILE && !$e->isSymlink);
            $dir = $root . '/core';
            $version = trim((string) file_get_contents($dir . '/VERSION'));
            if (!Semver::isValid($version)) {
                throw new RuntimeException("its VERSION \"{$version}\" is not a semantic version");
            }
            $meta = json_decode((string) file_get_contents($dir . '/core.json'), true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($meta)) {
                throw new RuntimeException('core.json must contain a JSON object');
            }
        } catch (Throwable $e) {
            Files::removeTree($root);
            throw ContentException::invalid(['file' => ['The release could not be read: ' . $e->getMessage() . '.']]);
        }

        return [
            'root' => $root,
            'dir' => $dir,
            'version' => $version,
            'php' => is_string($meta['requires']['php'] ?? null) ? $meta['requires']['php'] : null,
            'extensions' => array_values(array_filter((array) ($meta['extensions'] ?? []), 'is_string')),
            'signature' => $signature,
        ];
    }
}
