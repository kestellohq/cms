<?php

declare(strict_types=1);

namespace Kestello\Framework\Mail;

use InvalidArgumentException;

/**
 * Minimal, logic-free email templates (pages are Vue; emails stay server-side).
 *
 *   {{ key }}        HTML-escaped value (dot paths: {{ user.name }})
 *   {!! key !!}      raw value: only for HTML you built and escaped yourself
 *
 * Unknown keys render as an empty string. No loops or conditionals by design:
 * build lists in PHP and pass them as pre-escaped HTML.
 */
final class MailTemplate
{
    /**
     * @param array<string, mixed> $data
     */
    public static function render(string $template, array $data): string
    {
        return (string) preg_replace_callback(
            '/\{!!\s*([A-Za-z0-9_.]+)\s*!!\}|\{\{\s*([A-Za-z0-9_.]+)\s*\}\}/',
            static function (array $m) use ($data): string {
                $raw = ($m[1] ?? '') !== '';
                $value = self::lookup($data, $raw ? $m[1] : $m[2]);
                $string = is_scalar($value) || $value instanceof \Stringable ? (string) $value : '';

                return $raw ? $string : htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
            },
            $template,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function renderFile(string $file, array $data): string
    {
        if (!is_file($file)) {
            throw new InvalidArgumentException("Mail template not found: {$file}");
        }

        return self::render((string) file_get_contents($file), $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function lookup(array $data, string $path): mixed
    {
        $value = $data;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}
