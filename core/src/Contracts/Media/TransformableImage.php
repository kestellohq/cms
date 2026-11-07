<?php

declare(strict_types=1);

namespace Kestello\Contracts\Media;

/**
 * An image that can also turn and mirror, for editing in the media
 * library. Optional: an engine's images that don't implement it can still
 * be cropped, but not turned or flipped.
 *
 * @api
 */
interface TransformableImage extends EditableImage
{
    /**
     * Turn clockwise by a multiple of 90 degrees.
     *
     * @param 0|90|180|270 $degrees
     */
    public function rotate(int $degrees): static;

    /** Mirror left to right ($horizontal), or top to bottom. */
    public function flip(bool $horizontal): static;
}
