<?php

declare(strict_types=1);

namespace Kestello\Contracts\Media;

/**
 * Decodes, transforms and encodes raster images. Core ships GD and
 * Imagick; plugins can add others ($cms->media->engine()).
 *
 * @api
 */
interface ImageEngine
{
    public function name(): string;

    /** Whether the engine is usable here (its PHP extension is loaded). */
    public function available(): bool;

    /** Whether it can decode this MIME type (image/jpeg...). */
    public function reads(string $mime): bool;

    /** Whether it can encode this format: jpeg, png, gif, webp, avif. */
    public function writes(string $format): bool;

    /**
     * Decode a file, already turned upright when it carries an EXIF
     * orientation.
     *
     * @throws ImageException when it can't be decoded
     */
    public function open(string $path): EditableImage;
}
