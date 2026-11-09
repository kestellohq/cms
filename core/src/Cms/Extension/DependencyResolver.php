<?php

declare(strict_types=1);

namespace Kestello\Cms\Extension;

use Kestello\Framework\Support\Semver;
use RuntimeException;

/**
 * Checks whether packages can run together and in which order to load them.
 */
final class DependencyResolver
{
    /**
     * @param list<string>|null $loadedExtensions defaults to get_loaded_extensions()
     */
    public function __construct(
        private readonly string $coreVersion,
        private readonly string $phpVersion = PHP_VERSION,
        private ?array $loadedExtensions = null,
    ) {
        $this->loadedExtensions = array_map('strtolower', $loadedExtensions ?? get_loaded_extensions());
    }

    /**
     * Reasons $package cannot run alongside $set (slug => Package, may include $package itself).
     *
     * @param array<string, Package> $set
     * @return list<string>
     */
    public function problems(Package $package, array $set): array
    {
        $m = $package->manifest;
        $problems = [];

        if (!$this->satisfies($this->coreVersion, $m->requiresCore)) {
            $problems[] = "requires Kestello {$m->requiresCore} (running {$this->coreVersion})";
        }

        if ($m->requiresPhp !== null && !$this->satisfies($this->phpVersion, $m->requiresPhp)) {
            $problems[] = "requires PHP {$m->requiresPhp} (running {$this->phpVersion})";
        }

        foreach ($m->extensions as $extension) {
            if (!in_array(strtolower($extension), $this->loadedExtensions ?? [], true)) {
                $problems[] = "requires the PHP extension \"{$extension}\"";
            }
        }

        foreach ($m->dependencies as $slug => $constraint) {
            $dep = $set[$slug] ?? null;

            if ($dep === null) {
                $problems[] = "requires plugin \"{$slug}\" {$constraint} (not active)";
            } elseif (!Semver::satisfies($dep->version(), $constraint)) {
                $problems[] = "requires plugin \"{$slug}\" {$constraint} (found {$dep->version()})";
            }
        }

        foreach ($set as $slug => $other) {
            if ($slug === $m->slug) {
                continue;
            }
            if (in_array($slug, $m->conflicts, true) || in_array($m->slug, $other->manifest->conflicts, true)) {
                $problems[] = "conflicts with plugin \"{$slug}\"";
            }
        }

        return $problems;
    }

    /**
     * Reduce $candidates to the largest set whose members can all run
     * together, dropping packages with problems (and, transitively, anything
     * that depended on them).
     *
     * @param array<string, Package> $candidates
     * @return array{0: array<string, Package>, 1: array<string, list<string>>} [runnable, rejected slug => problems]
     */
    public function runnable(array $candidates): array
    {
        $set = $candidates;
        $rejected = [];

        do {
            $changed = false;

            foreach ($set as $slug => $package) {
                $problems = $this->problems($package, $set);

                if ($problems !== []) {
                    $rejected[$slug] = $problems;
                    unset($set[$slug]);
                    $changed = true;
                }
            }
        } while ($changed);

        return [$set, $rejected];
    }

    /**
     * Topological order: dependencies before dependents, ties broken by slug.
     *
     * @param array<string, Package> $packages
     * @return list<Package>
     * @throws RuntimeException on dependency cycles
     */
    public function order(array $packages): array
    {
        ksort($packages);
        $ordered = [];
        $state = []; // slug => 1 visiting, 2 done

        $visit = function (string $slug, array $path) use (&$visit, &$ordered, &$state, $packages): void {
            if (($state[$slug] ?? 0) === 2) {
                return;
            }
            if (($state[$slug] ?? 0) === 1) {
                throw new RuntimeException('Dependency cycle: ' . implode(' → ', [...$path, $slug]));
            }

            $state[$slug] = 1;

            $deps = array_keys($packages[$slug]->manifest->dependencies);
            sort($deps);
            foreach ($deps as $dep) {
                if (isset($packages[$dep])) {
                    $visit($dep, [...$path, $slug]);
                }
            }

            $state[$slug] = 2;
            $ordered[] = $packages[$slug];
        };

        foreach (array_keys($packages) as $slug) {
            $visit($slug, []);
        }

        return $ordered;
    }

    private function satisfies(string $version, string $constraint): bool
    {
        // Dev builds (core VERSION "dev") satisfy everything; a pre-release counts as its release
        return !Semver::isValid($version) || Semver::satisfiesRelease($version, $constraint);
    }
}
