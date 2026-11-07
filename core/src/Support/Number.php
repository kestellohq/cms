<?php

declare(strict_types=1);

namespace Kestello\Support;

use NumberFormatter;

/**
 * Locale-aware number formatting (intl), with plain fallbacks when the
 * extension is missing.
 *
 *   Number::currency(1234.5, 'EUR', 'de_DE')   // "1.234,50 €"
 *   Number::fileSize(1_468_006)                // "1.4 MB"
 *   Number::abbreviate(1_250_000)              // "1.3M"
 *
 * @api
 */
final class Number
{
    public static function format(int|float $number, ?int $decimals = null, ?string $locale = null): string
    {
        $f = self::formatter($locale, NumberFormatter::DECIMAL);
        if ($f === null) {
            return number_format($number, $decimals ?? (is_float($number) ? 2 : 0));
        }
        if ($decimals !== null) {
            $f->setAttribute(NumberFormatter::FRACTION_DIGITS, $decimals);
        }

        return (string) $f->format($number);
    }

    public static function currency(int|float $amount, string $currency = 'USD', ?string $locale = null): string
    {
        $f = self::formatter($locale, NumberFormatter::CURRENCY);

        return $f === null ? strtoupper($currency) . ' ' . number_format($amount, 2) : (string) $f->formatCurrency($amount, strtoupper($currency));
    }

    /** Number::percentage(12.5) → "12.5%" (input is 0–100). */
    public static function percentage(int|float $value, int $decimals = 0, ?string $locale = null, int $maxDecimals = 1): string
    {
        $f = self::formatter($locale, NumberFormatter::PERCENT);
        if ($f === null) {
            return number_format($value, max($decimals, 0)) . '%';
        }
        $f->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $decimals);
        $f->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, max($decimals, $maxDecimals));

        return (string) $f->format($value / 100);
    }

    /** Bytes in binary units: 1536 → "1.5 KB". */
    public static function fileSize(int|float $bytes, int $precision = 1): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $i = 0;
        $bytes = max(0, $bytes);
        // 1_048_575 bytes rounds to "1024 KB": move up while the rounded figure reaches the next unit
        while (($i === 0 ? $bytes : round($bytes, $precision)) >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return ($i === 0 ? (string) (int) $bytes : rtrim(rtrim(number_format($bytes, $precision, '.', ''), '0'), '.')) . ' ' . $units[$i];
    }

    /** 1200 → "1.2K", 3_400_000 → "3.4M". */
    public static function abbreviate(int|float $number, int $precision = 1): string
    {
        $abs = abs($number);
        $units = ['' => 1, 'K' => 1e3, 'M' => 1e6, 'B' => 1e9, 'T' => 1e12];
        $suffixes = array_keys($units);
        $i = 0;
        // pick the unit, then move up when rounding reaches 1000 (999_950 is "1M", not "1000K")
        while ($i < count($suffixes) - 1 && $abs >= $units[$suffixes[$i + 1]]) {
            $i++;
        }
        while ($i > 0 && $i < count($suffixes) - 1 && round($abs / $units[$suffixes[$i]], $precision) >= 1000) {
            $i++;
        }
        if ($i > 0) {
            return rtrim(rtrim(number_format($number / $units[$suffixes[$i]], $precision, '.', ''), '0'), '.') . $suffixes[$i];
        }
        if (round($abs, $precision) >= 1000) {
            return ($number < 0 ? '-' : '') . '1K';
        }

        return (string) (is_float($number) ? round($number, $precision) : $number);
    }

    /** 1 → "1st", 22 → "22nd" (locale rules via intl). */
    public static function ordinal(int $number, ?string $locale = null): string
    {
        $f = self::formatter($locale, NumberFormatter::ORDINAL);
        if ($f !== null) {
            return (string) $f->format($number);
        }
        $n = abs($number);
        $suffix = in_array($n % 100, [11, 12, 13], true) ? 'th' : (['th', 'st', 'nd', 'rd'][$n % 10] ?? 'th');

        return $number . $suffix;
    }

    /** 42 → "forty-two" (any intl locale). */
    public static function spell(int|float $number, ?string $locale = null): string
    {
        $f = self::formatter($locale, NumberFormatter::SPELLOUT);

        return $f === null ? (string) $number : (string) $f->format($number);
    }

    public static function clamp(int|float $number, int|float $min, int|float $max): int|float
    {
        return min(max($number, $min), $max);
    }

    private static function formatter(?string $locale, int $style): ?NumberFormatter
    {
        if (!class_exists(NumberFormatter::class)) {
            return null;
        }
        $f = new NumberFormatter($locale ?? (\Locale::getDefault() ?: 'en'), $style);

        return intl_is_failure($f->getErrorCode()) ? null : $f;
    }
}
