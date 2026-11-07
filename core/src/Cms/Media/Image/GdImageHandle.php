<?php

declare(strict_types=1);

namespace Kestello\Cms\Media\Image;

use GdImage;
use Kestello\Contracts\Media\ImageException;
use Kestello\Contracts\Media\TransformableImage;

/** @internal */
final class GdImageHandle implements TransformableImage
{
    private ?GdImage $img;

    public function __construct(GdImage $img)
    {
        if (!imageistruecolor($img)) {
            imagepalettetotruecolor($img);
        }
        imagealphablending($img, false);
        imagesavealpha($img, true);
        $this->img = $img;
    }

    private function gd(): GdImage
    {
        return $this->img ?? throw new ImageException('The image was already freed.');
    }

    public function width(): int
    {
        return imagesx($this->gd());
    }
    public function height(): int
    {
        return imagesy($this->gd());
    }

    public function hasAlpha(): bool
    {
        $img = $this->gd();
        $w = imagesx($img);
        $h = imagesy($img);
        $step = max(1, (int) floor(min($w, $h) / 32));
        for ($y = 0; $y < $h; $y += $step) {
            for ($x = 0; $x < $w; $x += $step) {
                if (((imagecolorat($img, $x, $y) >> 24) & 0x7F) > 0) {
                    return true;
                }
            }
        }

        return false;
    }

    public function crop(int $x, int $y, int $width, int $height): static
    {
        $out = imagecrop($this->gd(), ['x' => $x, 'y' => $y, 'width' => max(1, $width), 'height' => max(1, $height)]);
        if (!$out instanceof GdImage) {
            throw new ImageException('Cropping failed.');
        }

        return $this->replace($out);
    }

    public function resize(int $width, int $height): static
    {
        $out = imagecreatetruecolor(max(1, $width), max(1, $height));
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
        imagecopyresampled($out, $this->gd(), 0, 0, 0, 0, max(1, $width), max(1, $height), $this->width(), $this->height());

        return $this->replace($out);
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
        $img = $this->gd();
        // GD turns counter-clockwise; the corners it uncovers stay transparent.
        $out = imagerotate($img, 360 - $degrees, imagecolorallocatealpha($img, 0, 0, 0, 127));
        if (!$out instanceof GdImage) {
            throw new ImageException('Turning the image failed.');
        }

        return $this->replace($out);
    }

    public function flip(bool $horizontal): static
    {
        if (!imageflip($this->gd(), $horizontal ? IMG_FLIP_HORIZONTAL : IMG_FLIP_VERTICAL)) {
            throw new ImageException('Flipping the image failed.');
        }

        return $this;
    }

    public function averageColor(): string
    {
        $one = imagecreatetruecolor(1, 1);
        imagecopyresampled($one, $this->gd(), 0, 0, 0, 0, 1, 1, $this->width(), $this->height());
        $rgb = imagecolorat($one, 0, 0);

        return sprintf('#%02x%02x%02x', ($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF);
    }

    public function encode(string $format, int $quality): string
    {
        $img = $this->gd();
        $quality = max(1, min(100, $quality));
        if ($format === 'jpeg' || $format === 'gif') {
            // No alpha in JPEG (and GIF's is 1-bit): flatten onto white.
            $flat = imagecreatetruecolor(imagesx($img), imagesy($img));
            imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
            imagealphablending($flat, true);
            imagecopy($flat, $img, 0, 0, 0, 0, imagesx($img), imagesy($img));
            $img = $flat;
        }
        ob_start();
        $ok = match ($format) {
            'jpeg' => imageinterlace($img, true) !== null && imagejpeg($img, null, $quality),
            'png' => imagepng($img, null, 9),
            'gif' => imagegif($img),
            'webp' => function_exists('imagewebp') && imagewebp($img, null, $quality),
            'avif' => function_exists('imageavif') && imageavif($img, null, $quality, 6),
            default => false,
        };
        $bytes = (string) ob_get_clean();
        if (!$ok || $bytes === '') {
            throw new ImageException("GD could not encode {$format}.");
        }

        return $bytes;
    }

    /** Let go of the pixels now rather than when the handle goes away (GD images are freed with their last reference). */
    public function destroy(): void
    {
        $this->img = null;
    }

    public function __destruct()
    {
        $this->destroy();
    }

    private function replace(GdImage $out): static
    {
        imagealphablending($out, false);
        imagesavealpha($out, true);
        $this->img = $out;

        return $this;
    }
}
