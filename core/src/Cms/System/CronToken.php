<?php

declare(strict_types=1);

namespace Kestello\Cms\System;

use Kestello\Framework\Settings\Settings;

/** The secret for POST /system/cron. Only its hash is stored. @internal */
final class CronToken
{
    private const string KEY = 'cron.token_hash';

    public static function issue(): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        Settings::getInstance()->set(self::KEY, hash('sha256', $token), 'cron');

        return $token;
    }

    public static function revoke(): void
    {
        Settings::getInstance()->delete(self::KEY);
    }

    public static function configured(): bool
    {
        return is_string(self::hash()) && self::hash() !== '';
    }

    public static function verify(string $token): bool
    {
        $hash = self::hash();

        return is_string($hash) && $hash !== '' && $token !== '' && hash_equals($hash, hash('sha256', $token));
    }

    private static function hash(): ?string
    {
        try {
            $value = Settings::getInstance()->get(self::KEY);
            return is_string($value) ? $value : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
