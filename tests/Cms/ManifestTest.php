<?php

declare(strict_types=1);

namespace Kestello\Tests\Cms;

use Kestello\Cms\Extension\Manifest;
use Kestello\Cms\Extension\ManifestException;
use Kestello\Cms\Extension\PackageType;
use Kestello\Framework\Testing\TestCase;

final class ManifestTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function valid(array $overrides = []): array
    {
        return array_replace([
            'slug' => 'acme-seo',
            'name' => 'Acme SEO',
            'version' => '2.1.0',
            'requires' => ['core' => '^0.1', 'php' => '>=8.3'],
            'namespace' => 'Acme\\Seo',
            'provider' => 'Acme\\Seo\\SeoProvider',
            'dependencies' => ['forms' => '^1.0'],
        ], $overrides);
    }

    public function testValidManifestIsNormalised(): void
    {
        $m = Manifest::fromArray(self::valid(), PackageType::Plugin);

        $this->assertSame('acme-seo', $m->slug);
        $this->assertSame('Acme\\Seo\\', $m->namespace);
        $this->assertSame(['forms' => '^1.0'], $m->dependencies);
    }

    /** @return array<string, array<string, mixed>> */
    private static function invalidCases(): array
    {
        return [
            'uppercase slug' => ['slug' => 'Acme'],
            'bad version' => ['version' => '1.0'],
            'missing core constraint' => ['requires' => ['php' => '>=8.3']],
            'bad core constraint' => ['requires' => ['core' => 'banana']],
            'reserved namespace' => ['namespace' => 'Kestello\\Evil', 'provider' => 'Kestello\\Evil\\P'],
            'provider outside namespace' => ['provider' => 'Other\\Provider'],
            'self dependency' => ['dependencies' => ['acme-seo' => '^1.0']],
            'bad dependency constraint' => ['dependencies' => ['forms' => 'latest']],
        ];
    }

    public function testInvalidManifestsAreRejected(): void
    {
        foreach (self::invalidCases() as $case => $overrides) {
            $this->assertThrows(ManifestException::class, fn() => Manifest::fromArray(self::valid($overrides), PackageType::Plugin), $case);
        }
    }

    public function testDirectoryMustMatchSlug(): void
    {
        $dir = $this->tempDir() . '/wrong-name';
        mkdir($dir);
        file_put_contents("$dir/plugin.json", json_encode(self::valid()));

        $e = $this->assertThrows(ManifestException::class, fn() => Manifest::load($dir, PackageType::Plugin));
        $this->assertContains('must match slug', $e->getMessage());
    }

    public function testParentOnlyAppliesToThemes(): void
    {
        $theme = Manifest::fromArray(['slug' => 'child', 'name' => 'C', 'version' => '1.0.0', 'requires' => ['core' => '*'], 'parent' => 'base'], PackageType::Theme);
        $this->assertSame('base', $theme->parent);

        $plugin = Manifest::fromArray(['slug' => 'p', 'name' => 'P', 'version' => '1.0.0', 'requires' => ['core' => '*'], 'parent' => 'base'], PackageType::Plugin);
        $this->assertNull($plugin->parent);
    }
}
