<?php

declare(strict_types=1);

namespace Kestello\Support\Jwt;

/** @internal */
final class Base64Url
{
    public static function encode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function decode(string $data): string
    {
        if (preg_match('/^[A-Za-z0-9_-]*$/', $data) !== 1) {
            throw new JwtException('Invalid base64url.');
        }
        $out = base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4), true);
        if ($out === false) {
            throw new JwtException('Invalid base64url.');
        }

        return $out;
    }
}
