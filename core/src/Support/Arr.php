<?php

declare(strict_types=1);

namespace Kestello\Support;

use ArrayAccess;
use Closure;

/**
 * Array helpers with dot notation ("user.address.city").
 *
 * @api
 */
final class Arr
{
    /** Closures as defaults are called lazily. */
    private static function resolve(mixed $value): mixed
    {
        return $value instanceof Closure ? $value() : $value;
    }

    public static function accessible(mixed $value): bool
    {
        return is_array($value) || $value instanceof ArrayAccess;
    }

    public static function exists(array|ArrayAccess $array, string|int $key): bool
    {
        return $array instanceof ArrayAccess ? $array->offsetExists($key) : array_key_exists($key, $array);
    }

    /** Arr::get($config, 'mail.from.address', 'x'); a "*" segment maps over every item. */
    public static function get(mixed $target, string|int|null $key, mixed $default = null): mixed
    {
        if ($key === null) {
            return $target;
        }
        if (is_array($target) && array_key_exists($key, $target)) {
            return $target[$key];
        }

        $segments = explode('.', (string) $key);
        foreach ($segments as $i => $segment) {
            if ($segment === '*') {
                if (!is_iterable($target)) {
                    return self::resolve($default);
                }
                $rest = implode('.', array_slice($segments, $i + 1));
                $out = [];
                foreach ($target as $item) {
                    $out[] = $rest === '' ? $item : self::get($item, $rest);
                }
                return $out;
            }

            if (self::accessible($target) && self::exists($target, $segment)) {
                $target = $target[$segment];
            } elseif (is_object($target) && isset($target->{$segment})) {
                $target = $target->{$segment};
            } else {
                return self::resolve($default);
            }
        }

        return $target;
    }

    public static function has(array $array, string|int ...$keys): bool
    {
        if ($keys === []) {
            return false;
        }

        foreach ($keys as $key) {
            if (array_key_exists($key, $array)) {
                continue;
            }
            $target = $array;
            foreach (explode('.', (string) $key) as $segment) {
                if (!is_array($target) || !array_key_exists($segment, $target)) {
                    return false;
                }
                $target = $target[$segment];
            }
        }

        return true;
    }

    /** Set a value by dot path, creating nested arrays. Returns the new array. */
    public static function set(array $array, string|int $key, mixed $value): array
    {
        $target = &$array;
        foreach (explode('.', (string) $key) as $segment) {
            if (!isset($target[$segment]) || !is_array($target[$segment])) {
                $target[$segment] = [];
            }
            $target = &$target[$segment];
        }
        $target = $value;
        unset($target);

        return $array;
    }

