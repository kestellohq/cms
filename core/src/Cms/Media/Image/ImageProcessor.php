<?php

declare(strict_types=1);

namespace Kestello\Cms\Media\Image;

use Kestello\Contracts\Media\ImageEngine;
use Kestello\Contracts\Media\ImageException;

/**
 * What happens to an uploaded image before it is stored:
 *
 *   1. EXIF orientation is applied and metadata (EXIF, GPS, XMP, comments)
 *      removed: losslessly for JPEG, PNG and WebP when no pixels change,
 *      by re-encoding when the image must turn or shrink
 *   2. originals larger than the maximum edge are scaled down
 *   3. dimensions, average colour, transparency, animation and a tiny
 *      placeholder (LQIP, a data: URI of a ~16 px image) are recorded
 *
 * Animated images are kept as they are (their frames would be lost).
 * Without an engine the file is only stripped where that is lossless and
 * orientation-safe.
 *
 * @internal MediaLibrary
 */
final class ImageProcessor
{
    private const array FORMAT = ['image/jpeg' => 'jpeg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', 'image/avif' => 'avif'];

    public function __construct(private readonly ?ImageEngine $engine, private readonly int $maxEdge = 2560) {}

    /** The format an engine encodes this MIME type as: jpeg, png, gif, webp, avif, or null. */
    public static function format(string $mime): ?string
    {
        return self::FORMAT[$mime] ?? null;
    }

    /** Quality for a re-encoded original: high, since sizes are made from it. */
    public static function quality(string $format): int
    {
        return match ($format) {
            'jpeg', 'webp' => 90, 'avif' => 70, default => 100,
        };
    }

    /**
     * Process the file at $path in place.
     *
     * @return array{width: int, height: int, changed: bool, meta: array<string, mixed>}
     * @throws ImageException when the engine cannot decode it
     */
    public function prepare(string $path, string $mime): array
    {
        $bytes = (string) file_get_contents($path);
        $info = @getimagesizefromstring($bytes) ?: [0, 0];
        [$width, $height] = [(int) $info[0], (int) $info[1]];
        $format = self::FORMAT[$mime] ?? null;
        $animated = Metadata::animated($bytes, $mime);
        $orientation = $mime === 'image/jpeg' ? Metadata::jpegOrientation($bytes) : 1;
        $tooBig = $this->maxEdge > 0 && max($width, $height) > $this->maxEdge;
        $canEdit = $format !== null && $this->engine !== null && $this->engine->reads($mime);
        $meta = ['animated' => $animated, 'focus' => [0.5, 0.5], 'stripped' => false, 'oriented' => $orientation === 1];
        $out = null;

        if (!$animated && $canEdit && $this->engine->writes($format) && ($orientation !== 1 || $tooBig)) {
            self::reserveMemory($width, $height);
            $img = $this->engine->open($path);                 // upright
            if ($tooBig) {
                $scale = $this->maxEdge / max($img->width(), $img->height());
                $img->resize(max(1, (int) round($img->width() * $scale)), max(1, (int) round($img->height() * $scale)));
            }
            $out = $img->encode($format, self::quality($format));
            $img->destroy();
            $meta['stripped'] = $meta['oriented'] = true;
            $meta['resized'] = $tooBig;
        } elseif ($orientation === 1) {
            $out = match ($mime) {
                'image/jpeg' => Metadata::stripJpeg($bytes),
                'image/png' => Metadata::stripPng($bytes),
                'image/webp' => Metadata::stripWebp($bytes),
                default => null,
            };
            $meta['stripped'] = $out !== null;
            if ($out === $bytes) {
                $out = null;
            }
        }
        if ($out !== null) {
            file_put_contents($path, $out);
            $bytes = $out;
            $info = @getimagesizefromstring($bytes) ?: [$width, $height];
            [$width, $height] = [(int) $info[0], (int) $info[1]];
        }

        if ($canEdit) {
            try {
                self::reserveMemory($width, $height);
                $img = $this->engine->open($path);
                $meta['alpha'] = $img->hasAlpha();
                $meta['color'] = $img->averageColor();
                $w = 16;
                $h = max(1, min(32, (int) round($img->height() * $w / max(1, $img->width()))));
                $img->resize($w, $h);
                $lqFormat = $this->engine->writes('webp') ? 'webp' : 'jpeg';
                $meta['placeholder'] = 'data:image/' . $lqFormat . ';base64,' . base64_encode($img->encode($lqFormat, 40));
                $img->destroy();
                $meta['engine'] = $this->engine->name();
            } catch (ImageException) {
                // stats are a nicety; the upload itself is fine
            }
        }

        return ['width' => $width, 'height' => $height, 'changed' => $out !== null, 'meta' => $meta];
    }

    /** Decoding needs ~5 bytes a pixel; raise the memory limit for it, up to 1 GB. */
    public static function reserveMemory(int $width, int $height): void
    {
        $need = $width * $height * 5 + memory_get_usage() + 64 * 1_048_576;
        $limit = self::bytes((string) ini_get('memory_limit'));
        if ($limit > 0 && $need > $limit) {
            @ini_set('memory_limit', (string) min($need, 1_073_741_824));
        }
    }

    private static function bytes(string $v): int
    {
        $v = trim($v);
        if ($v === '' || $v === '-1') {
            return -1;
        }
        $n = (int) $v;

        return match (strtolower(substr($v, -1))) {
            'g' => $n << 30, 'm' => $n << 20, 'k' => $n << 10, default => $n,
        };
    }
}
