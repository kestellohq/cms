<?php

declare(strict_types=1);

namespace Kestello\Cms\Media\Image;

/**
 * Lossless metadata work on encoded bytes: read the JPEG orientation, drop
 * EXIF/GPS/XMP/comments from JPEG, PNG and WebP without re-encoding, and
 * spot animations. Malformed input returns null (callers keep the file as
 * it is or re-encode).
 *
 * @internal
 */
final class Metadata
{
    /** EXIF orientation 1–8 of a JPEG (1 when absent or unreadable). */
    public static function jpegOrientation(string $bytes): int
    {
        if (!str_starts_with($bytes, "\xFF\xD8")) {
            return 1;
        }
        $pos = 2;
        $len = strlen($bytes);
        while ($pos + 4 <= $len && $bytes[$pos] === "\xFF") {
            $marker = ord($bytes[$pos + 1]);
            if ($marker === 0xDA || $marker === 0xD9) {
                break;
            }
            $size = unpack('n', substr($bytes, $pos + 2, 2))[1];
            if ($marker === 0xE1 && substr($bytes, $pos + 4, 6) === "Exif\0\0") {
                return self::tiffOrientation(substr($bytes, $pos + 10, $size - 8));
            }
            $pos += 2 + $size;
        }

        return 1;
    }

    private static function tiffOrientation(string $tiff): int
    {
        if (strlen($tiff) < 8) {
            return 1;
        }
        $le = substr($tiff, 0, 2) === 'II';
        $u16 = static fn(int $o): int => strlen($tiff) >= $o + 2 ? unpack($le ? 'v' : 'n', substr($tiff, $o, 2))[1] : 0;
        $u32 = static fn(int $o): int => strlen($tiff) >= $o + 4 ? unpack($le ? 'V' : 'N', substr($tiff, $o, 4))[1] : 0;
        $ifd = $u32(4);
        $count = $u16($ifd);
        for ($i = 0; $i < min($count, 200); $i++) {
            $entry = $ifd + 2 + $i * 12;
            if ($u16($entry) === 0x0112) {
                $value = $u16($entry + 8);

                return $value >= 1 && $value <= 8 ? $value : 1;
            }
        }

        return 1;
    }

    /**
     * JPEG without APP1 (EXIF, GPS, XMP), APP3–APP13, APP15 and comments.
     * JFIF (APP0), the colour profile (APP2) and Adobe (APP14) stay.
     */
    public static function stripJpeg(string $bytes): ?string
    {
        if (!str_starts_with($bytes, "\xFF\xD8")) {
            return null;
        }
        $out = "\xFF\xD8";
        $pos = 2;
        $len = strlen($bytes);
        while ($pos + 4 <= $len) {
            if ($bytes[$pos] !== "\xFF") {
                return null;
            }
            $marker = ord($bytes[$pos + 1]);
            if ($marker === 0xFF) {          // fill byte
                $pos++;
                continue;
            }
            if ($marker === 0xDA) {          // start of scan: the rest is image data
                return $out . substr($bytes, $pos);
            }
            $size = unpack('n', substr($bytes, $pos + 2, 2))[1];
            if ($size < 2 || $pos + 2 + $size > $len) {
                return null;
            }
            $drop = $marker === 0xFE || $marker === 0xE1 || ($marker >= 0xE3 && $marker <= 0xED) || $marker === 0xEF;
            if (!$drop) {
                $out .= substr($bytes, $pos, 2 + $size);
            }
            $pos += 2 + $size;
        }

        return null;
    }

    /** PNG without eXIf, tEXt, zTXt, iTXt and tIME chunks. */
    public static function stripPng(string $bytes): ?string
    {
        if (!str_starts_with($bytes, "\x89PNG\r\n\x1A\n")) {
            return null;
        }
        $out = substr($bytes, 0, 8);
        $pos = 8;
        $len = strlen($bytes);
        while ($pos + 12 <= $len) {
            $size = unpack('N', substr($bytes, $pos, 4))[1];
            $type = substr($bytes, $pos + 4, 4);
            if ($pos + 12 + $size > $len) {
                return null;
            }
            if (!in_array($type, ['eXIf', 'tEXt', 'zTXt', 'iTXt', 'tIME'], true)) {
                $out .= substr($bytes, $pos, 12 + $size);
            }
            $pos += 12 + $size;
            if ($type === 'IEND') {
                return $out;
            }
        }

        return null;
    }

    /** WebP without EXIF and XMP chunks (and their VP8X flags). */
    public static function stripWebp(string $bytes): ?string
    {
        if (strlen($bytes) < 12 || substr($bytes, 0, 4) !== 'RIFF' || substr($bytes, 8, 4) !== 'WEBP') {
            return null;
        }
        $body = '';
        $pos = 12;
        $len = strlen($bytes);
        while ($pos + 8 <= $len) {
            $type = substr($bytes, $pos, 4);
            $size = unpack('V', substr($bytes, $pos + 4, 4))[1];
            $padded = $size + ($size & 1);
            if ($pos + 8 + $size > $len) {
                return null;
            }
            $chunk = substr($bytes, $pos, 8 + $padded);
            if ($type === 'VP8X' && $size >= 1) {
                $chunk[8] = chr(ord($chunk[8]) & ~0x0C);   // clear EXIF (0x08) and XMP (0x04)
            }
            if ($type !== 'EXIF' && $type !== 'XMP ') {
                $body .= $chunk;
            }
            $pos += 8 + $padded;
        }

        return 'RIFF' . pack('V', strlen($body) + 4) . 'WEBP' . $body;
    }

    /** Whether a GIF, PNG (APNG) or WebP has more than one frame. */
    public static function animated(string $bytes, string $mime): bool
    {
        return match ($mime) {
            'image/gif' => substr_count($bytes, "\x21\xF9\x04") > 1 && preg_match_all('/\x00\x21\xF9\x04.{4}\x00(\x2C|\x21)/s', $bytes) > 1,
            'image/png' => ($i = strpos($bytes, 'acTL')) !== false && $i < (int) (strpos($bytes, 'IDAT') ?: PHP_INT_MAX),
            'image/webp' => substr($bytes, 12, 4) === 'VP8X' && (ord($bytes[20] ?? "\0") & 0x02) !== 0,
            default => false,
        };
    }
}
