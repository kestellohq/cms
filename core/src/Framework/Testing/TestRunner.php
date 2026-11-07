<?php

declare(strict_types=1);

namespace Kestello\Framework\Testing;

use ReflectionClass;
use ReflectionMethod;
use Throwable;

/**
 * Discovers *Test.php files, runs their test methods and reports results.
 * Zero dependencies by design; deliberately small.
 */
final class TestRunner
{
    /** @var list<array{test: string, kind: string, message: string, where: string}> */
    private array $problems = [];

    private int $passed = 0;
    private int $assertions = 0;

    /** @var \Closure(string): void */
    private readonly \Closure $write;

    /**
     * @param \Closure(string): void|null $write Output sink (defaults to STDOUT).
     */
    public function __construct(?\Closure $write = null)
    {
        $this->write = $write ?? static function (string $text): void {
            fwrite(STDOUT, $text);
        };
    }

    /**
     * @param string      $directory Root directory to scan for *Test.php.
     * @param string|null $filter    Only run tests whose "Class::method" contains this string.
     *
     * @return bool True when every test passed.
     */
    public function run(string $directory, ?string $filter = null): bool
    {
        $started = microtime(true);
        $classes = $this->discover($directory);
        $count = 0;

        foreach ($classes as $class) {
            foreach ($this->testMethods($class) as $method) {
                $name = $class . '::' . $method;

                if ($filter !== null && !str_contains($name, $filter)) {
                    continue;
                }

                $count++;
                $this->runOne($class, $method, $name);

                if ($count % 60 === 0) {
                    ($this->write)("\n");
                }
            }
        }

        $this->report($count, microtime(true) - $started);

        return $this->problems === [];
    }

    private function runOne(string $class, string $method, string $name): void
    {
        try {
            /** @var TestCase $test */
            $test = new $class();
            $this->assertions += $test->runTest($method);
            $this->passed++;
            ($this->write)('.');
        } catch (AssertionFailed $e) {
            $this->problems[] = ['test' => $name, 'kind' => 'FAIL', 'message' => $e->getMessage(), 'where' => self::where($e)];
            ($this->write)("\033[31mF\033[0m");
        } catch (Throwable $e) {
            $this->problems[] = [
                'test' => $name,
                'kind' => 'ERROR',
                'message' => $e::class . ': ' . $e->getMessage(),
                'where' => $e->getFile() . ':' . $e->getLine(),
            ];
            ($this->write)("\033[31mE\033[0m");
        }
    }

    /**
     * @return list<class-string<TestCase>>
     */
    private function discover(string $directory): array
    {
        $files = [];
        if (is_file($directory) && str_ends_with($directory, 'Test.php')) {
            $files[] = $directory;      // one test file
        } elseif (!is_dir($directory)) {
            fwrite(STDERR, "No tests at {$directory}\n");
            return [];
        } else {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), 'Test.php')) {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        $before = get_declared_classes();
        foreach ($files as $file) {
            require_once $file;
        }

        $classes = array_filter(
            array_diff(get_declared_classes(), $before),
            static fn(string $c): bool => is_subclass_of($c, TestCase::class) && !(new ReflectionClass($c))->isAbstract(),
        );

        sort($classes);

        return array_values($classes);
    }

    /**
     * @return list<string>
     */
    private function testMethods(string $class): array
    {
        $methods = [];

        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (str_starts_with($method->getName(), 'test') && $method->getDeclaringClass()->getName() === $class) {
                $methods[] = $method->getName();
            }
        }

        return $methods;
    }

    private function report(int $count, float $seconds): void
    {
        $write = $this->write;
        $write("\n\n");

        foreach ($this->problems as $i => $p) {
            $write(sprintf("\033[31m%d) %s %s\033[0m\n   %s\n   at %s\n\n", $i + 1, $p['kind'], $p['test'], $p['message'], $p['where']));
        }

        $summary = sprintf(
            '%d tests, %d assertions, %d passed, %d failed (%.2fs)',
            $count,
            $this->assertions,
            $this->passed,
            count($this->problems),
            $seconds,
        );

        $write(($this->problems === [] ? "\033[32mOK\033[0m  " : "\033[31mFAILED\033[0m  ") . $summary . "\n");
    }

    /**
     * File:line of the assertion call inside the test (first frame outside TestCase).
     */
    private static function where(Throwable $e): string
    {
        foreach ($e->getTrace() as $frame) {
            if (isset($frame['file']) && !str_ends_with($frame['file'], '/Testing/TestCase.php')) {
                return $frame['file'] . ':' . ($frame['line'] ?? '?');
            }
        }

        return $e->getFile() . ':' . $e->getLine();
    }
}
