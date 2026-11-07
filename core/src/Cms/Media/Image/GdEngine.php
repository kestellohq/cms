<?php

declare(strict_types=1);

namespace Kestello\Cms\Media\Image;

use GdImage;
use Kestello\Contracts\Media\EditableImage;
use Kestello\Contracts\Media\ImageEngine;
use Kestello\Contracts\Media\ImageException;

/** GD: available almost everywhere. WebP and AVIF when PHP was built with them. */
final class GdEngine implements ImageEngine
{
    public function name(): string
    {
        return 'gd';
    }

    public function available(): bool
    {
        return extension_loaded('gd');
    }

    public function reads(string $mime): bool
    {
        $info = $this->available() ? gd_info() : [];

        return match ($mime) {
            'image/jpeg' => !empty($info['JPEG Support']),
            'image/png' => !empty($info['PNG Support']),
            'image/gif' => !empty($info['GIF Read Support']),
            'image/webp' => !empty($info['WebP Support']),
            'image/avif' => !empty($info['AVIF Support']) && function_exists('imagecreatefromavif'),
            default => false,
        };
    }

    public function writes(string $format): bool
    {
        return match ($format) {
            'jpeg' => function_exists('imagejpeg'),
            'png' => function_exists('imagepng'),
            'gif' => function_exists('imagegif'),
            'webp' => function_exists('imagewebp') && !empty(gd_info()['WebP Support']),
            'avif' => function_exists('imageavif') && !empty(gd_info()['AVIF Support']),
            default => false,
        };
    }

    public function open(string $path): EditableImage
    {
        $bytes = @file_get_contents($path);
        $image = $bytes === false ? false : @imagecreatefromstring($bytes);
        if (!$image instanceof GdImage) {
            throw new ImageException('GD could not decode this image.');
        }
        $orientation = Metadata::jpegOrientation($bytes);
        $image = self::orient($image, $orientation);

        return new GdImageHandle($image);
    }

    private static function orient(GdImage $img, int $o): GdImage
    {
        if ($o === 2 || $o === 4 || $o === 5 || $o === 7) {
            imageflip($img, IMG_FLIP_HORIZONTAL);
        }
        $angle = match ($o) {
            3, 4 => 180,
            5, 6 => 270,      // imagerotate turns counter-clockwise
            7, 8 => 90,
            default => 0,
        };
        if ($angle !== 0) {
            $rotated = imagerotate($img, $angle, 0);
            if ($rotated instanceof GdImage) {
                $img = $rotated;          // the old image is freed with its last reference
            }
        }

        return $img;
    }
}