    /** Remove keys by dot path. Returns the new array. */
    public static function forget(array $array, string|int ...$keys): array
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $array)) {
                unset($array[$key]);
                continue;
            }
            $segments = explode('.', (string) $key);
            $last = array_pop($segments);
            $target = &$array;
            foreach ($segments as $segment) {
                if (!isset($target[$segment]) || !is_array($target[$segment])) {
                    unset($target);
                    continue 2;
                }
                $target = &$target[$segment];
            }
            unset($target[$last], $target);
        }

        return $array;
    }

    /** ['a' => ['b' => 1]] → ['a.b' => 1] */
    public static function dot(array $array, string $prefix = ''): array
    {
        $out = [];
        foreach ($array as $key => $value) {
            if (is_array($value) && $value !== []) {
                $out += self::dot($value, $prefix . $key . '.');
            } else {
                $out[$prefix . $key] = $value;
            }
        }

        return $out;
    }

    /** ['a.b' => 1] → ['a' => ['b' => 1]] */
    public static function undot(array $array): array
    {
        $out = [];
        foreach ($array as $key => $value) {
            $out = self::set($out, (string) $key, $value);
        }

        return $out;
    }

    /** @param list<string|int>|string|int $keys */
    public static function only(array $array, array|string|int $keys): array
    {
        return array_intersect_key($array, array_flip((array) $keys));
    }

    /** @param list<string|int>|string|int $keys */
    public static function except(array $array, array|string|int $keys): array
    {
        return self::forget($array, ...(array) $keys);
    }

    /** Values at a dot path from each item, optionally keyed by another path. */
    public static function pluck(iterable $array, string $value, ?string $key = null): array
    {
        $out = [];
        foreach ($array as $item) {
            $v = self::get($item, $value);
            if ($key === null) {
                $out[] = $v;
            } else {
                $k = self::get($item, $key);
                $out[is_scalar($k) ? $k : (string) json_encode($k)] = $v;
            }
        }

        return $out;
    }

    /** @param string|Closure(mixed, array-key): array-key $key */
    public static function keyBy(iterable $array, string|Closure $key): array
    {
        $out = [];
        foreach ($array as $i => $item) {
            $out[$key instanceof Closure ? $key($item, $i) : self::get($item, $key)] = $item;
        }

        return $out;
    }

    /** @param string|Closure(mixed, array-key): array-key $key */
    public static function groupBy(iterable $array, string|Closure $key, bool $preserveKeys = false): array
    {
        $out = [];
        foreach ($array as $i => $item) {
            $group = $key instanceof Closure ? $key($item, $i) : self::get($item, $key);
            $group = is_bool($group) ? (int) $group : (is_scalar($group) || $group === null ? (string) $group : (string) json_encode($group));
            if ($preserveKeys) {
                $out[$group][$i] = $item;
            } else {
                $out[$group][] = $item;
            }
        }

        return $out;
    }

    public static function flatten(iterable $array, int $depth = PHP_INT_MAX): array
    {
        $out = [];
        foreach ($array as $item) {
            if (!is_iterable($item)) {
                $out[] = $item;
            } elseif ($depth === 1) {
                array_push($out, ...array_values(is_array($item) ? $item : iterator_to_array($item, false)));
            } else {
                array_push($out, ...self::flatten($item, $depth - 1));
            }
        }

        return $out;
    }

    /** Merge a list of arrays into one list. */
    public static function collapse(iterable $array): array
    {
        $out = [];
        foreach ($array as $item) {
            if (is_array($item)) {
                array_push($out, ...array_values($item));
            }
        }

        return $out;
    }

    public static function wrap(mixed $value): array
    {
        return $value === null ? [] : (is_array($value) ? $value : [$value]);
    }

    /** @param (Closure(mixed, array-key): bool)|null $callback */
    public static function first(iterable $array, ?Closure $callback = null, mixed $default = null): mixed
    {
        foreach ($array as $key => $value) {
            if ($callback === null || $callback($value, $key)) {
                return $value;
            }
        }

        return self::resolve($default);
    }

    /** @param (Closure(mixed, array-key): bool)|null $callback */
    public static function last(array $array, ?Closure $callback = null, mixed $default = null): mixed
    {
        if ($callback === null) {
            return $array === [] ? self::resolve($default) : end($array);
        }

        return self::first(array_reverse($array, true), $callback, $default);
    }

    /** Keep entries where $callback(value, key) is true (keys preserved). */
    public static function where(array $array, Closure $callback): array
    {
        return array_filter($array, $callback, ARRAY_FILTER_USE_BOTH);
    }

    public static function whereNotNull(array $array): array
    {
        return array_filter($array, static fn($v) => $v !== null);
    }

    /** @param Closure(mixed, array-key): mixed $callback map values, keys preserved */
    public static function map(array $array, Closure $callback): array
    {
        $keys = array_keys($array);

        return array_combine($keys, array_map($callback, $array, $keys));
    }

    /** @param Closure(mixed, array-key): array<array-key, mixed> $callback returns [key => value] */
    public static function mapWithKeys(iterable $array, Closure $callback): array
    {
        $out = [];
        foreach ($array as $key => $value) {
            foreach ($callback($value, $key) as $k => $v) {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    /**
     * Sort by one or more keys/paths or a callback.
     *   Arr::sortBy($rows, ['status', 'name'])   Arr::sortBy($rows, ['score' => 'desc', 'name' => 'asc'])
     *
     * @param string|Closure(mixed): mixed|array<int|string, string> $by
     */
    public static function sortBy(array $array, string|Closure|array $by, bool $descending = false, bool $preserveKeys = false): array
    {
        $criteria = [];
        if (is_array($by)) {
            foreach ($by as $k => $v) {
                $criteria[] = is_int($k) ? [$v, $descending] : [$k, strtolower((string) $v) === 'desc'];
            }
        } else {
            $criteria[] = [$by, $descending];
        }

        $compare = static function ($a, $b) use ($criteria): int {
            foreach ($criteria as [$key, $desc]) {
                $x = $key instanceof Closure ? $key($a) : self::get($a, $key);
                $y = $key instanceof Closure ? $key($b) : self::get($b, $key);
                $cmp = is_string($x) && is_string($y) ? strnatcasecmp($x, $y) : ($x <=> $y);
                if ($cmp !== 0) {
                    return $desc ? -$cmp : $cmp;
                }
            }
            return 0;
        };

        $preserveKeys ? uasort($array, $compare) : usort($array, $compare);

        return $array;
    }

    /** @return array{0: array, 1: array} [passing, failing], keys preserved */
    public static function partition(iterable $array, Closure $callback): array
    {
        $pass = $fail = [];
        foreach ($array as $key => $value) {
            if ($callback($value, $key)) {
                $pass[$key] = $value;
            } else {
                $fail[$key] = $value;
            }
        }

        return [$pass, $fail];
    }

    /** @return list<array> */
    public static function chunk(array $array, int $size, bool $preserveKeys = false): array
    {
        return $size < 1 ? [] : array_chunk($array, $size, $preserveKeys);
    }

    public static function isList(array $array): bool
    {
        return array_is_list($array);
    }

    public static function isAssoc(array $array): bool
    {
        return !array_is_list($array);
    }

    public static function prepend(array $array, mixed $value, string|int|null $key = null): array
    {
        return $key === null ? [$value, ...$array] : [$key => $value] + $array;
    }

    /** Build an RFC 3986 query string; nulls are skipped. */
    public static function query(array $params): string
    {
        return http_build_query(self::stripNulls($params), '', '&', PHP_QUERY_RFC3986);
    }

    private static function stripNulls(array $params): array
    {
        $out = [];
        foreach ($params as $k => $v) {
            if ($v === null) {
                continue;
            }
            $out[$k] = is_array($v) ? self::stripNulls($v) : (is_bool($v) ? (int) $v : $v);
        }

        return $out;
    }

    /** Fisher-Yates shuffle with a CSPRNG (keys not preserved). */
    public static function shuffle(array $array): array
    {
        $array = array_values($array);
        for ($i = count($array) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$array[$i], $array[$j]] = [$array[$j], $array[$i]];
        }

        return $array;
    }

    /** One random item, or a list of $count items (CSPRNG). */
    public static function random(array $array, ?int $count = null): mixed
    {
        if ($array === []) {
            throw new \InvalidArgumentException('Cannot pick from an empty array.');
        }
        if ($count === null) {
            $values = array_values($array);

            return $values[random_int(0, count($values) - 1)];
        }
        if ($count > count($array)) {
            throw new \InvalidArgumentException("Asked for {$count} items, the array has " . count($array) . '.');
        }

        return array_slice(self::shuffle($array), 0, $count);
    }
}
