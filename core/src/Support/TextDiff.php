<?php

declare(strict_types=1);

namespace Kestello\Support;

/**
 * What changed between two texts: line by line, and word by word inside
 * a line that was changed rather than added or removed.
 *
 *   TextDiff::lines("Hello world\nBye", "Hello there\nBye")
 *   // [['op' => 'change', 'parts' => [['equal', 'Hello '], ['delete', 'world'], ['insert', 'there']]],
 *   //  ['op' => 'equal', 'text' => 'Bye']]
 *
 * Longest common subsequence, so the result is minimal. Inputs too large
 * to compare in reasonable time (more than MAX_CELLS comparisons) come
 * back as everything removed, then everything added.
 *
 * @api
 */
final class TextDiff
{
    public const int MAX_CELLS = 250_000;       // about 20 MB for the table

    /**
     * @return list<array{op: 'equal'|'insert'|'delete', text: string}|array{op: 'change', parts: list<array{0: 'equal'|'insert'|'delete', 1: string}>}>
     */
    public static function lines(string $before, string $after): array
    {
        $a = $before === '' ? [] : explode("\n", str_replace("\r\n", "\n", $before));
        $b = $after === '' ? [] : explode("\n", str_replace("\r\n", "\n", $after));
        $out = [];
        $deleted = $inserted = [];
        $flush = static function () use (&$out, &$deleted, &$inserted): void {
            // Pair removed and added lines: a pair is a change, compared word by word.
            $pairs = min(count($deleted), count($inserted));
            for ($i = 0; $i < $pairs; $i++) {
                $out[] = ['op' => 'change', 'parts' => self::words($deleted[$i], $inserted[$i])];
            }
            foreach (array_slice($deleted, $pairs) as $line) {
                $out[] = ['op' => 'delete', 'text' => $line];
            }
            foreach (array_slice($inserted, $pairs) as $line) {
                $out[] = ['op' => 'insert', 'text' => $line];
            }
            $deleted = $inserted = [];
        };
        foreach (self::sequence($a, $b) as [$op, $line]) {
            if ($op === 'equal') {
                $flush();
                $out[] = ['op' => 'equal', 'text' => $line];
            } elseif ($op === 'delete') {
                $deleted[] = $line;
            } else {
                $inserted[] = $line;
            }
        }
        $flush();

        return $out;
    }

    /**
     * Word by word, with runs of the same operation merged.
     *
     * @return list<array{0: 'equal'|'insert'|'delete', 1: string}>
     */
    public static function words(string $before, string $after): array
    {
        $split = static fn(string $s): array => $s === '' ? [] : (preg_split('/(\s+)/u', $s, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [$s]);
        $parts = [];
        foreach (self::sequence($split($before), $split($after)) as [$op, $token]) {
            $last = array_key_last($parts);
            if ($last !== null && $parts[$last][0] === $op) {
                $parts[$last][1] .= $token;
            } else {
                $parts[] = [$op, $token];
            }
        }

        return $parts;
    }

    /** True when nothing changed. @param list<array<string, mixed>> $lines */
    public static function same(array $lines): bool
    {
        foreach ($lines as $line) {
            if ($line['op'] !== 'equal') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     * @return list<array{0: 'equal'|'insert'|'delete', 1: string}>
     */
    private static function sequence(array $a, array $b): array
    {
        // Common start and end first: most edits touch a small part of a long text.
        $start = 0;
        while ($start < count($a) && $start < count($b) && $a[$start] === $b[$start]) {
            $start++;
        }
        $endA = count($a);
        $endB = count($b);
        while ($endA > $start && $endB > $start && $a[$endA - 1] === $b[$endB - 1]) {
            $endA--;
            $endB--;
        }
        $head = array_map(static fn(string $t) => ['equal', $t], array_slice($a, 0, $start));
        $tail = array_map(static fn(string $t) => ['equal', $t], array_slice($a, $endA));
        $x = array_slice($a, $start, $endA - $start);
        $y = array_slice($b, $start, $endB - $start);
        $n = count($x);
        $m = count($y);

        if ($n === 0 || $m === 0 || $n * $m > self::MAX_CELLS) {
            $middle = [...array_map(static fn(string $t) => ['delete', $t], $x), ...array_map(static fn(string $t) => ['insert', $t], $y)];

            return [...$head, ...$middle, ...$tail];
        }

        // lengths[i][j]: LCS of x[i..] and y[j..], one row at a time from the end.
        $lengths = array_fill(0, $n + 1, null);
        $lengths[$n] = array_fill(0, $m + 1, 0);
        for ($i = $n - 1; $i >= 0; $i--) {
            $row = array_fill(0, $m + 1, 0);
            $below = $lengths[$i + 1];
            for ($j = $m - 1; $j >= 0; $j--) {
                $row[$j] = $x[$i] === $y[$j] ? $below[$j + 1] + 1 : max($below[$j], $row[$j + 1]);
            }
            $lengths[$i] = $row;
        }
        $middle = [];
        $i = $j = 0;
        while ($i < $n && $j < $m) {
            if ($x[$i] === $y[$j]) {
                $middle[] = ['equal', $x[$i]];
                $i++;
                $j++;
            } elseif ($lengths[$i + 1][$j] >= $lengths[$i][$j + 1]) {
                $middle[] = ['delete', $x[$i++]];
            } else {
                $middle[] = ['insert', $y[$j++]];
            }
        }
        while ($i < $n) {
            $middle[] = ['delete', $x[$i++]];
        }
        while ($j < $m) {
            $middle[] = ['insert', $y[$j++]];
        }

        return [...$head, ...$middle, ...$tail];
    }
}
