<?php

declare(strict_types=1);

namespace Kestello\Support\Jwt;

/** Minimal ASN.1 DER for JWK import and ECDSA signature conversion. @internal */
final class Der
{
    public static function rsaPublicKeyPem(string $n, string $e): string
    {
        $rsaKey = self::sequence(self::integer($n) . self::integer($e));
        $algorithm = self::sequence("\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01" . "\x05\x00"); // rsaEncryption, NULL
        $spki = self::sequence($algorithm . "\x03" . self::length(strlen($rsaKey) + 1) . "\x00" . $rsaKey);

        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    /** JWS raw r||s → DER (what openssl_verify wants). */
    public static function ecdsaFromRaw(string $raw, int $partLength): string
    {
        if (strlen($raw) !== 2 * $partLength) {
            throw new JwtException('Invalid ECDSA signature length.');
        }

        return self::sequence(self::integer(substr($raw, 0, $partLength)) . self::integer(substr($raw, $partLength)));
    }

    /** DER (what openssl_sign returns) → JWS raw r||s. */
    public static function ecdsaToRaw(string $der, int $partLength): string
    {
        $pos = 0;
        if (($der[$pos++] ?? '') !== "\x30") {
            throw new JwtException('Invalid ECDSA signature.');
        }
        self::readLength($der, $pos);
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            if (($der[$pos++] ?? '') !== "\x02") {
                throw new JwtException('Invalid ECDSA signature.');
            }
            $len = self::readLength($der, $pos);
            $int = ltrim(substr($der, $pos, $len), "\x00");
            $pos += $len;
            if (strlen($int) > $partLength) {
                throw new JwtException('Invalid ECDSA signature.');
            }
            $out .= str_pad($int, $partLength, "\x00", STR_PAD_LEFT);
        }

        return $out;
    }

    private static function sequence(string $content): string
    {
        return "\x30" . self::length(strlen($content)) . $content;
    }

    private static function integer(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '' || ord($bytes[0]) > 0x7f) {
            $bytes = "\x00" . $bytes;
        }

        return "\x02" . self::length(strlen($bytes)) . $bytes;
    }

    private static function length(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }
        $bytes = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private static function readLength(string $der, int &$pos): int
    {
        $first = ord($der[$pos++] ?? "\x00");
        if ($first < 0x80) {
            return $first;
        }
        $n = $first & 0x7f;
        $len = 0;
        for ($i = 0; $i < $n; $i++) {
            $len = ($len << 8) | ord($der[$pos++] ?? "\x00");
        }

        return $len;
    }
}
