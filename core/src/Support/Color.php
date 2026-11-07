<?php

declare(strict_types=1);

namespace Kestello\Support;

use InvalidArgumentException;

/**
 * Immutable sRGB colour with alpha. Parses hex, rgb(), hsl() and oklch();
 * mixes and adjusts lightness in OKLCH (perceptually even); measures WCAG
 * contrast; generates 50–950 palettes for the theme builder.
 *
 *   Color::parse('#6676d8')->contrast(Color::parse('#fff'))   // 4.13
 *   Color::parse('#6676d8')->palette()['700']->hex()
 *
 * @api
 */
final readonly class Color implements \Stringable
{
    /** Channels 0–1 (sRGB, gamma encoded), alpha 0–1. */
    private function __construct(public float $r, public float $g, public float $b, public float $a = 1.0) {}

    public static function rgb(int $r, int $g, int $b, float $a = 1.0): self
    {
        return new self(self::clamp($r / 255), self::clamp($g / 255), self::clamp($b / 255), self::clamp($a));
    }

    public static function parse(string $value): self
    {
        $v = strtolower(trim($value));
        if (preg_match('/^#?([0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/', $v, $m) === 1) {
            $hex = $m[1];
            if (strlen($hex) <= 4) {
                $hex = implode('', array_map(static fn($c) => $c . $c, str_split($hex)));
            }
            $a = strlen($hex) === 8 ? hexdec(substr($hex, 6, 2)) / 255 : 1.0;

            return new self(hexdec(substr($hex, 0, 2)) / 255, hexdec(substr($hex, 2, 2)) / 255, hexdec(substr($hex, 4, 2)) / 255, $a);
        }
        if (preg_match('/^(rgba?|hsla?|oklch)\((.*)\)$/', $v, $m) === 1) {
            $parts = preg_split('/[\s,\/]+/', trim($m[2]), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (count($parts) < 3 || count($parts) > 4) {
                throw new InvalidArgumentException("Invalid colour \"{$value}\".");
            }
            $alpha = isset($parts[3]) ? self::number($parts[3], 1) : 1.0;
            return match ($m[1][0] . ($m[1][1] ?? '')) {
                'rg' => new self(self::clamp(self::number($parts[0], 255)), self::clamp(self::number($parts[1], 255)), self::clamp(self::number($parts[2], 255)), self::clamp($alpha)),
                'hs' => self::fromHsl((float) $parts[0], self::number($parts[1], 100), self::number($parts[2], 100), $alpha),
                default => self::fromOklch(self::number($parts[0], 1), (float) $parts[1], (float) $parts[2], $alpha),
            };
        }
        $named = ['black' => '#000000', 'white' => '#ffffff', 'transparent' => '#00000000', 'red' => '#ff0000', 'green' => '#008000', 'blue' => '#0000ff'];
        if (isset($named[$v])) {
            return self::parse($named[$v]);
        }

        throw new InvalidArgumentException("Invalid colour \"{$value}\".");
    }

    public static function isValid(string $value): bool
    {
        try {
            self::parse($value);
            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /** @param float $h degrees, $s and $l 0–1 */
    public static function fromHsl(float $h, float $s, float $l, float $a = 1.0): self
    {
        $h = fmod(fmod($h, 360) + 360, 360) / 360;
        $s = self::clamp($s);
        $l = self::clamp($l);
        if ($s == 0.0) {
            return new self($l, $l, $l, self::clamp($a));
        }
        $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
        $p = 2 * $l - $q;
        $f = static function (float $t) use ($p, $q): float {
            $t = $t < 0 ? $t + 1 : ($t > 1 ? $t - 1 : $t);
            return match (true) {
                $t < 1 / 6 => $p + ($q - $p) * 6 * $t,
                $t < 1 / 2 => $q,
                $t < 2 / 3 => $p + ($q - $p) * (2 / 3 - $t) * 6,
                default => $p,
            };
        };

        return new self($f($h + 1 / 3), $f($h), $f($h - 1 / 3), self::clamp($a));
    }

    /** @param float $l 0–1, $c chroma (~0–0.4), $h degrees. Out-of-gamut colours are chroma-reduced. */
    public static function fromOklch(float $l, float $c, float $h, float $a = 1.0): self
    {
        $l = self::clamp($l);
        for ($i = 0; $i < 40; $i++) {
            [$r, $g, $b] = self::oklabToLinear($l, $c * cos(deg2rad($h)), $c * sin(deg2rad($h)));
            if (min($r, $g, $b) >= -0.0005 && max($r, $g, $b) <= 1.0005) {
                break;
            }
            $c *= 0.92;
        }

        return new self(self::clamp(self::encode($r)), self::clamp(self::encode($g)), self::clamp(self::encode($b)), self::clamp($a));
    }

    public function hex(bool $withAlpha = false): string
    {
        $hex = sprintf('#%02x%02x%02x', ...array_map(static fn(float $c) => (int) round($c * 255), [$this->r, $this->g, $this->b]));

        return $withAlpha || $this->a < 1.0 ? $hex . sprintf('%02x', (int) round($this->a * 255)) : $hex;
    }

    public function rgbString(): string
    {
        [$r, $g, $b] = array_map(static fn(float $c) => (int) round($c * 255), [$this->r, $this->g, $this->b]);

        return $this->a < 1.0 ? "rgb({$r} {$g} {$b} / " . round($this->a, 3) . ')' : "rgb({$r} {$g} {$b})";
    }

    /** @return array{h: float, s: float, l: float} h in degrees, s/l 0–1 */
    public function toHsl(): array
    {
        $max = max($this->r, $this->g, $this->b);
        $min = min($this->r, $this->g, $this->b);
        $l = ($max + $min) / 2;
        $d = $max - $min;
        if ($d < 1e-9) {
            return ['h' => 0.0, 's' => 0.0, 'l' => $l];
        }
        $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
        $h = match ($max) {
            $this->r => fmod(($this->g - $this->b) / $d + 6, 6),
            $this->g => ($this->b - $this->r) / $d + 2,
            default => ($this->r - $this->g) / $d + 4,
        };

        return ['h' => $h * 60, 's' => $s, 'l' => $l];
    }

    /** @return array{l: float, c: float, h: float} */
    public function toOklch(): array
    {
        [$L, $A, $B] = self::linearToOklab(self::decode($this->r), self::decode($this->g), self::decode($this->b));
        $c = sqrt($A * $A + $B * $B);
        $h = $c < 1e-6 ? 0.0 : fmod(rad2deg(atan2($B, $A)) + 360, 360);

        return ['l' => $L, 'c' => $c, 'h' => $h];
    }

    public function oklchString(): string
    {
        $o = $this->toOklch();

        return sprintf('oklch(%.4f %.4f %.2f%s)', $o['l'], $o['c'], $o['h'], $this->a < 1.0 ? ' / ' . round($this->a, 3) : '');
    }

    public function withAlpha(float $a): self
    {
        return new self($this->r, $this->g, $this->b, self::clamp($a));
    }

    /** Lighter by $amount of OKLCH lightness (0–1). */
    public function lighten(float $amount): self
    {
        $o = $this->toOklch();

        return self::fromOklch($o['l'] + $amount, $o['c'], $o['h'], $this->a);
    }

    public function darken(float $amount): self
    {
        return $this->lighten(-$amount);
    }

    /** Mix with another colour in OKLab ($weight 0 = this, 1 = other). */
    public function mix(self $other, float $weight = 0.5): self
    {
        $w = self::clamp($weight);
        $p = self::linearToOklab(self::decode($this->r), self::decode($this->g), self::decode($this->b));
        $q = self::linearToOklab(self::decode($other->r), self::decode($other->g), self::decode($other->b));
        [$r, $g, $b] = self::oklabToLinear($p[0] + ($q[0] - $p[0]) * $w, $p[1] + ($q[1] - $p[1]) * $w, $p[2] + ($q[2] - $p[2]) * $w);

        return new self(self::clamp(self::encode($r)), self::clamp(self::encode($g)), self::clamp(self::encode($b)), $this->a + ($other->a - $this->a) * $w);
    }

    /** WCAG relative luminance. */
    public function luminance(): float
    {
        return 0.2126 * self::decode($this->r) + 0.7152 * self::decode($this->g) + 0.0722 * self::decode($this->b);
    }

    /** WCAG contrast ratio, 1–21. */
    public function contrast(self $other): float
    {
        [$hi, $lo] = [max($this->luminance(), $other->luminance()), min($this->luminance(), $other->luminance())];

        return round(($hi + 0.05) / ($lo + 0.05), 2);
    }

    /** AA: 4.5 (3 for large text); AAA: 7 (4.5 for large text). */
    public function meetsWcag(self $background, string $level = 'AA', bool $largeText = false): bool
    {
        $need = match (strtoupper($level)) {
            'AAA' => $largeText ? 4.5 : 7.0,
            default => $largeText ? 3.0 : 4.5,
        };

        return $this->contrast($background) >= $need;
    }

    /** Black or white, whichever reads better on this colour. */
    public function readableText(): self
    {
        $black = self::rgb(17, 24, 39); // brand navy, softer than #000
        $white = self::rgb(255, 255, 255);

        return $black->contrast($this) >= $white->contrast($this) ? $black : $white;
    }

    /**
     * Tailwind-style scale from this colour: 50 (lightest) … 950 (darkest),
     * with this colour's hue and chroma at even OKLCH lightness steps.
     *
     * @return array<string, self>
     */
    public function palette(): array
    {
        $o = $this->toOklch();
        $steps = ['50' => 0.97, '100' => 0.93, '200' => 0.87, '300' => 0.78, '400' => 0.68, '500' => 0.58, '600' => 0.50, '700' => 0.42, '800' => 0.35, '900' => 0.28, '950' => 0.20];
        $out = [];
        foreach ($steps as $name => $l) {
            // Ease chroma off at the extremes so tints and shades stay in gamut and don't look neon.
            $c = $o['c'] * (1 - abs($l - 0.55) * 0.9);
            $out[$name] = self::fromOklch($l, max(0.0, $c), $o['h']);
        }

        return $out;
    }

    public function equals(self $other): bool
    {
        return $this->hex(true) === $other->hex(true);
    }

    public function __toString(): string
    {
        return $this->hex();
    }

    // ── conversions ──────────────────────────────────────────────

    private static function decode(float $c): float
    {
        return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    }

    private static function encode(float $c): float
    {
        $c = max(0.0, $c);

        return $c <= 0.0031308 ? 12.92 * $c : 1.055 * ($c ** (1 / 2.4)) - 0.055;
    }

    /** @return array{0: float, 1: float, 2: float} */
    private static function linearToOklab(float $r, float $g, float $b): array
    {
        $l = (0.4122214708 * $r + 0.5363325363 * $g + 0.0514459929 * $b) ** (1 / 3);
        $m = (0.2119034982 * $r + 0.6806995451 * $g + 0.1073969566 * $b) ** (1 / 3);
        $s = (0.0883024619 * $r + 0.2817188376 * $g + 0.6299787005 * $b) ** (1 / 3);

        return [
            0.2104542553 * $l + 0.7936177850 * $m - 0.0040720468 * $s,
            1.9779984951 * $l - 2.4285922050 * $m + 0.4505937099 * $s,
            0.0259040371 * $l + 0.7827717662 * $m - 0.8086757660 * $s,
        ];
    }

    /** @return array{0: float, 1: float, 2: float} */
    private static function oklabToLinear(float $L, float $A, float $B): array
    {
        $l = ($L + 0.3963377774 * $A + 0.2158037573 * $B) ** 3;
        $m = ($L - 0.1055613458 * $A - 0.0638541728 * $B) ** 3;
        $s = ($L - 0.0894841775 * $A - 1.2914855480 * $B) ** 3;

        return [
            4.0767416621 * $l - 3.3077115913 * $m + 0.2309699292 * $s,
            -1.2684380046 * $l + 2.6097574011 * $m - 0.3413193965 * $s,
            -0.0041960863 * $l - 0.7034186147 * $m + 1.7076147010 * $s,
        ];
    }

    private static function number(string $part, float $scale): float
    {
        return str_ends_with($part, '%') ? (float) rtrim($part, '%') / 100 : (float) $part / $scale;
    }

    private static function clamp(float $v): float
    {
        return max(0.0, min(1.0, $v));
    }
}
