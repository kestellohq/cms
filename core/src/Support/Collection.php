<?php

declare(strict_types=1);

namespace Kestello\Support;

use ArrayAccess;
use ArrayIterator;
use Closure;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use LogicException;
use Traversable;

/**
 * Immutable list/map. Every method returns a new collection (or a value);
 * the original never changes.
 *
 *   collect($posts)->where(fn($p) => $p['published'])->sortBy('date', descending: true)->pluck('title')->take(5)
 *
 * @template TKey of array-key
 * @template TValue
 * @implements IteratorAggregate<TKey, TValue>
 * @implements ArrayAccess<TKey, TValue>
 * @api
 */
class Collection implements ArrayAccess, Countable, IteratorAggregate, JsonSerializable
{
    /** @var array<TKey, TValue> */
    protected readonly array $items;

    /** @param iterable<TKey, TValue> $items */
    final public function __construct(iterable $items = [])
    {
        $this->items = $items instanceof self ? $items->all() : (is_array($items) ? $items : iterator_to_array($items));
    }

    /** @param iterable<mixed, mixed>|mixed $items */
    public static function make(mixed $items = []): static
    {
        return new static(is_iterable($items) ? $items : Arr::wrap($items));
    }

    /** collect()->times(3, fn($i) => $i * 2) → [2, 4, 6] */
    public static function times(int $count, ?Closure $callback = null): static
    {
        $range = $count < 1 ? [] : range(1, $count);

        return new static($callback === null ? $range : array_map($callback, $range));
    }

    public static function range(int $from, int $to, int $step = 1): static
    {
        return new static(range($from, $to, $step));
    }

    // ── Access ──────────────────────────────────────────────────────

    /** @return array<TKey, TValue> */
    public function all(): array
    {
        return $this->items;
    }

    /** Plain arrays all the way down (nested collections converted). */
    public function toArray(): array
    {
        return array_map(static fn($v) => $v instanceof self ? $v->toArray() : $v, $this->items);
    }

    public function toJson(int $flags = 0): string
    {
        return Json::encode($this->jsonSerialize(), $flags);
    }

    public function jsonSerialize(): array
    {
        return array_map(static fn($v) => $v instanceof JsonSerializable ? $v->jsonSerialize() : $v, $this->items);
    }

