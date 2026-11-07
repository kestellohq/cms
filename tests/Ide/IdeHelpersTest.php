<?php

declare(strict_types=1);

namespace Kestello\Tests\Ide;

use Kestello\Cms\Admin\AdminRegistry;
use Kestello\Cms\Auth\Roles;
use Kestello\Cms\I18n\SiteLanguage;
use Kestello\Cms\Ide\Meta;
use Kestello\Cms\Ide\Stubs;
use Kestello\Framework\Testing\TestCase;

/** bin/cms ide:helpers: the PhpStorm meta file and the API stubs for plugin repositories. */
final class IdeHelpersTest extends TestCase
{
    public function testDeclaredClassesAreReadFromTokens(): void
    {
        $code = "<?php\nnamespace Acme\\Shop;\n/** a class */\nfinal class Cart {}\ninterface Priced {}\nenum Size: string { case S = 's'; }\n\$x = Cart::class;\n\$y = new class {};\n";
        $this->assertSame(['Acme\\Shop\\Cart', 'Acme\\Shop\\Priced', 'Acme\\Shop\\Size'], Stubs::declared($code));
    }

    public function testStubsAreValidPhpWithSignaturesAndNoBodies(): void
    {
        $stub = Stubs::render([SiteLanguage::class, \Kestello\Cms\Extension\PackageType::class], ['Kestello\\__'], '1.2.3');

        $this->assertContains('// Kestello 1.2.3: stubs of the public API', $stub);
        $this->assertContains('namespace Kestello\\Cms\\I18n {', $stub);
        $this->assertContains("final class SiteLanguage\n{", $stub);
        $this->assertContains("    public const SETTING = 'language';", $stub);
        $this->assertContains('    public static function resolve(?string $setting, ?string $config): string {}', $stub);
        $this->assertContains('    public static function choices(?array $installed = null): array {}', $stub);
        $this->assertContains('    /** The locale to use: a well-formed setting wins over config. */', $stub, 'doc comments are kept');
        $this->assertContains("enum PackageType: string\n{", $stub);
        $this->assertContains("function __(string \$key, array \$params = [], string \$domain = 'core', ?string \$locale = null): string {}", $stub);
        $this->assertNotContains('Translator::isLocale', $stub, 'no method bodies');

        $file = $this->tempDir() . '/stub.php';
        file_put_contents($file, $stub);
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $output, $code);
        $this->assertSame(0, $code, implode("\n", $output));
    }

    public function testInternalClassesAreLeftOut(): void
    {
        $classes = Stubs::publicClasses(dirname(__DIR__, 2) . '/core/src/Cms/I18n');
        $this->assertTrue(in_array(SiteLanguage::class, $classes, true));
        $this->assertFalse(in_array(\Kestello\Cms\I18n\Extractor::class, $classes, true), '@internal');
    }

    public function testMetaNamesTheRealCapabilitiesAndPoints(): void
    {
        $meta = Meta::render(array_keys(Roles::CORE), AdminRegistry::POINTS, AdminRegistry::REPLACEABLE);
        $this->assertContains("override(\\Kestello\\Framework\\Core\\Container::make(0), map(['' => '@']));", $meta);
        $this->assertContains("'admin.access'", $meta);
        $this->assertContains("registerArgumentsSet('kestello_slots', 'users.edit.panels', 'profile.tabs');", $meta);
        $this->assertContains("expectedArguments(\\Kestello\\Cms\\Admin\\AdminRegistry::replace(), 0, argumentsSet('kestello_replaceable'));", $meta);
    }

    public function testTheCommittedMetaFileIsCurrent(): void
    {
        $expected = Meta::render(array_keys(Roles::CORE), AdminRegistry::POINTS, AdminRegistry::REPLACEABLE);
        $this->assertSame($expected, file_get_contents(dirname(__DIR__, 2) . '/.phpstorm.meta.php'), 'run: bin/cms ide:helpers');
    }
}
