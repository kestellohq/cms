<?php

declare(strict_types=1);

namespace Kestello\Support;

/**
 * Authenticated encryption with the app key (libsodium). Old app keys in
 * app.previous_keys still decrypt after rotation.
 *
 *   $token = Crypt::encrypt(['user' => 5, 'exp' => time() + 600], 'acme-shop.cart');
 *   $data  = Crypt::decrypt($token, 'acme-shop.cart');
 *
 * $purpose binds a value to its use: a value encrypted for one purpose
 * fails to decrypt for another. Namespace it with your plugin slug.
 *
 * @api
 */
final class Crypt
{
    /** Encrypt any JSON-encodable value. */
    public static function encrypt(mixed $value, string $purpose = 'default'): string
    {
        return self::encryptString(Json::encode($value), $purpose);
    }

    /** @throws \RuntimeException when tampered, wrong purpose or unknown key */
    public static function decrypt(string $payload, string $purpose = 'default'): mixed
    {
        return Json::decode(self::decryptString($payload, $purpose));
    }

    public static function encryptString(#[\SensitiveParameter] string $plaintext, string $purpose = 'default'): string
    {
        return AppKey::secretStore()->seal($plaintext, 'crypt.' . $purpose);
    }

    public static function decryptString(string $payload, string $purpose = 'default'): string
    {
        return AppKey::secretStore()->open($payload, 'crypt.' . $purpose);
    }

    /** Decrypt, or null when invalid (no exception). */
    public static function tryDecrypt(?string $payload, string $purpose = 'default'): mixed
    {
        if ($payload === null || $payload === '') {
            return null;
        }
        try {
            return self::decrypt($payload, $purpose);
        } catch (\Throwable) {
            return null;
        }
    }
}
