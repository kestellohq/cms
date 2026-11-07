<?php

declare(strict_types=1);

namespace Kestello\Cms\Media\Image;

use Imagick;
use ImagickPixel;
use Kestello\Contracts\Media\ImageException;
use Kestello\Contracts\Media\TransformableImage;

/** @internal */
final class ImagickImageHandle implements TransformableImage
{
    public function __construct(private ?Imagick $im) {}

    private function im(): Imagick
    {
        return $this->im ?? throw new ImageException('The image was already freed.');
    }

    public function width(): int
    {
        return $this->im()->getImageWidth();
    }
    public function height(): int
    {
        return $this->im()->getImageHeight();
    }
    public function hasAlpha(): bool
    {
        return (bool) $this->im()->getImageAlphaChannel();
    }

    public function crop(int $x, int $y, int $width, int $height): static
    {
        $this->im()->cropImage(max(1, $width), max(1, $height), $x, $y);
        $this->im()->setImagePage(0, 0, 0, 0);

        return $this;
    }

    public function resize(int $width, int $height): static
    {
        $this->im()->resizeImage(max(1, $width), max(1, $height), Imagick::FILTER_LANCZOS, 1);

        return $this;
    }

    public function rotate(int $degrees): static
    {
        $degrees = (($degrees % 360) + 360) % 360;
        if ($degrees === 0) {
            return $this;
        }
        if ($degrees % 90 !== 0) {
            throw new ImageException('Images turn in quarter turns (90, 180 or 270 degrees).');
        }
        $this->im()->rotateImage(new ImagickPixel('transparent'), $degrees);   // clockwise
        $this->im()->setImagePage(0, 0, 0, 0);

        return $this;
    }

    public function flip(bool $horizontal): static
    {
        $horizontal ? $this->im()->flopImage() : $this->im()->flipImage();

        return $this;
    }

    public function averageColor(): string
    {
        $c = clone $this->im();
        $c->resizeImage(1, 1, Imagick::FILTER_BOX, 1);
        $p = $c->getImagePixelColor(0, 0)->getColor();
        $c->clear();

        return sprintf('#%02x%02x%02x', $p['r'], $p['g'], $p['b']);
    }

    public function encode(string $format, int $quality): string
    {
        try {
            $c = clone $this->im();
            if ($format === 'jpeg') {
                $c->setImageBackgroundColor(new ImagickPixel('white'));
                $c = $c->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
                $c->setInterlaceScheme(Imagick::INTERLACE_PLANE);
            }
            $icc = in_array('icc', array_map('strtolower', array_keys($c->getImageProfiles('icc', false))), true) ? $c->getImageProfile('icc') : null;
            $c->stripImage();      // EXIF, GPS, XMP, comments and profiles...
            if ($icc !== null && $icc !== '') {
                $c->profileImage('icc', $icc);   // ...but the colour profile stays (Display P3 photos)
            }
            $c->setImageFormat(['jpeg' => 'JPEG', 'png' => 'PNG', 'gif' => 'GIF', 'webp' => 'WEBP', 'avif' => 'AVIF'][$format] ?? throw new ImageException("Unknown format {$format}."));
            $c->setImageCompressionQuality(max(1, min(100, $quality)));
            if ($format === 'png') {
                $c->setOption('png:compression-level', '9');
            }
            $bytes = $c->getImageBlob();
            $c->clear();
        } catch (\ImagickException $e) {
            throw new ImageException("Imagick could not encode {$format}: " . $e->getMessage(), 0, $e);
        }

        return $bytes;
    }

    public function destroy(): void
    {
        $this->im?->clear();
        $this->im = null;
    }

    public function __destruct()
    {
        $this->destroy();
    }
}