    public function get(string|int $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->items) ? $this->items[$key] : ($default instanceof Closure ? $default() : $default);
    }

    public function has(string|int ...$keys): bool
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $this->items)) {
                return false;
            }
        }

        return $keys !== [];
    }

    public function first(?Closure $callback = null, mixed $default = null): mixed
    {
        return Arr::first($this->items, $callback, $default);
    }

    public function last(?Closure $callback = null, mixed $default = null): mixed
    {
        return Arr::last($this->items, $callback, $default);
    }

    /** The only item; throws when there are zero or several. */
    public function sole(?Closure $callback = null): mixed
    {
        $items = $callback === null ? $this->items : array_filter($this->items, $callback, ARRAY_FILTER_USE_BOTH);
        if (count($items) !== 1) {
            throw new LogicException('Expected exactly one item, found ' . count($items) . '.');
        }

        return reset($items);
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function isNotEmpty(): bool
    {
        return $this->items !== [];
    }

    public function keys(): static
    {
        return new static(array_keys($this->items));
    }

    public function values(): static
    {
        return new static(array_values($this->items));
    }

    public function contains(mixed $value): bool
    {
        if ($value instanceof Closure) {
            foreach ($this->items as $k => $v) {
                if ($value($v, $k)) {
                    return true;
                }
            }
            return false;
        }

        return in_array($value, $this->items, true);
    }

    public function search(mixed $value): string|int|false
    {
        if ($value instanceof Closure) {
            foreach ($this->items as $k => $v) {
                if ($value($v, $k)) {
                    return $k;
                }
            }
            return false;
        }

        return array_search($value, $this->items, true);
    }

    // ── Transform ───────────────────────────────────────────────────

    /** @param Closure(TValue, TKey): mixed $callback keys preserved */
    public function map(Closure $callback): static
    {
        return new static(Arr::map($this->items, $callback));
    }

    public function mapWithKeys(Closure $callback): static
    {
        return new static(Arr::mapWithKeys($this->items, $callback));
    }

    public function flatMap(Closure $callback): static
    {
        return new static(Arr::collapse(array_map(
            static fn($v) => $v instanceof self ? $v->all() : Arr::wrap($v),
            Arr::map($this->items, $callback),
        )));
    }

    /** Map items into a class: collect($rows)->mapInto(UserDto::class) */
    public function mapInto(string $class): static
    {
        return $this->map(static fn($v, $k) => new $class($v, $k));
    }

    /** @param (Closure(TValue, TKey): bool)|null $callback keys preserved; null drops falsy values */
    public function filter(?Closure $callback = null): static
    {
        return new static($callback === null ? array_filter($this->items) : array_filter($this->items, $callback, ARRAY_FILTER_USE_BOTH));
    }

    public function reject(Closure $callback): static
    {
        return $this->filter(static fn($v, $k) => !$callback($v, $k));
    }

    /**
     * where(fn) · where('status', 'live') · where('views', '>', 10)
     */
    public function where(string|Closure $key, mixed $operator = null, mixed $value = null): static
    {
        if ($key instanceof Closure) {
            return $this->filter($key);
        }
        if (func_num_args() === 2) {
            [$value, $operator] = [$operator, '='];
        }

        return $this->filter(static function ($item) use ($key, $operator, $value): bool {
            $actual = Arr::get($item, $key);

            return match ($operator) {
                '=', '==' => $actual == $value,
                '===' => $actual === $value,
                '!=', '<>' => $actual != $value,
                '!==' => $actual !== $value,
                '<' => $actual < $value,
                '>' => $actual > $value,
                '<=' => $actual <= $value,
                '>=' => $actual >= $value,
                default => throw new \InvalidArgumentException("Unknown operator \"{$operator}\"."),
            };
        });
    }

    public function whereIn(string $key, iterable $values, bool $strict = false): static
    {
        $values = is_array($values) ? $values : iterator_to_array($values, false);

        return $this->filter(static fn($item) => in_array(Arr::get($item, $key), $values, $strict));
    }

    public function whereNotIn(string $key, iterable $values, bool $strict = false): static
    {
        $values = is_array($values) ? $values : iterator_to_array($values, false);

        return $this->filter(static fn($item) => !in_array(Arr::get($item, $key), $values, $strict));
    }

    public function whereNull(?string $key = null): static
    {
        return $this->filter(static fn($item) => ($key === null ? $item : Arr::get($item, $key)) === null);
    }

    public function whereNotNull(?string $key = null): static
    {
        return $this->filter(static fn($item) => ($key === null ? $item : Arr::get($item, $key)) !== null);
    }

    public function pluck(string $value, ?string $key = null): static
    {
        return new static(Arr::pluck($this->items, $value, $key));
    }

    public function keyBy(string|Closure $key): static
    {
        return new static(Arr::keyBy($this->items, $key));
    }

    /** Groups become collections. */
    public function groupBy(string|Closure $key, bool $preserveKeys = false): static
    {
        return new static(array_map(static fn(array $g) => new static($g), Arr::groupBy($this->items, $key, $preserveKeys)));
    }

    public function countBy(string|Closure|null $key = null): static
    {
        $out = [];
        foreach ($this->items as $k => $item) {
            $group = $key === null ? $item : ($key instanceof Closure ? $key($item, $k) : Arr::get($item, $key));
            $group = is_bool($group) ? (int) $group : (string) $group;
            $out[$group] = ($out[$group] ?? 0) + 1;
        }

        return new static($out);
    }

    public function flatten(int $depth = PHP_INT_MAX): static
    {
        return new static(Arr::flatten(array_map(static fn($v) => $v instanceof self ? $v->all() : $v, $this->items), $depth));
    }

    public function collapse(): static
    {
        return new static(Arr::collapse(array_map(static fn($v) => $v instanceof self ? $v->all() : $v, $this->items)));
    }

    public function flip(): static
    {
        return new static(array_flip($this->items));
    }

    public function only(string|int ...$keys): static
    {
        return new static(Arr::only($this->items, $keys));
    }

    public function except(string|int ...$keys): static
    {
        return new static(array_diff_key($this->items, array_flip($keys)));
    }

    public function select(string ...$keys): static
    {
        return $this->map(static fn($item) => Arr::only((array) $item, $keys));
    }

    // ── Order and slicing ───────────────────────────────────────────

    /** @param string|Closure|array<int|string, string> $by see Arr::sortBy() */
    public function sortBy(string|Closure|array $by, bool $descending = false): static
    {
        return new static(Arr::sortBy($this->items, $by, $descending, preserveKeys: true));
    }

    public function sortByDesc(string|Closure $by): static
    {
        return $this->sortBy($by, true);
    }

    public function sort(?Closure $callback = null): static
    {
        $items = $this->items;
        $callback === null ? asort($items) : uasort($items, $callback);

        return new static($items);
    }

    public function sortDesc(): static
    {
        $items = $this->items;
        arsort($items);

        return new static($items);
    }

    public function sortKeys(bool $descending = false): static
    {
        $items = $this->items;
        $descending ? krsort($items) : ksort($items);

        return new static($items);
    }

    public function reverse(): static
    {
        return new static(array_reverse($this->items, true));
    }

    public function shuffle(): static
    {
        return new static(Arr::shuffle($this->items));
    }

    public function random(?int $count = null): mixed
    {
        $picked = Arr::random($this->items, $count);

        return $count === null ? $picked : new static($picked);
    }

    /** First $limit items (negative: last items). */
    public function take(int $limit): static
    {
        return $limit < 0 ? $this->slice($limit) : $this->slice(0, $limit);
    }

    public function skip(int $count): static
    {
        return $this->slice($count);
    }

    public function slice(int $offset, ?int $length = null): static
    {
        return new static(array_slice($this->items, $offset, $length, true));
    }

    public function takeWhile(Closure $callback): static
    {
        $out = [];
        foreach ($this->items as $k => $v) {
            if (!$callback($v, $k)) {
                break;
            }
            $out[$k] = $v;
        }

        return new static($out);
    }

    public function skipWhile(Closure $callback): static
    {
        $out = [];
        $skipping = true;
        foreach ($this->items as $k => $v) {
            if ($skipping && $callback($v, $k)) {
                continue;
            }
            $skipping = false;
            $out[$k] = $v;
        }

        return new static($out);
    }

    public function forPage(int $page, int $perPage): static
    {
        return $this->slice(max(0, ($page - 1) * $perPage), $perPage);
    }

    /** @return static<int, static> */
    public function chunk(int $size): static
    {
        return new static(array_map(static fn(array $c) => new static($c), Arr::chunk($this->items, $size, true)));
    }

    /** @return static<int, static> two collections: [passing, failing] */
    public function partition(Closure $callback): static
    {
        [$pass, $fail] = Arr::partition($this->items, $callback);

        return new static([new static($pass), new static($fail)]);
    }

    // ── Sets ────────────────────────────────────────────────────────

    /** Unique values, or unique by a key/callback (first occurrence wins). */
    public function unique(string|Closure|null $key = null): static
    {
        $seen = [];
        $out = [];
        foreach ($this->items as $k => $v) {
            $id = $key === null ? $v : ($key instanceof Closure ? $key($v, $k) : Arr::get($v, $key));
            $hash = is_scalar($id) || $id === null ? gettype($id) . ':' . $id : serialize($id);
            if (!isset($seen[$hash])) {
                $seen[$hash] = true;
                $out[$k] = $v;
            }
        }

        return new static($out);
    }

    public function duplicates(string|Closure|null $key = null): static
    {
        $unique = $this->unique($key)->all();

        return new static(array_diff_key($this->items, $unique));
    }

    public function diff(iterable $items): static
    {
        return new static(array_diff($this->items, self::arrayOf($items)));
    }

    public function diffKeys(iterable $items): static
    {
        return new static(array_diff_key($this->items, self::arrayOf($items)));
    }

    public function intersect(iterable $items): static
    {
        return new static(array_intersect($this->items, self::arrayOf($items)));
    }

    public function intersectByKeys(iterable $items): static
    {
        return new static(array_intersect_key($this->items, self::arrayOf($items)));
    }

    public function merge(iterable $items): static
    {
        return new static(array_merge($this->items, self::arrayOf($items)));
    }

    /** Add items whose keys are missing (+ operator). */
    public function union(iterable $items): static
    {
        return new static($this->items + self::arrayOf($items));
    }

    public function combine(iterable $values): static
    {
        return new static(array_combine(array_values($this->items), array_values(self::arrayOf($values))));
    }

    /** zip([1,2], ['a','b']) → [[1,'a'], [2,'b']] */
    public function zip(iterable ...$others): static
    {
        $lists = [array_values($this->items), ...array_map(static fn($o) => array_values(self::arrayOf($o)), $others)];
        $out = [];
        foreach (array_keys($lists[0]) as $i) {
            $out[] = new static(array_map(static fn(array $l) => $l[$i] ?? null, $lists));
        }

        return new static($out);
    }

    public function push(mixed ...$values): static
    {
        return new static([...array_values($this->items), ...$values]);
    }

    public function prepend(mixed $value, string|int|null $key = null): static
    {
        return new static(Arr::prepend($this->items, $value, $key));
    }

    public function put(string|int $key, mixed $value): static
    {
        $items = $this->items;
        $items[$key] = $value;

        return new static($items);
    }

    public function forget(string|int ...$keys): static
    {
        return $this->except(...$keys);
    }

    public function pad(int $size, mixed $value): static
    {
        return new static(array_pad($this->items, $size, $value));
    }

    // ── Aggregates ──────────────────────────────────────────────────

    public function reduce(Closure $callback, mixed $initial = null): mixed
    {
        $carry = $initial;
        foreach ($this->items as $k => $v) {
            $carry = $callback($carry, $v, $k);
        }

        return $carry;
    }

    /** @return list<int|float> numeric values of the items (or at a key/callback) */
    private function numbers(string|Closure|null $key): array
    {
        $values = $key === null ? array_values($this->items) : array_values(Arr::map($this->items, static fn($v, $k) => $key instanceof Closure ? $key($v, $k) : Arr::get($v, $key)));

        return array_values(array_filter($values, static fn($v) => is_int($v) || is_float($v) || (is_string($v) && is_numeric($v))));
    }

    public function sum(string|Closure|null $key = null): int|float
    {
        return array_sum(array_map(static fn($v) => +$v, $this->numbers($key)));
    }

    public function avg(string|Closure|null $key = null): int|float|null
    {
        $n = $this->numbers($key);

        return $n === [] ? null : array_sum(array_map(static fn($v) => +$v, $n)) / count($n);
    }

    public function median(string|Closure|null $key = null): int|float|null
    {
        $n = array_map(static fn($v) => +$v, $this->numbers($key));
        if ($n === []) {
            return null;
        }
        sort($n);
        $mid = intdiv(count($n), 2);

        return count($n) % 2 === 1 ? $n[$mid] : ($n[$mid - 1] + $n[$mid]) / 2;
    }

    public function min(string|Closure|null $key = null): mixed
    {
        $n = $this->numbers($key);

        return $n === [] ? null : min($n);
    }

    public function max(string|Closure|null $key = null): mixed
    {
        $n = $this->numbers($key);

        return $n === [] ? null : max($n);
    }

    /** Whether every item passes. */
    public function every(Closure $callback): bool
    {
        foreach ($this->items as $k => $v) {
            if (!$callback($v, $k)) {
                return false;
            }
        }

        return true;
    }

    public function some(Closure $callback): bool
    {
        return $this->contains($callback);
    }

    public function implode(string $glue, string|Closure|null $key = null): string
    {
        $values = $key === null ? $this->items : Arr::map($this->items, static fn($v, $k) => $key instanceof Closure ? $key($v, $k) : Arr::get($v, $key));

        return implode($glue, array_map(static fn($v) => (string) $v, $values));
    }

    /** "a, b and c" */
    public function join(string $glue, string $finalGlue = ''): string
    {
        $values = array_map('strval', array_values($this->items));
        if ($finalGlue === '' || count($values) < 2) {
            return implode($glue, $values);
        }
        $last = array_pop($values);

        return implode($glue, $values) . $finalGlue . $last;
    }

    // ── Flow ────────────────────────────────────────────────────────

    /** Run $callback for each item (return false to stop); returns the same collection. */
    public function each(Closure $callback): static
    {
        foreach ($this->items as $k => $v) {
            if ($callback($v, $k) === false) {
                break;
            }
        }

        return $this;
    }

    /** Pass the collection to $callback and return its result. */
    public function pipe(Closure $callback): mixed
    {
        return $callback($this);
    }

    /** Call $callback with the collection, return the collection. */
    public function tap(Closure $callback): static
    {
        $callback($this);

        return $this;
    }

    public function when(mixed $condition, Closure $callback, ?Closure $default = null): static
    {
        if ($condition) {
            return $callback($this, $condition) ?? $this;
        }

        return $default !== null ? ($default($this, $condition) ?? $this) : $this;
    }

    public function unless(mixed $condition, Closure $callback, ?Closure $default = null): static
    {
        return $this->when(!$condition, $callback, $default);
    }

    public function lazy(): LazyCollection
    {
        return new LazyCollection($this->items);
    }

    // ── Interfaces ──────────────────────────────────────────────────

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->items[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->items[$offset];
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('Collections are immutable: use put() or push(), which return a new collection.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('Collections are immutable: use forget(), which returns a new collection.');
    }

    private static function arrayOf(iterable $items): array
    {
        return $items instanceof self ? $items->all() : (is_array($items) ? $items : iterator_to_array($items));
    }
}
