<?php

declare(strict_types=1);

namespace Kestello\Support;

use Generator;
use InvalidArgumentException;

/**
 * Streaming CSV (constant memory).
 *
 *   foreach (Csv::read($path) as $row) { $row['Email'] ... }      // header mapped, BOM stripped, delimiter detected
 *   Csv::write($path, ['Name', 'Email'], $rows, bom: true);        // Excel-friendly UTF-8
 *
 * Exports escape cells that start with = + - @ (and tab/CR) by prefixing a
 * quote, so a spreadsheet never runs them as formulas (CSV injection).
 *
 * @api
 */
final class Csv
{
    public const string BOM = "\xEF\xBB\xBF";

    /**
     * Rows as header => value maps (or lists with $header = false).
     *
     * @param string|resource $source path or readable stream
     * @return Generator<int, array<string|int, string>>
     */
    public static function read(mixed $source, ?string $delimiter = null, bool $header = true, ?int $maxRows = null): Generator
    {
        [$handle, $owned] = self::open($source, 'rb');
        try {
            $first = (string) fgets($handle);
            if (str_starts_with($first, self::BOM)) {
                $first = substr($first, 3);
            }
            $delimiter ??= self::detectDelimiter($first);
            // Re-parse the first line through fgetcsv (it may contain quotes) by chaining it in front.
            $stream = fopen('php://temp', 'w+');
            fwrite($stream, $first);
            stream_copy_to_stream($handle, $stream);
            rewind($stream);

            $keys = null;
            $count = 0;
            while (($row = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
                if ($row === [null]) {
                    continue; // blank line
                }
                $row = array_map(static fn($v) => (string) $v, $row);
                if ($header && $keys === null) {
                    $keys = self::headerKeys($row);
                    continue;
                }
                if ($maxRows !== null && $count >= $maxRows) {
                    break;
                }
                $count++;
                if ($keys === null) {
                    yield $row;
                    continue;
                }
                $row = array_pad(array_slice($row, 0, count($keys)), count($keys), '');
                yield array_combine($keys, $row);
            }
            fclose($stream);
        } finally {
            if ($owned) {
                fclose($handle);
            }
        }
    }

    /**
     * Write rows (lists, or maps in $headers order).
     *
     * @param string|resource            $target path or writable stream
     * @param list<string>               $headers
     * @param iterable<array<mixed>>     $rows
     * @return int rows written
     */
    public static function write(mixed $target, array $headers, iterable $rows, string $delimiter = ',', bool $bom = false, bool $escapeFormulas = true): int
    {
        [$handle, $owned] = self::open($target, 'wb');
        try {
            if ($bom) {
                fwrite($handle, self::BOM);
            }
            if ($headers !== []) {
                fputcsv($handle, array_map(fn($v) => self::cell($v, $escapeFormulas), $headers), $delimiter, '"', '', "\r\n");
            }
            $count = 0;
            foreach ($rows as $row) {
                $values = $headers !== [] && !array_is_list($row)
                    ? array_map(static fn(string $h) => $row[$h] ?? '', $headers)
                    : array_values($row);
                fputcsv($handle, array_map(fn($v) => self::cell($v, $escapeFormulas), $values), $delimiter, '"', '', "\r\n");
                $count++;
            }

            return $count;
        } finally {
            if ($owned) {
                fclose($handle);
            }
        }
    }

    /**
     * @param list<string>           $headers
     * @param iterable<array<mixed>> $rows
     */
    public static function toString(array $headers, iterable $rows, string $delimiter = ',', bool $escapeFormulas = true): string
    {
        $stream = fopen('php://temp', 'w+');
        self::write($stream, $headers, $rows, $delimiter, false, $escapeFormulas);
        rewind($stream);
        $out = (string) stream_get_contents($stream);
        fclose($stream);

        return $out;
    }

    /** The most frequent of , ; tab | outside quotes in the first line. */
    public static function detectDelimiter(string $line): string
    {
        $unquoted = (string) preg_replace('/"(?:[^"]|"")*"/', '', $line);
        $best = ',';
        $bestCount = 0;
        foreach ([',', ';', "\t", '|'] as $candidate) {
            $count = substr_count($unquoted, $candidate);
            if ($count > $bestCount) {
                [$best, $bestCount] = [$candidate, $count];
            }
        }

        return $best;
    }

    private static function cell(mixed $value, bool $escapeFormulas): string
    {
        $value = match (true) {
            $value === null => '',
            is_bool($value) => $value ? '1' : '0',
            $value instanceof \DateTimeInterface => $value->format('Y-m-d H:i:s'),
            $value instanceof \Stringable, is_scalar($value) => (string) $value,
            default => Json::encode($value),
        };
        if ($escapeFormulas && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) && !is_numeric($value)) {
            return "'" . $value;
        }

        return $value;
    }

    /** @param list<string> $row @return list<string> */
    private static function headerKeys(array $row): array
    {
        $keys = [];
        foreach ($row as $i => $name) {
            $name = trim($name);
            $name = $name === '' ? 'column_' . ($i + 1) : $name;
            $base = $name;
            for ($n = 2; in_array($name, $keys, true); $n++) {
                $name = "{$base}_{$n}";
            }
            $keys[] = $name;
        }

        return $keys;
    }

    /** @return array{0: resource, 1: bool} */
    private static function open(mixed $source, string $mode): array
    {
        if (is_resource($source)) {
            return [$source, false];
        }
        if (!is_string($source) || $source === '') {
            throw new InvalidArgumentException('Expected a path or a stream.');
        }
        $handle = @fopen($source, $mode);
        if ($handle === false) {
            throw new \RuntimeException("Cannot open {$source}.");
        }

        return [$handle, true];
    }
}
