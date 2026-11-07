<?php

declare(strict_types=1);

namespace Kestello\Contracts\Media;

/**
 * A decoded image. Operations change it in place and return it. Encoding
 * never writes metadata (EXIF, GPS, XMP, comments).
 *
 * @api
 */
interface EditableImage
{
    public function width(): int;

    public function height(): int;

    public function hasAlpha(): bool;

    /** Cut out a rectangle (pixels from the top left). */
    public function crop(int $x, int $y, int $width, int $height): static;

    /** Scale to exactly these dimensions. */
    public function resize(int $width, int $height): static;

    /** Average colour, "#rrggbb". */
    public function averageColor(): string;

    /**
     * Encoded bytes.
     *
     * @param string $format  jpeg, png, gif, webp or avif
     * @param int    $quality 1–100 (ignored by lossless formats)
     * @throws ImageException
     */
    public function encode(string $format, int $quality): string;

    /** Free the memory now (also done on destruction). */
    public function destroy(): void;
}
