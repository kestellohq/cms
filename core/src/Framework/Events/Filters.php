<?php

declare(strict_types=1);

namespace Kestello\Framework\Events;

use Kestello\Framework\Core\ExecutionContext;

/**
 * Named value filters.
 *
 *   $filters->add('acme.share_text', fn(string $text, Entry $e) => $text . ' via Acme');
 *   $text = $filters->apply('acme.share_text', $entry->title, $entry);
 *
 * Callbacks receive the current value first, then any extra arguments,
 * and must return the (possibly changed) value. Higher priority runs first.
 *
 * In strict mode (debug), a callback that changes the value's type throws,
 * naming the extension responsible. Moving between null and a value is
 * allowed, for filters whose value is nullable.
 *
 * @api
 */
final class Filters
{
    /** @var array<string, list<array{callback: callable, priority: int, owner: string, seq: int}>> */
    private array $filters = [];

    /** @var array<string, bool> */
    private array $sorted = [];

    private int $seq = 0;

    /** @var array<string, array<string, true>> name as added => owners */
    private array $uses = [];

    /** @var array<string, string> deprecated name => the name it forwards to */
    private array $aliases = [];

    public function __construct(
        private readonly bool $strict = false,
    ) {}

    public function add(string $name, callable $callback, int $priority = 0): void
    {
        $owner = ExecutionContext::current();
        $this->uses[$name][$owner] = true;
        $name = $this->aliases[$name] ?? $name;
        $this->filters[$name][] = [
            'callback' => $callback,
            'priority' => $priority,
            'owner' => $owner,
            'seq' => $this->seq++,
        ];
        unset($this->sorted[$name]);
    }

    /**
     * A renamed filter: callbacks added under $old from now on run as part
     * of $new. The value and arguments must be the same.
     */
    public function alias(string $old, string $new): void
    {
        $this->aliases[$old] = $new;
    }

    /**
     * Who added callbacks, by the name they used (before aliases).
     *
     * @return array<string, list<string>>
     */
    public function uses(): array
    {
        return array_map(static fn(array $owners) => array_keys($owners), $this->uses);
    }

    public function apply(string $name, mixed $value, mixed ...$args): mixed
    {
        if (empty($this->filters[$name])) {
            return $value;
        }

        if (!isset($this->sorted[$name])) {
            usort($this->filters[$name], static fn(array $a, array $b): int => [$b['priority'], $a['seq']] <=> [$a['priority'], $b['seq']]);
            $this->sorted[$name] = true;
        }

        foreach ($this->filters[$name] as $entry) {
            $before = get_debug_type($value);
            $value = ExecutionContext::run($entry['owner'], static fn() => ($entry['callback'])($value, ...$args));

            // Nullable values (media.scan starts as null, a scanner returns a message) may move between null and a value.
            if ($this->strict && get_debug_type($value) !== $before && $before !== 'null' && $value !== null) {
                throw new \UnexpectedValueException(sprintf(
                    'Filter "%s" from %s changed the value type from %s to %s.',
                    $name,
                    $entry['owner'],
                    $before,
                    get_debug_type($value),
                ));
            }
        }

        return $value;
    }

    public function has(string $name): bool
    {
        return !empty($this->filters[$name]);
    }
}
