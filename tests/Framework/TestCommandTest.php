<?php

declare(strict_types=1);

namespace Kestello\Tests\Framework;

use Kestello\Framework\Testing\TestCase;
use Kestello\Framework\Testing\TestCommand;

/**
 * bin/cms test on a site: several paths at once, and a plugin's or theme's tests using its own
 * classes and its own test helpers without it being installed.
 */
final class TestCommandTest extends TestCase
{
    public function testASiteTestsItsPluginsAndThemesTogether(): void
    {
        $site = $this->tempDir();
        $id = 'Run' . bin2hex(random_bytes(4));   // classes stay declared for the rest of the suite
        $this->package($site . '/content/plugins/greeter', 'plugin.json', "Plugins\\Greeter{$id}\\", 'Greeter', "return 'hello';", 'hello');
        $this->package($site . '/content/themes/plain', 'theme.json', "Themes\\Plain{$id}\\", 'Colours', "return 'navy';", 'navy');
        mkdir($site . '/tests/broken', 0o777, true);
        file_put_contents($site . '/tests/broken/BrokenTest.php', "<?php\nnamespace Kestello\\Tests\\Broken{$id};\nfinal class BrokenTest extends \\Kestello\\Framework\\Testing\\TestCase { public function testFails(): void { \$this->assertSame(1, 2); } }\n");

        $this->assertSame(
            [
                "Plugins\\Greeter{$id}\\" => $site . '/content/plugins/greeter/src',
                "Plugins\\Greeter{$id}\\Tests\\" => $site . '/content/plugins/greeter/tests',
                "Themes\\Plain{$id}\\" => $site . '/content/themes/plain/src',
                "Themes\\Plain{$id}\\Tests\\" => $site . '/content/themes/plain/tests',
            ],
            TestCommand::packages($site . '/content'),
        );

        [$code, $out] = $this->run($site, ['content/plugins', 'content/themes']);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('2 tests', $out, 'both paths, and each package\'s classes and test helpers autoloaded');

        [$code, $out] = $this->run($site, ['content/plugins', 'tests']);
        $this->assertSame(1, $code, 'a failing test anywhere fails the run');
        $this->assertStringContainsString('1 failed', $out);
    }

    /** A package with one class, and one test of it built on a helper of the package's own. */
    private function package(string $dir, string $manifest, string $namespace, string $class, string $body, string $expected): void
    {
        mkdir($dir . '/src', 0o777, true);
        mkdir($dir . '/tests', 0o777, true);
        file_put_contents($dir . '/' . $manifest, (string) json_encode(['slug' => basename($dir), 'name' => basename($dir), 'version' => '1.0.0', 'namespace' => $namespace]));
        $ns = rtrim($namespace, '\\');
        file_put_contents("{$dir}/src/{$class}.php", "<?php\nnamespace {$ns};\nfinal class {$class} { public function value(): string { {$body} } }\n");
        file_put_contents("{$dir}/tests/{$class}Case.php", "<?php\nnamespace {$ns}\\Tests;\nabstract class {$class}Case extends \\Kestello\\Framework\\Testing\\TestCase { protected function expected(): string { return '{$expected}'; } }\n");
        file_put_contents("{$dir}/tests/{$class}Test.php", "<?php\nnamespace {$ns}\\Tests;\nfinal class {$class}Test extends {$class}Case { public function testValue(): void { \$this->assertSame(\$this->expected(), (new \\{$ns}\\{$class}())->value()); } }\n");
    }

    /**
     * @param list<string> $paths
     * @return array{int, string}
     */
    private function run(string $site, array $paths): array
    {
        $out = '';
        $command = new TestCommand($site, static function (string $text) use (&$out): void {
            $out .= $text;
        });
        $code = $command->handle($paths, []);

        return [$code, $out];
    }
}
