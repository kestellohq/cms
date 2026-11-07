<?php

declare(strict_types=1);

namespace Kestello\Support;

/**
 * Fluent strings: str($title)->squish()->limit(60)->slug().
 * Every Str method that takes the string first is available; methods
 * returning a string return a new Stringable, others return their result.
 *
 * @method self ascii() @method self slug(string $separator = '-') @method self camel() @method self studly()
 * @method self snake(string $delimiter = '_') @method self kebab() @method self title() @method self headline()
 * @method self lower() @method self upper() @method self ucfirst() @method int length()
 * @method self limit(int $limit = 100, string $end = '…') @method self words(int $words = 100, string $end = '…')
 * @method self excerpt(int $length = 160, string $end = '…') @method self squish()
 * @method self before(string $search) @method self after(string $search) @method self beforeLast(string $search)
 * @method self afterLast(string $search) @method self between(string $from, string $to)
 * @method bool startsWith(string|iterable $needles) @method bool endsWith(string|iterable $needles)
 * @method bool contains(string|iterable $needles, bool $ignoreCase = false) @method bool containsAll(iterable $needles, bool $ignoreCase = false)
 * @method self mask(string $character, int $index, ?int $length = null) @method self padBoth(int $length, string $pad = ' ')
 * @method self padLeft(int $length, string $pad = ' ') @method self padRight(int $length, string $pad = ' ')
 * @method self wrap(string $before, ?string $after = null) @method self start(string $prefix) @method self finish(string $cap)
 * @method int wordCount() @method int readingTime(int $wordsPerMinute = 200) @method self plural(int|float|\Countable|array $count = 2)
 * @method self singular() @method self substr(int $start, ?int $length = null)
 *
 * @api
 */
final class Stringable implements \Stringable, \JsonSerializable
{
    private const array SUBJECT_LAST = ['replaceFirst', 'replaceLast'];

    public function __construct(private readonly string $value = '') {}

    /** @param list<mixed> $arguments */
    public function __call(string $method, array $arguments): mixed
    {
        if (!method_exists(Str::class, $method) || in_array($method, ['of', 'random', 'uuid', 'uuid7', 'ulid'], true)) {
            throw new \BadMethodCallException("Stringable::{$method}() does not exist.");
        }

        $result = in_array($method, self::SUBJECT_LAST, true)
            ? Str::$method(...[...$arguments, $this->value])
            : Str::$method($this->value, ...$arguments);

        return is_string($result) ? new self($result) : $result;
    }

    public function is(string|iterable $patterns): bool
    {
        return Str::is($patterns, $this->value);
    }

    public function replace(string|array $search, string|array $replace): self
    {
        return new self(str_replace($search, $replace, $this->value));
    }

    public function replaceFirst(string $search, string $replace): self
    {
        return new self(Str::replaceFirst($search, $replace, $this->value));
    }

    public function append(string ...$values): self
    {
        return new self($this->value . implode('', $values));
    }

    public function prepend(string ...$values): self
    {
        return new self(implode('', $values) . $this->value);
    }

    public function trim(string $characters = " \t\n\r\0\x0B"): self
    {
        return new self(trim($this->value, $characters));
    }

    /** @return list<string> */
    public function explode(string $delimiter, int $limit = PHP_INT_MAX): array
    {
        return $delimiter === '' ? [$this->value] : explode($delimiter, $this->value, $limit);
    }

    public function isEmpty(): bool
    {
        return $this->value === '';
    }

    public function isNotEmpty(): bool
    {
        return $this->value !== '';
    }

    /** @param \Closure(self): (self|string) $callback */
    public function when(mixed $condition, \Closure $callback): self
    {
        if (!$condition) {
            return $this;
        }
        $result = $callback($this);

        return $result instanceof self ? $result : new self((string) $result);
    }

    /** @param callable(string): mixed $callback */
    public function pipe(callable $callback): self
    {
        return new self((string) $callback($this->value));
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function jsonSerialize(): string
    {
        return $this->value;
    }
}
