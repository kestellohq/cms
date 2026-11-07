<?php

declare(strict_types=1);

namespace Kestello\Support;

use Closure;
use Generator;
use IteratorAggregate;
use Traversable;

/**
 * The collection API over generators: items are produced one at a time,
 * so exports and imports run in constant memory.
 *
 *   LazyCollection::make(fn() => yield from $db->table('users')->lazyById())
 *       ->filter(fn($u) => $u['is_active'])->map(fn($u) => [$u['email']])->chunk(500)->each($writeCsv);
 *
 * @api
 */
final class LazyCollection implements IteratorAggregate
{
    /** @var Closure(): iterable<mixed, mixed> */
    private readonly Closure $source;

    /** @param iterable<mixed, mixed>|Closure(): iterable<mixed, mixed> $source */
    public function __construct(iterable|Closure $source = [])
    {
        $this->source = $source instanceof Closure ? $source : static fn() => $source;
    }

    /** @param iterable<mixed, mixed>|Closure(): iterable<mixed, mixed> $source */
    public static function make(iterable|Closure $source = []): self
    {
        return new self($source);
    }

    public static function range(int $from, int $to): self
    {
        return new self(static function () use ($from, $to): Generator {
            for ($i = $from; $i <= $to; $i++) {
                yield $i;
            }
        });
    }

    public function getIterator(): Traversable
    {
        $items = ($this->source)();

        return (static fn(): Generator => yield from $items)();
    }

    public function map(Closure $callback): self
    {
        return new self(fn(): Generator => (function () use ($callback): Generator {
            foreach ($this as $k => $v) {
                yield $k => $callback($v, $k);
            }
        })());
    }

    public function filter(?Closure $callback = null): self
    {
        $callback ??= static fn($v) => (bool) $v;

        return new self(fn(): Generator => (function () use ($callback): Generator {
            foreach ($this as $k => $v) {
                if ($callback($v, $k)) {
                    yield $k => $v;
                }
            }
        })());
    }

    public function reject(Closure $callback): self
    {
        return $this->filter(static fn($v, $k) => !$callback($v, $k));
    }

    public function pluck(string $value, ?string $key = null): self
    {
        return new self(fn(): Generator => (function () use ($value, $key): Generator {
            foreach ($this as $item) {
                if ($key === null) {
                    yield Arr::get($item, $value);
                } else {
                    yield Arr::get($item, $key) => Arr::get($item, $value);
                }
            }
        })());
    }

    public function take(int $limit): self
    {
        return new self(fn(): Generator => (function () use ($limit): Generator {
            if ($limit <= 0) {
                return;
            }
            $n = 0;
            foreach ($this as $k => $v) {
                yield $k => $v;
                if (++$n >= $limit) {
                    return;
                }
            }
        })());
    }

    public function skip(int $count): self
    {
        return new self(fn(): Generator => (function () use ($count): Generator {
            $n = 0;
            foreach ($this as $k => $v) {
                if ($n++ >= $count) {
                    yield $k => $v;
                }
            }
        })());
    }

    public function takeWhile(Closure $callback): self
    {
        return new self(fn(): Generator => (function () use ($callback): Generator {
            foreach ($this as $k => $v) {
                if (!$callback($v, $k)) {
                    return;
                }
                yield $k => $v;
            }
        })());
    }

    public function skipWhile(Closure $callback): self
    {
        return new self(fn(): Generator => (function () use ($callback): Generator {
            $skipping = true;
            foreach ($this as $k => $v) {
                if ($skipping && $callback($v, $k)) {
                    continue;
                }
                $skipping = false;
                yield $k => $v;
            }
        })());
    }

    /** Chunks are (eager) Collections of $size items. */
    public function chunk(int $size): self
    {
        return new self(fn(): Generator => (function () use ($size): Generator {
            $chunk = [];
            foreach ($this as $k => $v) {
                $chunk[$k] = $v;
                if (count($chunk) >= $size) {
                    yield new Collection($chunk);
                    $chunk = [];
                }
            }
            if ($chunk !== []) {
                yield new Collection($chunk);
            }
        })());
    }

    /** Unique values (keeps a set of seen ids in memory). */
    public function unique(string|Closure|null $key = null): self
    {
        return new self(fn(): Generator => (function () use ($key): Generator {
            $seen = [];
            foreach ($this as $k => $v) {
                $id = $key === null ? $v : ($key instanceof Closure ? $key($v, $k) : Arr::get($v, $key));
                $hash = is_scalar($id) || $id === null ? gettype($id) . ':' . $id : serialize($id);
                if (!isset($seen[$hash])) {
                    $seen[$hash] = true;
                    yield $k => $v;
                }
            }
        })());
    }

    public function values(): self
    {
        return new self(fn(): Generator => (function (): Generator {
            foreach ($this as $v) {
                yield $v;
            }
        })());
    }

    /** Run a side effect per item as items stream through. */
    public function tapEach(Closure $callback): self
    {
        return new self(fn(): Generator => (function () use ($callback): Generator {
            foreach ($this as $k => $v) {
                $callback($v, $k);
                yield $k => $v;
            }
        })());
    }

    // ── Terminal operations ─────────────────────────────────────────

    /** Iterate everything (return false from $callback to stop). */
    public function each(Closure $callback): void
    {
        foreach ($this as $k => $v) {
            if ($callback($v, $k) === false) {
                return;
            }
        }
    }

    public function first(?Closure $callback = null, mixed $default = null): mixed
    {
        foreach ($this as $k => $v) {
            if ($callback === null || $callback($v, $k)) {
                return $v;
            }
        }

        return $default instanceof Closure ? $default() : $default;
    }

    public function reduce(Closure $callback, mixed $initial = null): mixed
    {
        $carry = $initial;
        foreach ($this as $k => $v) {
            $carry = $callback($carry, $v, $k);
        }

        return $carry;
    }

    public function count(): int
    {
        $n = 0;
        foreach ($this as $_) {
            $n++;
        }

        return $n;
    }

    public function sum(string|Closure|null $key = null): int|float
    {
        return $this->reduce(static fn($carry, $v, $k) => $carry + +($key === null ? $v : ($key instanceof Closure ? $key($v, $k) : Arr::get($v, $key))), 0);
    }

    public function all(): array
    {
        return iterator_to_array($this, true);
    }

    /** Materialize into an (eager) Collection. */
    public function collect(): Collection
    {
        return new Collection($this->all());
    }
}
