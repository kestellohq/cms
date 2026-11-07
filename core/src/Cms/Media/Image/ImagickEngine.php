<?php

declare(strict_types=1);

namespace Kestello\Cms\Media\Image;

use Imagick;
use Kestello\Contracts\Media\EditableImage;
use Kestello\Contracts\Media\ImageEngine;
use Kestello\Contracts\Media\ImageException;

/** Imagick: better resampling, colour profiles, CMYK. Preferred when loaded. */
final class ImagickEngine implements ImageEngine
{
    private const array FORMATS = ['jpeg' => 'JPEG', 'png' => 'PNG', 'gif' => 'GIF', 'webp' => 'WEBP', 'avif' => 'AVIF'];

    public function name(): string
    {
        return 'imagick';
    }

    public function available(): bool
    {
        return extension_loaded('imagick') && class_exists(Imagick::class);
    }

    public function reads(string $mime): bool
    {
        $format = ['image/jpeg' => 'JPEG', 'image/png' => 'PNG', 'image/gif' => 'GIF', 'image/webp' => 'WEBP', 'image/avif' => 'AVIF'][$mime] ?? null;

        return $format !== null && $this->available() && in_array($format, Imagick::queryFormats($format), true);
    }

    public function writes(string $format): bool
    {
        return isset(self::FORMATS[$format]) && $this->available() && in_array(self::FORMATS[$format], Imagick::queryFormats(self::FORMATS[$format]), true);
    }

    public function open(string $path): EditableImage
    {
        try {
            $im = new Imagick();
            $im->setResourceLimit(Imagick::RESOURCETYPE_MEMORY, 256 * 1024 * 1024);
            $im->readImage($path);
            if ($im->getNumberImages() > 1) {
                $im = $im->coalesceImages();
                $im->setIteratorIndex(0);
                $first = $im->getImage();
                $im->clear();
                $im = $first;          // animations become their first frame in variants
            }
            $im->autoOrient();
            if ($im->getImageColorspace() === Imagick::COLORSPACE_CMYK) {
                $im->transformImageColorspace(Imagick::COLORSPACE_SRGB);
            }
        } catch (\ImagickException $e) {
            throw new ImageException('Imagick could not decode this image: ' . $e->getMessage(), 0, $e);
        }

        return new ImagickImageHandle($im);
    }
}
