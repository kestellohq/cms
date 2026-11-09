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
    /**
     * What one decode may use. Uploads are untrusted: a small file can claim a
     * huge canvas or thousands of frames. Over a limit, Imagick throws and the
     * file gets no variants. Limits apply to the whole process, so none here
     * may stop other Imagick work: not a frame count, and not a time limit,
     * which some ImageMagick versions count from the process start (a queue
     * worker would lose Imagick after a minute). PHP's own time limits apply.
     *
     * A method, not a constant: the Imagick constants exist only where the
     * extension is loaded, and this class is loaded everywhere.
     *
     * @return array<int, int>
     */
    public static function limits(): array
    {
        return [
            Imagick::RESOURCETYPE_MEMORY => 256 * 1024 * 1024,
            Imagick::RESOURCETYPE_MAP => 512 * 1024 * 1024,
            Imagick::RESOURCETYPE_DISK => 1024 * 1024 * 1024,
            Imagick::RESOURCETYPE_AREA => 100_000_000,        // pixels in one image
            Imagick::RESOURCETYPE_WIDTH => 20_000,
            Imagick::RESOURCETYPE_HEIGHT => 20_000,
        ];
    }

    /** The path naming only the first frame (Imagick reads "file[0]" as frame 0 of file). */
    public static function firstFrame(string $path): string
    {
        return $path . '[0]';
    }

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
            foreach (self::limits() as $type => $limit) {
                $im->setResourceLimit($type, $limit);
            }
            // only the first frame is read: animations become their first frame in
            // variants, and a GIF of thousands of frames never fills memory or disk
            $im->readImage(self::firstFrame($path));
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
