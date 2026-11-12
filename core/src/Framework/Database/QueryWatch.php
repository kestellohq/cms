<?php

declare(strict_types=1);

namespace Kestello\Framework\Database;

use Kestello\Framework\Log\Log;

/**
 * Watches a connection's statements (Connection::listen) for the two
 * database problems that slow a site down without an error:
 *
 *   - a slow statement: logged at once, with how long it took;
 *   - the same statement many times in one request, the sign of an N+1
 *     (a query per item of a list): logged when the request ends (report()),
 *     once per statement, with how often it ran.
 *
 * The SQL is logged with its placeholders, never the values, which can be
 * personal data. Lists of placeholders count as one shape: "IN (?, ?)" and
 * "IN (?, ?, ?)" are the same statement.
 *
 * KESTELLO_SLOW_QUERY_MS (500 by default; 0 turns it off) and
 * KESTELLO_REPEATED_QUERIES (20 by default; 0 turns it off) set the limits.
 */
final class QueryWatch
{
    private const int SQL_LENGTH = 1000;

    private int $count = 0;
    private float $milliseconds = 0.0;

    /** @var array<string, int> shape => times run */
    private array $shapes = [];

    public function __construct(
        private readonly float $slowMs = 500.0,
        private readonly int $repeats = 20,
    ) {}

    /** @param array<string, string> $env usually getenv() */
    public static function fromEnvironment(array $env): self
    {
        $slow = $env['KESTELLO_SLOW_QUERY_MS'] ?? '';
        $repeats = $env['KESTELLO_REPEATED_QUERIES'] ?? '';

        return new self(
            is_numeric($slow) ? max(0.0, (float) $slow) : 500.0,
            is_numeric($repeats) ? max(0, (int) $repeats) : 20,
        );
    }

    /** The same slow-query limit, without counting repeats (a long-running command). */
    public function withoutRepeats(): self
    {
        return new self($this->slowMs, 0);
    }

    /** Start watching $connection. */
    public function watch(Connection $connection): self
    {
        $connection->listen($this->record(...));

        return $this;
    }

    public function record(string $sql, float $ms): void
    {
        $this->count++;
        $this->milliseconds += $ms;
        if ($this->repeats > 0) {
            $shape = self::shape($sql);
            $this->shapes[$shape] = ($this->shapes[$shape] ?? 0) + 1;
        }
        if ($this->slowMs > 0 && $ms >= $this->slowMs) {
            Log::warning(sprintf('slow query: %d ms', (int) round($ms)), ['sql' => self::shape($sql), 'ms' => round($ms, 1)]);
        }
    }

    /**
     * The end of a request: log each statement that ran $repeats times or
     * more, the most frequent first, and start counting again.
     *
     * @return array<string, int> what was reported: shape => times
     */
    public function report(): array
    {
        $repeated = $this->repeats > 0 ? array_filter($this->shapes, fn(int $times): bool => $times >= $this->repeats) : [];
        arsort($repeated);
        foreach ($repeated as $shape => $times) {
            Log::warning("the same query ran {$times} times in one request: a query per item of a list? Load them together (->with(), or one whereIn).", [
                'sql' => $shape,
                'times' => $times,
                'queries' => $this->count,
                'ms' => round($this->milliseconds, 1),
            ]);
        }
        $this->reset();

        return $repeated;
    }

    /** @return array{queries: int, ms: float} */
    public function totals(): array
    {
        return ['queries' => $this->count, 'ms' => round($this->milliseconds, 1)];
    }

    public function reset(): void
    {
        $this->count = 0;
        $this->milliseconds = 0.0;
        $this->shapes = [];
    }

    /** One line, placeholder lists folded, cut to a length a log line holds. */
    public static function shape(string $sql): string
    {
        $sql = preg_replace('/\s+/', ' ', trim($sql)) ?? $sql;
        $sql = preg_replace('/\?(?:\s*,\s*\?)+/', '?, …', $sql) ?? $sql;

        return mb_strcut($sql, 0, self::SQL_LENGTH);
    }
}
