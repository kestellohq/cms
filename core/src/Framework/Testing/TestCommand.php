<?php

declare(strict_types=1);

namespace Kestello\Framework\Testing;

use Kestello\Framework\Console\Command;
use Kestello\Framework\Core\Autoloader;

/**
 * php bin/cms test [path ...] [--filter=Name]
 *
 * Runs every *Test.php under the paths given (tests/ by default). Besides the site's own
 * Kestello\Tests\ (tests/), core's test helpers are autoloaded when core comes with its tests,
 * as in a development checkout: a site's plugin can extend AuthTestCase or use core's fixtures.
 * Every plugin and theme in content/ is autoloaded by the namespace its manifest names, so its
 * tests can use its classes without installing it, and <namespace>\Tests\ from its tests/, so
 * its tests can share a base class or fixtures.
 */
final class TestCommand extends Command
{
    protected string $name = 'test';
    protected string $description = 'Run the tests: test [path ...] [--filter=Name] (default tests/)';

    /** @param \Closure(string): void|null $write where the results go (tests pass one) */
    public function __construct(
        private readonly string $basePath,
        private readonly ?\Closure $write = null,
    ) {}

    public function handle(array $arguments, array $options): int
    {
        $paths = array_map(
            fn(string $p): string => str_starts_with($p, '/') ? $p : $this->basePath . '/' . $p,
            $arguments === [] ? ['tests'] : array_values(array_map('strval', $arguments)),
        );

        $autoloader = new Autoloader();
        foreach (array_unique([$this->basePath . '/tests', dirname(__DIR__, 4) . '/tests']) as $tests) {
            if (is_dir($tests)) {
                $autoloader->addNamespace('Kestello\\Tests\\', $tests);
            }
        }
        foreach (self::packages($this->basePath . '/content') as $namespace => $dir) {
            $autoloader->addNamespace($namespace, $dir);
        }
        $autoloader->register();

        $runner = new TestRunner($this->write);

        return $runner->run($paths, $this->option($options, 'filter')) ? 0 : 1;
    }

    /**
     * The plugins and themes in content/, by the namespace their manifest names.
     *
     * @return array<string, string> namespace => its src/ folder, and namespace\Tests\ => its tests/ folder
     */
    public static function packages(string $content): array
    {
        $out = [];
        foreach ([...(glob($content . '/plugins/*/plugin.json') ?: []), ...(glob($content . '/themes/*/theme.json') ?: [])] as $manifest) {
            $namespace = json_decode((string) file_get_contents($manifest), true)['namespace'] ?? null;
            if (!is_string($namespace) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*\\\\?$/', $namespace) !== 1) {
                continue;
            }
            $namespace = rtrim($namespace, '\\') . '\\';
            $dir = dirname($manifest);
            if (is_dir($dir . '/src')) {
                $out[$namespace] = $dir . '/src';
            }
            if (is_dir($dir . '/tests')) {
                $out[$namespace . 'Tests\\'] = $dir . '/tests';
            }
        }

        return $out;
    }
}
