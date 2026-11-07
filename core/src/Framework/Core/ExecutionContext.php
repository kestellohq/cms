<?php

declare(strict_types=1);

namespace Kestello\Framework\Core;

use Throwable;
use WeakMap;

/**
 * Tracks which extension (plugin, theme, module) is currently executing.
 *
 * Owners are strings like "plugin:acme-seo", "theme:aurora", "core".
 * Anything that runs extension code (provider boot, event listeners,
 * filters, route handlers, template functions) runs it inside
 * ExecutionContext::run(), so failures can be attributed to the right
 * package — the foundation of safe mode.
 */
final class ExecutionContext
{
    /** @var list<string> */
    private static array $stack = [];

    /** @var WeakMap<Throwable, string>|null */
    private static ?WeakMap $blame = null;

    /**
     * Run $callback as $owner. Exceptions are tagged with the innermost owner
     * that saw them first, then rethrown unchanged.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function run(?string $owner, callable $callback): mixed
    {
        if ($owner === null) {
            return $callback();
        }
        if ($owner === 'core') {
            // Core work called from plugin code (storage, HTTP for a core service)
            // runs with core's rights. Errors still reach the plugin's frame, so
            // blame and safe mode work as before.
            self::$stack[] = 'core';
            try {
                return $callback();
            } finally {
                array_pop(self::$stack);
            }
        }

        self::$stack[] = $owner;

        try {
            return $callback();
        } catch (Throwable $e) {
            self::blame($e, $owner);
            throw $e;
        } finally {
            array_pop(self::$stack);
        }
    }

    /**
     * The owner currently executing, or "core".
     */
    public static function current(): string
    {
        return self::$stack === [] ? 'core' : self::$stack[array_key_last(self::$stack)];
    }

    /**
     * The full stack (outermost first), for fatal-error attribution: after a
     * fatal error, finally blocks never run, so the stack is still intact.
     *
     * @return list<string>
     */
    public static function stack(): array
    {
        return self::$stack;
    }

    public static function blame(Throwable $e, string $owner): void
    {
        self::$blame ??= new WeakMap();

        // Keep the first (innermost) attribution.
        if (!isset(self::$blame[$e])) {
            self::$blame[$e] = $owner;
        }
    }

    public static function blamed(Throwable $e): ?string
    {
        return self::$blame[$e] ?? null;
    }

    /** @internal for tests */
    public static function reset(): void
    {
        self::$stack = [];
        self::$blame = null;
    }
}
