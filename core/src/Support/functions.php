<?php

declare(strict_types=1);

/*
 * Namespaced helper functions (no globals): import what you use.
 *
 *   use function Kestello\{str, collect, data_get, __, trans_choice};
 */

namespace Kestello;

use Closure;
use Kestello\Support\Arr;
use Kestello\Support\Collection;
use Kestello\Support\Stringable;

if (!function_exists('Kestello\str')) {
    /** @api */
    function str(string $value = ''): Stringable
    {
        return new Stringable($value);
    }

    /**
     * @template TKey of array-key
     * @template TValue
     * @param iterable<TKey, TValue>|mixed $items
     * @return Collection<TKey, TValue>
     * @api
     */
    function collect(mixed $items = []): Collection
    {
        return Collection::make($items);
    }

    /** Dot-path read from arrays and objects ("*" maps over lists). @api */
    function data_get(mixed $target, string|int|null $key, mixed $default = null): mixed
    {
        return Arr::get($target, $key, $default);
    }

    /** Dot-path write into an array (returns the new array). @api */
    function data_set(array $target, string|int $key, mixed $value): array
    {
        return Arr::set($target, $key, $value);
    }

    /** Call closures, return anything else as is. @api */
    function value(mixed $value, mixed ...$args): mixed
    {
        return $value instanceof Closure ? $value(...$args) : $value;
    }

    /**
     * Call $callback with $value, then return $value.
     *
     * @template T
     * @param T $value
     * @return T
     * @api
     */
    function tap(mixed $value, ?callable $callback = null): mixed
    {
        if ($callback !== null) {
            $callback($value);
        }

        return $value;
    }

    /** Run $callback; on any exception, return $default (and report it via $report). @api */
    function rescue(callable $callback, mixed $default = null, ?callable $report = null): mixed
    {
        try {
            return $callback();
        } catch (\Throwable $e) {
            if ($report !== null) {
                $report($e);
            }

            return value($default, $e);
        }
    }

    /**
     * Retry $callback up to $times attempts, sleeping $sleepMs (or
     * $sleepMs(attempt)) between them. $when(Throwable) decides retryable errors.
     *
     * @api
     */
    function retry(int $times, callable $callback, int|Closure $sleepMs = 0, ?callable $when = null): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $callback($attempt);
            } catch (\Throwable $e) {
                if ($attempt >= $times || ($when !== null && !$when($e))) {
                    throw $e;
                }
                $ms = $sleepMs instanceof Closure ? (int) $sleepMs($attempt) : $sleepMs;
                if ($ms > 0) {
                    usleep($ms * 1000);
                }
            }
        }
    }

    /** True for null, "", whitespace-only strings, empty arrays and empty Countables. @api */
    function blank(mixed $value): bool
    {
        return match (true) {
            $value === null => true,
            is_string($value) => trim($value) === '',
            is_array($value) => $value === [],
            $value instanceof \Countable => count($value) === 0,
            default => false,
        };
    }

    /** @api */
    function filled(mixed $value): bool
    {
        return !blank($value);
    }
}

if (!function_exists('Kestello\__')) {
    /**
     * Translate (see Support\Translator).
     *
     * @api
     * @param array<string, mixed> $params
     */
    function __(string $key, array $params = [], string $domain = 'core', ?string $locale = null): string
    {
        return \Kestello\Support\Translator::get($key, $params, $domain, $locale);
    }
}

if (!function_exists('Kestello\trans_choice')) {
    /**
     * @api
     * @param array<string, mixed> $params
     */
    function trans_choice(string $key, int|float $count, array $params = [], string $domain = 'core', ?string $locale = null): string
    {
        return \Kestello\Support\Translator::choice($key, $count, $params, $domain, $locale);
    }
}
