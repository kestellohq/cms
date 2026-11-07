<?php

declare(strict_types=1);

namespace Kestello\Cms\Media\Image;

use Kestello\Cms\Media\MediaException;

/**
 * One edit of an image, applied to the original in this order:
 *
 *   1. turn clockwise by a quarter, half or three quarters
 *   2. mirror left to right, then top to bottom
 *   3. crop: x, y, width and height as fractions (0 to 1) of the turned
 *      and mirrored picture, so the same edit fits any resolution
 *
 * mapPoint() moves a point (the focal point) from the original into the
 * edited picture, and unmapPoint() back. Pure: no files, no engine.
 *
 * @internal MediaEditor, the media library's image editor
 */
final readonly class ImageEdit
{
    private const float EPSILON = 1e-6;

    /**
     * @param 0|90|180|270                                             $rotate
     * @param array{x: float, y: float, width: float, height: float}|null $crop
     */
    private function __construct(
        public int $rotate,
        public bool $flipHorizontal,
        public bool $flipVertical,
        public ?array $crop,
    ) {}

    /**
     * @param array<string, mixed> $input rotate, flip_horizontal, flip_vertical, crop {x, y, width, height}
     * @throws MediaException (invalid) naming the field that is wrong
     */
    public static function fromInput(array $input): self
    {
        $errors = [];
        $rotate = $input['rotate'] ?? 0;
        $whole = is_int($rotate) || (is_float($rotate) && floor($rotate) === $rotate);
        if (!$whole || (int) $rotate % 90 !== 0) {
            $errors['rotate'] = ['Turn by 90, 180 or 270 degrees.'];
            $rotate = 0;
        }
        $flips = [];
        foreach (['flip_horizontal', 'flip_vertical'] as $key) {
            $flips[$key] = $input[$key] ?? false;
            if (!is_bool($flips[$key])) {
                $errors[$key] = ['Flipping is on (true) or off (false).'];
            }
        }
        $crop = self::crop($input['crop'] ?? null);
        if ($crop === false) {
            $errors['crop'] = ['The crop is x, y, width and height from 0 to 1, and must fit inside the picture.'];
        }
        if ($errors !== []) {
            throw MediaException::invalid($errors, 'The edit was refused.');
        }

        $quarter = match (((int) $rotate % 360 + 360) % 360) {
            90 => 90, 180 => 180, 270 => 270, default => 0,
        };

        return new self($quarter, $flips['flip_horizontal'] === true, $flips['flip_vertical'] === true, $crop ?: null);
    }

    /**
     * A full crop is no crop.
     *
     * @return array{x: float, y: float, width: float, height: float}|false|null false when invalid
     */
    private static function crop(mixed $c): array|false|null
    {
        if ($c === null) {
            return null;
        }
        if (!is_array($c)) {
            return false;
        }
        $v = [];
        foreach (['x', 'y', 'width', 'height'] as $key) {
            if (!is_int($c[$key] ?? null) && !is_float($c[$key] ?? null)) {
                return false;
            }
            $v[$key] = round((float) $c[$key], 6);
            if ($v[$key] < 0 || $v[$key] > 1) {
                return false;
            }
        }
        if ($v['width'] <= 0 || $v['height'] <= 0 || $v['x'] + $v['width'] > 1 + self::EPSILON || $v['y'] + $v['height'] > 1 + self::EPSILON) {
            return false;
        }
        $v['width'] = min($v['width'], 1 - $v['x']);
        $v['height'] = min($v['height'], 1 - $v['y']);
        if ($v['x'] < self::EPSILON && $v['y'] < self::EPSILON && $v['width'] > 1 - self::EPSILON && $v['height'] > 1 - self::EPSILON) {
            return null;
        }

        return $v;
    }

    /** Whether the edit leaves the picture as it is. */
    public function isIdentity(): bool
    {
        return $this->rotate === 0 && !$this->flipHorizontal && !$this->flipVertical && $this->crop === null;
    }

    /** Whether it turns or mirrors (which needs a TransformableImage). */
    public function transforms(): bool
    {
        return $this->rotate !== 0 || $this->flipHorizontal || $this->flipVertical;
    }

    /** @return array{rotate: 0|90|180|270, flip_horizontal: bool, flip_vertical: bool, crop: array{x: float, y: float, width: float, height: float}|null} */
    public function toArray(): array
    {
        return ['rotate' => $this->rotate, 'flip_horizontal' => $this->flipHorizontal, 'flip_vertical' => $this->flipVertical, 'crop' => $this->crop];
    }

    /**
     * Size of the original once turned (before cropping).
     *
     * @return array{0: int, 1: int}
     */
    public function orientedSize(int $width, int $height): array
    {
        return $this->rotate % 180 === 0 ? [$width, $height] : [$height, $width];
    }

    /**
     * The crop in pixels of a turned picture this size: x, y, width, height.
     *
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    public function cropBox(int $width, int $height): array
    {
        $c = $this->crop ?? ['x' => 0.0, 'y' => 0.0, 'width' => 1.0, 'height' => 1.0];
        $x = min($width - 1, (int) round($c['x'] * $width));
        $y = min($height - 1, (int) round($c['y'] * $height));

        return [$x, $y, max(1, min($width - $x, (int) round($c['width'] * $width))), max(1, min($height - $y, (int) round($c['height'] * $height)))];
    }

    /**
     * A point of the original (fractions from the top left) in the edited
     * picture. Points the crop leaves out move to its nearest edge.
     *
     * @param array{0: float, 1: float} $p
     * @return array{0: float, 1: float}
     */
    public function mapPoint(array $p): array
    {
        [$x, $y] = [(float) $p[0], (float) $p[1]];
        [$x, $y] = match ($this->rotate) {
            90 => [1 - $y, $x],
            180 => [1 - $x, 1 - $y],
            270 => [$y, 1 - $x],
            default => [$x, $y],
        };
        $x = $this->flipHorizontal ? 1 - $x : $x;
        $y = $this->flipVertical ? 1 - $y : $y;
        if ($this->crop !== null) {
            $x = ($x - $this->crop['x']) / $this->crop['width'];
            $y = ($y - $this->crop['y']) / $this->crop['height'];
        }

        return [max(0.0, min(1.0, $x)), max(0.0, min(1.0, $y))];
    }

    /**
     * A point of the edited picture in the original.
     *
     * @param array{0: float, 1: float} $p
     * @return array{0: float, 1: float}
     */
    public function unmapPoint(array $p): array
    {
        [$x, $y] = [(float) $p[0], (float) $p[1]];
        if ($this->crop !== null) {
            $x = $this->crop['x'] + $x * $this->crop['width'];
            $y = $this->crop['y'] + $y * $this->crop['height'];
        }
        $x = $this->flipHorizontal ? 1 - $x : $x;
        $y = $this->flipVertical ? 1 - $y : $y;
        [$x, $y] = match ($this->rotate) {
            90 => [$y, 1 - $x],
            180 => [1 - $x, 1 - $y],
            270 => [1 - $y, $x],
            default => [$x, $y],
        };

        return [max(0.0, min(1.0, $x)), max(0.0, min(1.0, $y))];
    }
}
