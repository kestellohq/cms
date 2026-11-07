<?php

declare(strict_types=1);

namespace Kestello\Cms\Media;

use InvalidArgumentException;

/**
 * A named image size: a box, a fit, and the responsive widths made for it.
 *
 *   contain  the image fits inside width × height (height null: width only)
 *   cover    fills width × height exactly, cropped around the focal point
 *
 * Widths never exceed what the original allows: images are never upscaled.
 *
 * @api
 */
final class MediaSize
{
    /** Responsive steps; a size uses those from a quarter of its width up. */
    public const array STEPS = [320, 640, 960, 1280, 1920, 2560];

    /** @param list<int>|null $widths explicit responsive widths */
    public function __construct(
        public readonly string $name,
        public readonly int $width,
        public readonly ?int $height = null,
        public readonly string $fit = 'contain',
        private readonly ?array $widths = null,
        public readonly bool $public = true,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,39}$/', $name) !== 1) {
            throw new InvalidArgumentException("Size names are lowercase letters, digits and underscores: \"{$name}\".");
        }
        if ($width < 16 || $width > 4096 || ($height !== null && ($height < 16 || $height > 4096))) {
            throw new InvalidArgumentException("Size \"{$name}\": width and height are 16–4096 pixels.");
        }
        if (!in_array($fit, ['contain', 'cover'], true)) {
            throw new InvalidArgumentException("Size \"{$name}\": fit is \"contain\" or \"cover\".");
        }
        if ($fit === 'cover' && $height === null) {
            throw new InvalidArgumentException("Size \"{$name}\": cover needs a height.");
        }
        foreach ($widths ?? [] as $w) {
            if (!is_int($w) || $w < 16 || $w > $width) {
                throw new InvalidArgumentException("Size \"{$name}\": widths must be 16–{$width}.");
            }
        }
    }

    /** What the variants depend on (part of their file names). @return array<string, mixed> */
    public function spec(): array
    {
        return ['w' => $this->width, 'h' => $this->height, 'fit' => $this->fit, 'widths' => $this->widths];
    }

    /**
     * The largest width this size can have for an original of $w × $h.
     */
    public function maxWidth(int $w, int $h): int
    {
        if ($this->fit === 'cover') {
            [, , $cw] = $this->cropBox($w, $h, [0.5, 0.5]);

            return max(1, min($this->width, $cw));
        }
        $max = min($this->width, $w);
        if ($this->height !== null) {
            $max = min($max, (int) floor($w * $this->height / $h));
        }

        return max(1, $max);
    }

    /** @return list<int> responsive widths for an original of $w × $h, ascending */
    public function widthsFor(int $w, int $h): array
    {
        $max = $this->maxWidth($w, $h);
        $list = $this->widths ?? array_values(array_filter(self::STEPS, fn(int $s) => $s >= $this->width / 4));
        $list = array_values(array_filter($list, static fn(int $s) => $s <= $max));
        if (!in_array($max, $list, true)) {
            // the largest the original allows, unless a step is within 10% of it
            $last = $list === [] ? 0 : max($list);
            if ($last < $max * 0.9) {
                $list[] = $max;
            }
        }
        sort($list);

        return $list;
    }

    /**
     * Output size, and the crop (x, y, w, h) to take from the original first.
     *
     * @param array{0: float, 1: float} $focus
     * @return array{width: int, height: int, crop: array{0: int, 1: int, 2: int, 3: int}|null}
     */
    public function plan(int $target, int $w, int $h, array $focus): array
    {
        if ($this->fit === 'cover') {
            $crop = $this->cropBox($w, $h, $focus);
            $ratio = $this->width / $this->height;

            return ['width' => $target, 'height' => max(1, (int) round($target / $ratio)), 'crop' => $crop];
        }

        return ['width' => $target, 'height' => max(1, (int) round($h * $target / $w)), 'crop' => null];
    }

    /** @return array{0: int, 1: int, 2: int, 3: int} x, y, width, height */
    private function cropBox(int $w, int $h, array $focus): array
    {
        $ratio = $this->width / (int) $this->height;
        if ($w / $h > $ratio) {
            $ch = $h;
            $cw = max(1, (int) round($h * $ratio));
        } else {
            $cw = $w;
            $ch = max(1, (int) round($w / $ratio));
        }
        $x = (int) round(max(0, min($w - $cw, $focus[0] * $w - $cw / 2)));
        $y = (int) round(max(0, min($h - $ch, $focus[1] * $h - $ch / 2)));

        return [$x, $y, $cw, $ch];
    }
}
