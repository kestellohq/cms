<?php

declare(strict_types=1);

namespace Kestello\Cms\Auth;

/**
 * Emails are stored and looked up lowercased and trimmed, so
 * "Ana@Example.com" and "ana@example.com" are one account on every database
 * (Postgres and SQLite compare case-sensitively, MySQL doesn't).
 *
 * @api
 */
final class Emails
{
    public static function normalize(string $email): string
    {
        return mb_strtolower(trim($email), 'UTF-8');
    }

    public static function isValid(string $email): bool
    {
        return strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /** "ana@example.com" → "a**@example.com" for messages that must not leak the address. */
    public static function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1) . str_repeat('*', max(2, mb_strlen($local) - 1)) . ($domain !== '' ? '@' . $domain : '');
    }
}
