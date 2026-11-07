<?php

declare(strict_types=1);

namespace Kestello\Framework\Testing;

use Throwable;

/**
 * Base class for tests. Every public method whose name starts with "test"
 * runs on a fresh instance, between setUp() and tearDown().
 */
abstract class TestCase
{
    private int $assertions = 0;

    /** @var list<string> Temp directories removed after each test. */
    private array $tempDirs = [];

    protected function setUp(): void {}

    protected function tearDown(): void {}

    /** @internal Called by the runner. */
    final public function runTest(string $method): int
    {
        $this->setUp();

        try {
            $this->{$method}();
        } finally {
            try {
                $this->tearDown();
            } finally {
                foreach ($this->tempDirs as $dir) {
                    self::removeDirectory($dir);
                }
            }
        }

        return $this->assertions;
    }

    // ── Helpers ─────────────────────────────────────────────────────

    /**
     * Create an empty temporary directory, removed automatically after the test.
     */
    protected function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/kestello-test-' . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    /**
     * A path inside Kestello's core folder, for tests that live outside this repository
     * (a plugin's own tests): corePath('database/migrations').
     */
    protected static function corePath(string $path = ''): string
    {
        $core = dirname(__DIR__, 3);

        return $path === '' ? $core : $core . '/' . ltrim($path, '/');
    }

    // ── Assertions ──────────────────────────────────────────────────

    protected function assertTrue(mixed $value, string $message = ''): void
    {
        $this->check($value === true, $message ?: 'Expected true, got ' . self::export($value));
    }

    protected function assertFalse(mixed $value, string $message = ''): void
    {
        $this->check($value === false, $message ?: 'Expected false, got ' . self::export($value));
    }

    /** @param array<array-key, mixed> $array */
    protected function assertArrayHasKey(int|string $key, array $array, string $message = ''): void
    {
        $this->check(array_key_exists($key, $array), $message ?: 'Expected key ' . self::export($key) . ' in ' . self::export(array_keys($array)));
    }

    /** @param array<array-key, mixed> $array */
    protected function assertArrayNotHasKey(int|string $key, array $array, string $message = ''): void
    {
        $this->check(!array_key_exists($key, $array), $message ?: 'Did not expect key ' . self::export($key));
    }

    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->check(
            $expected === $actual,
            $message ?: sprintf("Expected %s\n       got %s", self::export($expected), self::export($actual)),
        );
    }

    protected function assertEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->check(
            $expected == $actual,
            $message ?: sprintf("Expected %s\n       got %s", self::export($expected), self::export($actual)),
        );
    }

    protected function assertNull(mixed $value, string $message = ''): void
    {
        $this->check($value === null, $message ?: 'Expected null, got ' . self::export($value));
    }

    protected function assertNotNull(mixed $value, string $message = ''): void
    {
        $this->check($value !== null, $message ?: 'Expected a non-null value');
    }

    protected function assertCount(int $expected, countable|array $value, string $message = ''): void
    {
        $count = count($value);
        $this->check($count === $expected, $message ?: "Expected count {$expected}, got {$count}");
    }

    protected function assertGreaterThanOrEqual(int|float $minimum, int|float $actual, string $message = ''): void
    {
        $this->check($actual >= $minimum, $message ?: "Expected at least {$minimum}, got {$actual}");
    }

    /**
     * Substring check for strings, membership (strict) for arrays.
     */
    protected function assertContains(mixed $needle, string|array $haystack, string $message = ''): void
    {
        $found = is_string($haystack)
            ? str_contains($haystack, (string) $needle)
            : in_array($needle, $haystack, true);

        $this->check($found, $message ?: sprintf('Expected %s to contain %s', self::export($haystack), self::export($needle)));
    }

    protected function assertNotContains(mixed $needle, string|array $haystack, string $message = ''): void
    {
        $found = is_string($haystack)
            ? str_contains($haystack, (string) $needle)
            : in_array($needle, $haystack, true);

        $this->check(!$found, $message ?: sprintf('Expected %s not to contain %s', self::export($haystack), self::export($needle)));
    }

    protected function assertStringContainsString(string $needle, string $haystack, string $message = ''): void
    {
        $this->check(str_contains($haystack, $needle), $message ?: sprintf('Expected %s to contain %s', self::export($haystack), self::export($needle)));
    }

    protected function assertStringNotContainsString(string $needle, string $haystack, string $message = ''): void
    {
        $this->check(!str_contains($haystack, $needle), $message ?: sprintf('Expected %s not to contain %s', self::export($haystack), self::export($needle)));
    }

    protected function assertStringStartsWith(string $prefix, string $value, string $message = ''): void
    {
        $this->check(str_starts_with($value, $prefix), $message ?: sprintf('Expected %s to start with %s', self::export($value), self::export($prefix)));
    }

    protected function assertStringEndsWith(string $suffix, string $value, string $message = ''): void
    {
        $this->check(str_ends_with($value, $suffix), $message ?: sprintf('Expected %s to end with %s', self::export($value), self::export($suffix)));
    }

    protected function assertNotSame(mixed $unexpected, mixed $actual, string $message = ''): void
    {
        $this->check($unexpected !== $actual, $message ?: sprintf('Expected a value other than %s', self::export($actual)));
    }

    protected function assertMatchesRegularExpression(string $pattern, string $value, string $message = ''): void
    {
        $this->check(preg_match($pattern, $value) === 1, $message ?: sprintf('Expected %s to match %s', self::export($value), $pattern));
    }

    protected function assertFileExists(string $path, string $message = ''): void
    {
        clearstatcache(true, $path);
        $this->check(is_file($path), $message ?: "Expected file {$path} to exist");
    }

    protected function assertFileDoesNotExist(string $path, string $message = ''): void
    {
        clearstatcache(true, $path);
        $this->check(!file_exists($path), $message ?: "Expected file {$path} not to exist");
    }

    protected function assertInstanceOf(string $class, mixed $value, string $message = ''): void
    {
        $this->check(
            $value instanceof $class,
            $message ?: sprintf('Expected instance of %s, got %s', $class, get_debug_type($value)),
        );
    }

    /**
     * Assert that $callback throws $class. Returns the exception for further checks.
     *
     * @template T of Throwable
     * @param class-string<T> $class
     * @return T
     */
    protected function assertThrows(string $class, callable $callback, string $message = ''): Throwable
    {
        try {
            $callback();
        } catch (AssertionFailed $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->check(
                $e instanceof $class,
                $message ?: sprintf('Expected %s, got %s: %s', $class, $e::class, $e->getMessage()),
            );
            return $e;
        }

        $this->fail($message ?: "Expected {$class} to be thrown, nothing was thrown");
    }

    protected function fail(string $message): never
    {
        throw new AssertionFailed($message);
    }

    private function check(bool $condition, string $message): void
    {
        $this->assertions++;

        if (!$condition) {
            throw new AssertionFailed($message);
        }
    }

    private static function export(mixed $value): string
    {
        return match (true) {
            is_string($value) => '"' . (strlen($value) > 200 ? substr($value, 0, 200) . '…' : $value) . '"',
            is_object($value) => get_debug_type($value),
            default => str_replace("\n", ' ', var_export($value, true)),
        };
    }

    private static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}
