<?php

declare(strict_types=1);

namespace Kestello\Cms\Blocks;

use Closure;
use Kestello\Framework\Log\Log;

/**
 * Block design: the styles an editor gives one block (colour, spacing, type,
 * border), per screen size. Stored on the node next to its props:
 *
 *   "design": {"base": {"background": "cream", "padding-top": "lg"}, "tablet": {...}, "phone": {...}}
 *
 * A value is a preset of the theme (a lowercase name) or a custom value
 * (#hex for colours, a length such as 24px or 1.5rem). This class checks
 * them; shared/block-design.js turns them into custom properties on the page
 * and shared/block-layout.css applies them. PROPS here and there must match
 * (BlockDesignTest compares them).
 *
 * Presets come from the theme (ThemeStyles, styles.json "presets") through
 * usePresets(); DEFAULTS are what a theme that declares none gets.
 */
final class BlockDesign
{
    public const array SCREENS = ['base', 'tablet', 'phone'];

    /** style => [kind, group]; see shared/block-design.js */
    public const array PROPS = [
        'text' => ['color', 'color'],
        'background' => ['color', 'color'],
        'padding-top' => ['space', 'spacing'],
        'padding-right' => ['space', 'spacing'],
        'padding-bottom' => ['space', 'spacing'],
        'padding-left' => ['space', 'spacing'],
        'margin-top' => ['space', 'spacing'],
        'margin-bottom' => ['space', 'spacing'],
        'font-size' => ['font', 'type'],
        'border-width' => ['width', 'border'],
        'border-style' => ['line', 'border'],
        'border-color' => ['color', 'border'],
        'radius' => ['radius', 'border'],
    ];

    public const array GROUPS = ['color', 'spacing', 'type', 'border'];
    public const array LINES = ['solid', 'dashed', 'dotted', 'double', 'none'];

    /** Preset kinds (the values a theme names) and what each is called in messages. */
    public const array PRESET_KINDS = ['color' => 'colours', 'space' => 'spacing sizes', 'font' => 'font sizes', 'radius' => 'corner sizes'];

    /**
     * What a theme without presets offers. Spacing and font sizes step up evenly;
     * colours are left to the theme (its colour tokens), with a neutral few here.
     *
     * @var array<string, array<string, array{label: string, value: string}>>
     */
    public const array DEFAULTS = [
        'color' => [
            'ink' => ['label' => 'Ink', 'value' => '#1b1b1a'],
            'paper' => ['label' => 'Paper', 'value' => '#ffffff'],
            'mist' => ['label' => 'Mist', 'value' => '#f1f0ec'],
        ],
        'space' => [
            'xs' => ['label' => 'XS', 'value' => '0.5rem'],
            'sm' => ['label' => 'S', 'value' => '1rem'],
            'md' => ['label' => 'M', 'value' => '1.5rem'],
            'lg' => ['label' => 'L', 'value' => '2.5rem'],
            'xl' => ['label' => 'XL', 'value' => '4rem'],
            '2xl' => ['label' => '2XL', 'value' => '6rem'],
        ],
        'font' => [
            'sm' => ['label' => 'S', 'value' => '0.875rem'],
            'md' => ['label' => 'M', 'value' => '1rem'],
            'lg' => ['label' => 'L', 'value' => '1.25rem'],
            'xl' => ['label' => 'XL', 'value' => '1.75rem'],
            '2xl' => ['label' => '2XL', 'value' => '2.5rem'],
            '3xl' => ['label' => '3XL', 'value' => '3.5rem'],
        ],
        'radius' => [
            'sm' => ['label' => 'S', 'value' => '4px'],
            'md' => ['label' => 'M', 'value' => '10px'],
            'lg' => ['label' => 'L', 'value' => '20px'],
            'full' => ['label' => 'Round', 'value' => '999px'],
        ],
    ];

    /** A preset's name: a word, which may start with one digit (2xl). A length (1rem) is read as a length first. */
    private const string PRESET = '/^[0-9]?[a-z][a-z0-9-]{0,30}$/';
    private const string HEX = '/^#(?:[0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i';
    private const string LENGTH = '/^(?:0|\d{1,4}(?:\.\d{1,3})?(?:px|rem|em|%|vw))$/';
    private const string PX = '/^(?:0|\d{1,2}(?:\.\d{1,2})?px)$/';

    private ?Closure $provider = null;
    /** @var array<string, array<string, array{label: string, value: string}>>|null */
    private ?array $presets = null;

    /** The theme's presets: fn(): array shaped like DEFAULTS (missing kinds fall back to DEFAULTS). */
    public function usePresets(Closure $provider): void
    {
        $this->provider = $provider;
        $this->presets = null;
    }

    /** @return array<string, array<string, array{label: string, value: string}>> */
    public function presets(): array
    {
        if ($this->presets !== null) {
            return $this->presets;
        }
        $theirs = [];
        if ($this->provider !== null) {
            try {
                $theirs = (array) ($this->provider)();
            } catch (\Throwable $e) {
                Log::error('block design presets could not be read: ' . $e->getMessage());
            }
        }
        $out = [];
        foreach (array_keys(self::PRESET_KINDS) as $kind) {
            $out[$kind] = is_array($theirs[$kind] ?? null) && $theirs[$kind] !== [] ? $theirs[$kind] : self::DEFAULTS[$kind];
        }

        return $this->presets = $out;
    }

