<?php

declare(strict_types=1);

namespace Kestello\Support;

use JsonException;

/**
 * JSON that throws instead of returning null/false, with size and depth
 * limits for untrusted input.
 *
 * @api
 */
final class Json
{
    public const int MAX_BYTES = 10_485_760; // 10 MB

    public static function encode(mixed $value, int $flags = 0, int $depth = 512): string
    {
        return json_encode($value, $flags | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION, $depth);
    }

    public static function pretty(mixed $value): string
    {
        return self::encode($value, JSON_PRETTY_PRINT);
    }

    /**
     * @param bool $assoc objects as arrays (default) or stdClass
     * @throws JsonException
     */
    public static function decode(string $json, bool $assoc = true, int $depth = 64, int $maxBytes = self::MAX_BYTES): mixed
    {
        if (strlen($json) > $maxBytes) {
            throw new JsonException("JSON input is larger than {$maxBytes} bytes.");
        }

        return json_decode($json, $assoc, $depth, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
    }

    /** Decode, or return $default for invalid input. */
    public static function tryDecode(?string $json, mixed $default = null, bool $assoc = true, int $depth = 64): mixed
    {
        if ($json === null || $json === '') {
            return $default;
        }
        try {
            return self::decode($json, $assoc, $depth);
        } catch (JsonException) {
            return $default;
        }
    }

    public static function isValid(string $json, int $depth = 512): bool
    {
        return json_validate($json, $depth);
    }
}
