<?php

declare(strict_types=1);

namespace Kestello\Cms\Extension;

/**
 * Finds packages on disk. Invalid packages are reported, never loaded.
 */
final class PackageRepository
{
    /** @var list<string> */
    private array $errors = [];

    /**
     * @return array<string, Package> slug => package, sorted by slug
     */
    public function discover(string $directory, PackageType $type): array
    {
        $packages = [];

        foreach (glob(rtrim($directory, '/') . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (str_starts_with(basename($dir), '.')) {
                continue;
            }

            try {
                $manifest = Manifest::load($dir, $type);
                $packages[$manifest->slug] = new Package($manifest, $dir);
            } catch (ManifestException $e) {
                $this->errors[] = $e->getMessage();
            }
        }

        ksort($packages);

        return $packages;
    }

    /**
     * @return list<string>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