    /** Forget the presets read (the theme or its styles changed). */
    public function forget(): void
    {
        $this->presets = null;
    }

    /** What the editor needs: the styles, which group each is in, the line styles and the presets. @return array<string, mixed> */
    public function describe(): array
    {
        $props = [];
        foreach (self::PROPS as $name => [$kind, $group]) {
            $props[$name] = ['kind' => $kind, 'group' => $group];
        }

        return ['screens' => self::SCREENS, 'groups' => self::GROUPS, 'props' => $props, 'lines' => self::LINES, 'presets' => $this->presets()];
    }

    /**
     * A node's design, cleaned. Saving ($strict) reports every problem; rendering drops
     * what isn't valid any more (a preset the theme no longer has) and keeps the rest,
     * so a theme change never hides a block.
     *
     * @param list<string> $groups what the block supports (BlockDefinition::supports)
     * @param list<string> $errors
     * @return array<string, array<string, string>>|null
     */
    public function clean(mixed $design, array $groups, string $label, array &$errors, bool $strict = true): ?array
    {
        if ($design === null || $design === []) {
            return null;
        }
        $problem = static function (string $message) use (&$errors, $label, $strict): void {
            if ($strict) {
                $errors[] = "{$label}: {$message}";
            }
        };
        if (!is_array($design) || array_is_list($design)) {
            $problem('design must be an object of screen sizes (base, tablet, phone).');
            return null;
        }
        $out = [];
        foreach ($design as $screen => $values) {
            if (!in_array($screen, self::SCREENS, true)) {
                $problem('design.' . mb_substr((string) $screen, 0, 20) . ' is not a screen size (base, tablet or phone).');
                continue;
            }
            if (!is_array($values) || ($values !== [] && array_is_list($values))) {
                $problem("design.{$screen} must be an object of styles.");
                continue;
            }
            foreach ($values as $prop => $value) {
                $where = "design.{$screen}." . mb_substr((string) $prop, 0, 30);
                $spec = self::PROPS[$prop] ?? null;
                if ($spec === null) {
                    $problem("{$where} is not a style a block can have.");
                    continue;
                }
                if (!in_array($spec[1], $groups, true)) {
                    $problem("{$where}: this block has no {$spec[1]} settings.");
                    continue;
                }
                $why = $this->check($spec[0], $value);
                if ($why !== null) {
                    $problem("{$where}: {$why}");
                    continue;
                }
                $out[$screen][$prop] = $value;
            }
        }

        return $out === [] ? null : $out;
    }

    /** Null when the value suits the kind, else why not. */
    private function check(string $kind, mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return 'must be text.';
        }
        if ($kind === 'line') {
            return in_array($value, self::LINES, true) ? null : 'is one of ' . implode(', ', self::LINES) . '.';
        }
        if ($kind === 'width') {
            return preg_match(self::PX, $value) === 1 ? null : 'is a width in px, at most 99px (such as 2px).';
        }
        if ($kind !== 'color' && preg_match(self::LENGTH, $value) === 1) {
            return null;
        }
        if (preg_match(self::PRESET, $value) === 1) {
            $known = $this->presets()[$kind] ?? [];

            return isset($known[$value]) ? null : "\"{$value}\" isn't one of the theme's " . self::PRESET_KINDS[$kind] . ' (' . implode(', ', array_keys($known)) . ').';
        }
        if ($kind === 'color') {
            return preg_match(self::HEX, $value) === 1 ? null : 'is one of the theme\'s colours or a colour such as #1e3a8a.';
        }

        return preg_match(self::LENGTH, $value) === 1 ? null : 'is one of the theme\'s sizes or a length such as 24px or 1.5rem.';
    }

    /**
     * The presets as custom properties, for the site's stylesheet (ThemeStyles):
     * --k-color-ink, --k-space-md, --k-font-lg, --k-radius-sm. Values come from the
     * theme's styles.json, checked there; a colour may name a token (var(--plate)).
     */
    public static function css(array $presets): string
    {
        $prefix = ['color' => 'k-color', 'space' => 'k-space', 'font' => 'k-font', 'radius' => 'k-radius'];
        $lines = [];
        foreach ($prefix as $kind => $p) {
            foreach ($presets[$kind] ?? [] as $key => $preset) {
                if (preg_match(self::PRESET, (string) $key) === 1 && is_string($preset['value'] ?? null) && self::safeValue($preset['value'])) {
                    $lines[] = "  --{$p}-{$key}: {$preset['value']};";
                }
            }
        }

        return $lines === [] ? '' : ":root {\n" . implode("\n", $lines) . "\n}\n";
    }

    /** A preset's value: a colour, a length, or a theme token (var(--name)); nothing that could end the rule. */
    public static function safeValue(string $value): bool
    {
        return preg_match(self::HEX, $value) === 1
            || preg_match(self::LENGTH, $value) === 1
            || preg_match('/^var\(--[a-z][a-z0-9-]{0,40}\)$/', $value) === 1
            || preg_match('/^clamp\(\s*[0-9.]+(?:px|rem|em|vw)\s*,\s*[0-9.]+(?:px|rem|em|vw)(?:\s*[+-]\s*[0-9.]+(?:px|rem|em|vw))?\s*,\s*[0-9.]+(?:px|rem|em|vw)\s*\)$/', $value) === 1;
    }
}
