<?php

declare(strict_types=1);

namespace Kestello\Tests\Cms;

use Kestello\Cms\Extension\DependencyResolver;
use Kestello\Cms\Extension\Manifest;
use Kestello\Cms\Extension\Package;
use Kestello\Cms\Extension\PackageType;
use Kestello\Framework\Testing\TestCase;
use RuntimeException;

final class DependencyResolverTest extends TestCase
{
    /** @param array<string, mixed> $extra */
    public static function plugin(string $slug, string $version = '1.0.0', array $extra = []): Package
    {
        return new Package(Manifest::fromArray(array_replace([
            'slug' => $slug,
            'name' => $slug,
            'version' => $version,
            'requires' => ['core' => '^0.1'],
        ], $extra), PackageType::Plugin), '/tmp/' . $slug);
    }

    private function resolver(): DependencyResolver
    {
        return new DependencyResolver('0.1.0', '8.3.10', ['core', 'json']);
    }

    public function testReportsCoreAndPhpAndExtensionRequirements(): void
    {
        $p = self::plugin('x', extra: ['requires' => ['core' => '^2.0', 'php' => '>=9.0', 'extensions' => ['imagick']]]);
        $problems = $this->resolver()->problems($p, ['x' => $p]);

        $this->assertCount(3, $problems);
        $this->assertContains('requires Kestello ^2.0', $problems[0]);
    }

    public function testAReleaseCandidateRunsThePackagesMadeForItsRelease(): void
    {
        $p = self::plugin('x');
        $this->assertSame([], (new DependencyResolver('0.1.0-rc.1', '8.4.0', ['core']))->problems($p, ['x' => $p]), 'a plugin for ^0.1 runs on 0.1.0-rc.1');
        $next = self::plugin('y', extra: ['requires' => ['core' => '^0.2']]);
        $this->assertNotSame([], (new DependencyResolver('0.2.0-rc.1', '8.4.0', ['core']))->problems($p, ['x' => $p]), 'but not on the next minor\'s candidate');
        $this->assertSame([], (new DependencyResolver('0.2.0-rc.1', '8.4.0', ['core']))->problems($next, ['y' => $next]));

        $dep = self::plugin('forms', '2.0.0-beta.1');
        $seo = self::plugin('seo', extra: ['dependencies' => ['forms' => '^2.0']]);
        $this->assertNotSame([], $this->resolver()->problems($seo, ['forms' => $dep, 'seo' => $seo]), 'plugin pre-releases stay strict');
    }

    public function testDependenciesAndConflicts(): void
    {
        $forms = self::plugin('forms', '1.4.0');
        $seo = self::plugin('seo', extra: ['dependencies' => ['forms' => '^2.0']]);
        $rival = self::plugin('rival', extra: ['conflicts' => ['seo']]);

        $this->assertContains('requires plugin "forms" ^2.0 (found 1.4.0)', $this->resolver()->problems($seo, ['forms' => $forms, 'seo' => $seo]));
        $this->assertContains('conflicts with plugin "rival"', $this->resolver()->problems($seo, ['seo' => $seo, 'rival' => $rival]));
    }

    public function testRunnableDropsBrokenPackagesTransitively(): void
    {
        $base = self::plugin('base-lib', extra: ['requires' => ['core' => '^9.0']]);
        $mid = self::plugin('mid', extra: ['dependencies' => ['base-lib' => '*']]);
        $top = self::plugin('top', extra: ['dependencies' => ['mid' => '*']]);
        $solo = self::plugin('solo');

        [$runnable, $rejected] = $this->resolver()->runnable(['base-lib' => $base, 'mid' => $mid, 'top' => $top, 'solo' => $solo]);

        $this->assertSame(['solo'], array_keys($runnable));
        $this->assertSame(['base-lib', 'mid', 'top'], array_keys($rejected));
    }

    public function testOrderPutsDependenciesFirst(): void
    {
        $a = self::plugin('a', extra: ['dependencies' => ['c' => '*']]);
        $b = self::plugin('b');
        $c = self::plugin('c', extra: ['dependencies' => ['b' => '*']]);

        $order = array_map(fn(Package $p) => $p->slug(), $this->resolver()->order(['a' => $a, 'b' => $b, 'c' => $c]));

        $this->assertSame(['b', 'c', 'a'], $order);
    }

    public function testCyclesAreDetected(): void
    {
        $a = self::plugin('a', extra: ['dependencies' => ['b' => '*']]);
        $b = self::plugin('b', extra: ['dependencies' => ['a' => '*']]);

        $this->assertThrows(RuntimeException::class, fn() => $this->resolver()->order(['a' => $a, 'b' => $b]));
    }
}
