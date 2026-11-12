<?php

declare(strict_types=1);

namespace Kestello\Cms\Design;

use Kestello\Cms\Blocks\BlockDesign;
use Kestello\Cms\Cms;
use Kestello\Cms\Extension\Package;
use Kestello\Framework\Log\Log;
use Kestello\Support\Translator;

/**
 * Global styles: the design tokens a theme declares in styles.json, the
 * site's published choices (a style variation plus changed values, and
 * optional custom CSS), and the stylesheet they compile to.
 *
 * styles.json (a child theme's file adds to and overrides its parent's):
 *
 *   {
 *     "groups":   {"colors": "Colours", "type": "Type", "shape": "Shape"},
 *     "tokens": {
 *       "plate":  {"type": "color", "label": "Enamel", "group": "colors", "value": "#1e3a8a"},
 *       "radius": {"type": "size",  "label": "Corners", "group": "shape", "value": 18, "unit": "px", "min": 0, "max": 40},
 *       "font":   {"type": "font",  "label": "Text", "group": "type", "value": "archivo"}
 *     },
 *     "fonts":    {"archivo": {"label": "Archivo", "stack": "'Archivo', Arial, sans-serif"}},
 *     "contrast": [{"fg": "wall-ink", "bg": "wall", "label": "Text on the page"}],
 *     "variations": {"night": {"label": "Night", "values": {"plate": "#0f766e"}}},
 *     "email":    {"page": "wall", "card": "enamel-white", "ink": "wall-ink", "accent": "plate", "accent-ink": "#ffffff",
 *                  "font": "font", "dark": {"page": "#141414", "card": "#1f1f1f", "ink": "#f4f1ea"}},
 *     "presets":  {"color": {"plate": {"label": "Enamel", "value": "var(--plate)"}},
 *                  "space": {"sm": {"label": "S", "value": "1rem"}}, "font": {...}, "radius": {...}}
 *   }
 *
 * Presets are what editors pick from when they style a block (BlockDesign):
 * colours, spacing sizes, font sizes and corner sizes. A kind the theme leaves
 * out gets BlockDesign::DEFAULTS; with no colours, the theme's colour tokens
 * are the palette. They are written into the stylesheet as --k-color-*,
 * --k-space-*, --k-font-* and --k-radius-*.
 *
 * "email" says which colours emails use (MailBrand): each role (page, card,
 * ink, muted, line, accent, accent-ink, font) names a token or is a colour,
 * and "dark" does the same for mail apps in dark mode. Roles left out keep
 * Kestello's defaults.
 *
 * Every token becomes a custom property of the same name (--plate,
 * --radius, --font) in one stylesheet loaded after the theme's own, so a
 * theme written against custom properties restyles without a build.
 * Values are validated strictly by type; nothing typed in the admin
 * reaches CSS unchecked.
 */
final class ThemeStyles
{
    public const string KEY = 'global';
    public const string ROUTE = '/system/theme/styles.css';
    public const int MAX_CSS = 50_000;

    /** Font stacks every theme can offer (no downloads, no requests). */
    public const array SYSTEM_FONTS = [
        'system-sans' => ['label' => 'System sans-serif', 'stack' => "system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif"],
        'system-serif' => ['label' => 'System serif', 'stack' => "Charter, 'Bitstream Charter', 'Sitka Text', Cambria, Georgia, serif"],
        'humanist' => ['label' => 'Humanist sans-serif', 'stack' => "Seravek, 'Gill Sans Nova', Ubuntu, Calibri, 'DejaVu Sans', source-sans-pro, sans-serif"],
        'geometric' => ['label' => 'Geometric sans-serif', 'stack' => "Avenir, Montserrat, Corbel, 'URW Gothic', source-sans-pro, sans-serif"],
        'old-style' => ['label' => 'Old-style serif', 'stack' => "'Iowan Old Style', 'Palatino Linotype', 'URW Palladio L', P052, serif"],
        'mono' => ['label' => 'Monospace', 'stack' => "ui-monospace, 'Cascadia Code', 'Source Code Pro', Menlo, Consolas, 'DejaVu Sans Mono', monospace"],
    ];

    private const array UNITS = ['px', 'rem', 'em', '%', 'ch', 'vw'];

    /** The parts of an email a theme can colour, and its font. */
    public const array EMAIL_ROLES = ['page', 'card', 'ink', 'muted', 'line', 'accent', 'accent-ink', 'font'];

    /** @var array<string, array<string, mixed>> per theme slug */
    private array $definitions = [];
    private ?string $cssMemo = null;

    public function __construct(private readonly Cms $cms, private readonly ThemeDocuments $documents, private readonly ?ThemeFonts $fonts = null) {}

    public function theme(): ?Package
    {
        return $this->cms->extensions()->activeTheme();
    }

    // ── what the theme offers ───────────────────────────────────────────

    /**
     * The theme chain's tokens, groups, fonts, contrast pairs and variations.
     *
     * @return array{groups: array<string, string>, tokens: array<string, array<string, mixed>>, fonts: array<string, array{label: string, stack: string}>, contrast: list<array{fg: string, bg: string, label: string}>, variations: array<string, array{label: string, values: array<string, mixed>}>, theme_css: string, presets: array<string, array<string, array{label: string, value: string}>>, email: array{light: array<string, string>, dark: array<string, string>}}
     */
    public function definition(): array
    {
        $theme = $this->theme();
        $slug = $theme?->slug() ?? '';
        if (isset($this->definitions[$slug])) {
            return $this->definitions[$slug];
        }
        $def = ['groups' => [], 'tokens' => [], 'fonts' => self::SYSTEM_FONTS, 'contrast' => [], 'variations' => [], 'theme_css' => '', 'presets' => [], 'email' => ['light' => [], 'dark' => []]];
        // fonts downloaded to this site can be chosen for any font token
        foreach ($this->fonts?->all() ?? [] as $slug => $font) {
            $def['fonts'][$slug] = ['label' => $font['label'], 'stack' => $font['stack'], 'hosted' => true];
        }
        foreach (array_reverse($this->cms->extensions()->themeChain()) as $package) {
            $file = $package->path('styles.json');
            if (!is_file($file)) {
                continue;
            }
            $raw = json_decode((string) file_get_contents($file), true);
            if (!is_array($raw)) {
                Log::warning("{$package->slug()}/styles.json is not valid JSON; its tokens are ignored.");
                continue;
            }
            self::merge($def, $raw, $package->slug());
        }
        // contrast pairs and variations may only name real tokens
        $def['contrast'] = array_values(array_filter($def['contrast'], static fn(array $p) => ($def['tokens'][$p['fg']]['type'] ?? '') === 'color' && ($def['tokens'][$p['bg']]['type'] ?? '') === 'color'));
        foreach ($def['variations'] as $name => $v) {
            [$values] = $this->clean($v['values'], $def);
            $def['variations'][$name]['values'] = $values;
        }

        return $this->definitions[$slug] = $def;
    }

    /** @param array<string, mixed> $def */
    private static function merge(array &$def, array $raw, string $owner): void
    {
        foreach ((array) ($raw['groups'] ?? []) as $k => $label) {
            if (is_string($k) && is_string($label) && preg_match('/^[a-z][a-z0-9-]{0,30}$/', $k) === 1) {
                $def['groups'][$k] = mb_substr($label, 0, 60);
            }
        }
        foreach ((array) ($raw['fonts'] ?? []) as $k => $font) {
            if (is_string($k) && preg_match('/^[a-z][a-z0-9-]{0,40}$/', $k) === 1 && is_array($font) && is_string($font['stack'] ?? null) && self::safeStack($font['stack'])) {
                $def['fonts'][$k] = ['label' => mb_substr((string) ($font['label'] ?? $k), 0, 60), 'stack' => $font['stack']];
                // "preload": the main .woff2 file in the declaring theme's assets/, fetched before the CSS asks for it
                $preload = $font['preload'] ?? null;
                if (is_string($preload) && preg_match('#^(?!.*\.\.)[A-Za-z0-9_][A-Za-z0-9_./-]{0,200}\.woff2$#', $preload) === 1) {
                    $def['fonts'][$k]['preload'] = ['theme' => $owner, 'path' => $preload];
                }
            }
        }
        foreach ((array) ($raw['tokens'] ?? []) as $name => $t) {
            if (!is_string($name) || preg_match('/^[a-z][a-z0-9-]{0,40}$/', $name) !== 1 || !is_array($t) || !in_array($t['type'] ?? null, ['color', 'size', 'number', 'font'], true)) {
                Log::warning("{$owner}/styles.json: token \"{$name}\" is invalid and ignored.");
                continue;
            }
            $token = [
                'type' => $t['type'],
                'label' => mb_substr((string) ($t['label'] ?? $name), 0, 80),
                'group' => is_string($t['group'] ?? null) ? $t['group'] : 'other',
                'help' => is_string($t['help'] ?? null) ? mb_substr($t['help'], 0, 200) : null,
                'value' => $t['value'] ?? null,
            ];
            if ($t['type'] === 'size' || $t['type'] === 'number') {
                $token['unit'] = $t['type'] === 'size' && in_array($t['unit'] ?? 'px', self::UNITS, true) ? ($t['unit'] ?? 'px') : null;
                $token['min'] = is_numeric($t['min'] ?? null) ? (float) $t['min'] : 0.0;
                $token['max'] = is_numeric($t['max'] ?? null) ? (float) $t['max'] : 100.0;
                $token['step'] = is_numeric($t['step'] ?? null) && (float) $t['step'] > 0 ? (float) $t['step'] : 1.0;
            }
            $def['tokens'][$name] = $token;
        }
        foreach ((array) ($raw['contrast'] ?? []) as $pair) {
            if (is_array($pair) && is_string($pair['fg'] ?? null) && is_string($pair['bg'] ?? null)) {
                $def['contrast'][] = ['fg' => $pair['fg'], 'bg' => $pair['bg'], 'label' => mb_substr((string) ($pair['label'] ?? "{$pair['fg']} on {$pair['bg']}"), 0, 80), 'large' => !empty($pair['large'])];
            }
        }
        // a child theme (often one made in the admin) changes its parent's defaults and adds CSS
        foreach ((array) ($raw['values'] ?? []) as $name => $value) {
            if (is_string($name) && isset($def['tokens'][$name]) && self::value($def['tokens'][$name], $value, $def['fonts']) !== null) {
                $def['tokens'][$name]['value'] = self::value($def['tokens'][$name], $value, $def['fonts']);
            }
        }
        if (is_string($raw['custom_css'] ?? null) && trim($raw['custom_css']) !== '') {
            if (self::checkCss($raw['custom_css']) === null) {
                $def['theme_css'] = trim($raw['custom_css']);
            } else {
                Log::warning("{$owner}/styles.json: custom_css is refused (" . self::checkCss($raw['custom_css']) . ').');
            }
        }
        foreach ((array) ($raw['presets'] ?? []) as $kind => $presets) {
            if (!isset(BlockDesign::PRESET_KINDS[$kind]) || !is_array($presets)) {
                Log::warning("{$owner}/styles.json: presets.{$kind} is not a preset kind (" . implode(', ', array_keys(BlockDesign::PRESET_KINDS)) . ') and is ignored.');
                continue;
            }
            foreach ($presets as $key => $preset) {
                $value = is_array($preset) ? ($preset['value'] ?? null) : null;
                if (!is_string($key) || preg_match('/^[a-z][a-z0-9-]{0,30}$/', $key) !== 1 || !is_string($value) || !BlockDesign::safeValue($value)) {
                    Log::warning("{$owner}/styles.json: presets.{$kind}.{$key} is invalid and ignored (a lowercase name, and a colour, length or var(--token)).");
                    continue;
                }
                $def['presets'][$kind][$key] = ['label' => mb_substr((string) ($preset['label'] ?? $key), 0, 40), 'value' => $value];
            }
        }
        foreach (['light' => is_array($raw['email'] ?? null) ? $raw['email'] : [], 'dark' => is_array($raw['email']['dark'] ?? null) ? $raw['email']['dark'] : []] as $scheme => $roles) {
            foreach ($roles as $role => $ref) {
                if ($role === 'dark' && $scheme === 'light') {
                    continue;
                }
                if (!in_array($role, self::EMAIL_ROLES, true) || !is_string($ref) || preg_match('/^(?:[a-z][a-z0-9-]{0,40}|#[0-9a-fA-F]{3}|#[0-9a-fA-F]{6})$/', $ref) !== 1) {
                    Log::warning("{$owner}/styles.json: email.{$role} is ignored (roles: " . implode(', ', self::EMAIL_ROLES) . '; a token name or a #colour).');
                    continue;
                }
                $def['email'][$scheme][$role] = $ref;
            }
        }
        foreach ((array) ($raw['variations'] ?? []) as $name => $v) {
            if (is_string($name) && preg_match('/^[a-z][a-z0-9-]{0,40}$/', $name) === 1 && is_array($v)) {
                $def['variations'][$name] = ['label' => mb_substr((string) ($v['label'] ?? $name), 0, 60), 'values' => is_array($v['values'] ?? null) ? $v['values'] : []];
            }
        }
        // a token's own default must be valid too
        foreach ($def['tokens'] as $name => $token) {
            if (self::value($token, $token['value'], $def['fonts']) === null) {
                Log::warning("{$owner}/styles.json: token \"{$name}\" has an invalid default and is ignored.");
                unset($def['tokens'][$name]);
            }
        }
    }

    // ── what the site chose ─────────────────────────────────────────────

    /** @return array{variation: ?string, values: array<string, mixed>, custom_css: string} */
    public function published(): array
    {
        $slug = $this->theme()?->slug();
        $doc = $slug === null ? null : $this->documents->get($slug, 'styles', self::KEY);

        return [
            'variation' => is_string($doc['variation'] ?? null) ? $doc['variation'] : null,
            'values' => is_array($doc['values'] ?? null) ? $doc['values'] : [],
            'custom_css' => is_string($doc['custom_css'] ?? null) ? $doc['custom_css'] : '',
        ];
    }

    /**
     * Check what the admin sent. Unknown tokens and bad values are errors;
     * custom CSS is only taken from people allowed to write it ($css false
     * keeps what is published).
     *
     * @param array<string, mixed> $input {variation, values, custom_css}
     * @return array{0: array{variation: ?string, values: array<string, mixed>, custom_css: string}, 1: array<string, list<string>>}
     */
    public function normalize(array $input, bool $css): array
    {
        $def = $this->definition();
        $errors = [];
        $variation = $input['variation'] ?? null;
        if ($variation !== null && (!is_string($variation) || !isset($def['variations'][$variation]))) {
            $errors['variation'][] = Translator::get('This theme has no such style.');
            $variation = null;
        }
        [$values, $valueErrors] = $this->clean(is_array($input['values'] ?? null) ? $input['values'] : [], $def);
        foreach ($valueErrors as $k => $e) {
            $errors["values.{$k}"] = $e;
        }
        $custom = $this->published()['custom_css'];
        if ($css && array_key_exists('custom_css', $input)) {
            $custom = trim((string) $input['custom_css']);
            if (($problem = self::checkCss($custom)) !== null) {
                $errors['custom_css'][] = $problem;
            }
        }

        return [['variation' => $variation, 'values' => $values, 'custom_css' => $custom], $errors];
    }

    /**
     * @param array<string, mixed> $values
     * @return array{0: array<string, mixed>, 1: array<string, list<string>>}
     */
    private function clean(array $values, array $def): array
    {
        $out = [];
        $errors = [];
        foreach ($values as $name => $value) {
            $token = is_string($name) ? ($def['tokens'][$name] ?? null) : null;
            if ($token === null) {
                $errors[(string) $name][] = Translator::get('The theme has no such setting.');
                continue;
            }
            $clean = self::value($token, $value, $def['fonts']);
            if ($clean === null) {
                $errors[$name][] = match ($token['type']) {
                    'color' => 'A colour like #1e3a8a.',
                    'font' => 'Choose one of the fonts offered.',
                    default => sprintf('A number from %s to %s.', self::num($token['min']), self::num($token['max'])),
                };
                continue;
            }
            $out[$name] = $clean;
        }
        ksort($out);

        return [$out, $errors];
    }

    /** A token's value, cleaned, or null when invalid. */
    private static function value(array $token, mixed $value, array $fonts): mixed
    {
        return match ($token['type']) {
            'color' => is_string($value) && preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value) === 1 ? strtolower($value) : null,
            'font' => is_string($value) && isset($fonts[$value]) ? $value : null,
            'size', 'number' => is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))
                ? (((float) $value >= $token['min'] && (float) $value <= $token['max']) ? 0 + $value : null) : null,
            default => null,
        };
    }

    /**
     * Final values: the theme's defaults, then the variation, then the site's changes.
     *
     * @param array{variation: ?string, values: array<string, mixed>} $choice
     * @return array<string, mixed>
     */
    public function resolve(array $choice): array
    {
        $def = $this->definition();
        $values = array_map(static fn(array $t) => $t['value'], $def['tokens']);
        if ($choice['variation'] !== null && isset($def['variations'][$choice['variation']])) {
            $values = array_replace($values, $def['variations'][$choice['variation']]['values']);
        }
        // values for tokens the theme no longer has are dropped quietly
        return array_replace($values, array_intersect_key($choice['values'], $def['tokens']));
    }

    /**
     * The colours and font emails use, from the theme's "email" roles and
     * the site's published choices: #rrggbb colours by MailBrand role
     * (accent-ink becomes accentInk), and a font stack. Roles the theme
     * doesn't map, or that name a missing token, are left out.
     *
     * @return array{light: array<string, string>, dark: array<string, string>, font: ?string}
     */
    public function email(): array
    {
        $def = $this->definition();
        $values = $this->resolve($this->published());
        $out = ['light' => [], 'dark' => [], 'font' => null];
        foreach (['light', 'dark'] as $scheme) {
            foreach ($def['email'][$scheme] as $role => $ref) {
                $token = $def['tokens'][$ref] ?? null;
                if ($role === 'font') {
                    $key = ($token['type'] ?? null) === 'font' ? $values[$ref] : $ref;
                    $out['font'] = $scheme === 'light' && is_string($key) && isset($def['fonts'][$key]) ? $def['fonts'][$key]['stack'] : $out['font'];
                    continue;
                }
                $color = str_starts_with($ref, '#') ? $ref : (($token['type'] ?? null) === 'color' ? (string) $values[$ref] : null);
                if ($color !== null) {
                    $out[$scheme][$role === 'accent-ink' ? 'accentInk' : $role] = self::opaqueHex($color);
                }
            }
        }

        return $out;
    }

    /** #abc, #aabbcc or #aabbccdd as #aabbcc: mail clients don't all read alpha. */
    private static function opaqueHex(string $hex): string
    {
        $hex = strtolower(ltrim($hex, '#'));
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        return '#' . substr($hex, 0, 6);
    }

    // ── the stylesheet ──────────────────────────────────────────────────

    /** @param array{variation: ?string, values: array<string, mixed>, custom_css: string} $choice */
    public function css(array $choice): string
    {
        $def = $this->definition();
        $lines = [];
        foreach ($this->resolve($choice) as $name => $value) {
            $token = $def['tokens'][$name];
            $css = match ($token['type']) {
                'font' => $def['fonts'][$value]['stack'] ?? null,
                'size' => self::num((float) $value) . $token['unit'],
                'number' => self::num((float) $value),
                default => (string) $value,
            };
            if ($css !== null) {
                $lines[] = "  --{$name}: {$css};";
            }
        }
        $out = "/* Global styles (Appearance → Styles). Generated; changes here are lost. */\n";
        $hosted = [];
        foreach ($this->resolve($choice) as $name => $value) {
            if ($def['tokens'][$name]['type'] === 'font' && !empty($def['fonts'][$value]['hosted'])) {
                $hosted[] = $value;
            }
        }
        if ($hosted !== [] && $this->fonts !== null) {
            $out .= $this->fonts->faces($hosted) . "\n";
        }
        $out .= ":root {\n" . implode("\n", $lines) . "\n}\n";
        if ($def['theme_css'] !== '') {
            $out .= "\n/* Theme CSS */\n" . $def['theme_css'] . "\n";
        }
        if ($choice['custom_css'] !== '' && self::checkCss($choice['custom_css']) === null) {
            $out .= "\n/* Custom CSS */\n" . $choice['custom_css'] . "\n";
        }

        return $out;
    }

    /** The published stylesheet (memoized per request). Empty when the theme declares nothing and nothing was chosen. */
    public function publishedCss(): string
    {
        if ($this->cssMemo !== null) {
            return $this->cssMemo;
        }
        $published = $this->published();
        $def = $this->definition();
        $nothing = $def['tokens'] === [] && $published['custom_css'] === '' && $def['theme_css'] === '';
        // a theme's own values are already in its CSS; a child theme's changes and CSS are not
        $untouched = $published['variation'] === null && $published['values'] === [] && $published['custom_css'] === '' && !$this->childChanges();

        $main = $nothing || $untouched ? '' : $this->css($published);
        $presets = "/* Block design presets (styles.json \"presets\", else core defaults) */\n" . BlockDesign::css($this->presets());

        return $this->cssMemo = ($main === '' ? '' : $main . "\n") . $presets;
    }

    /**
     * What editors pick from when they style a block (see the class comment).
     *
     * @return array<string, array<string, array{label: string, value: string}>>
     */
    public function presets(): array
    {
        $def = $this->definition();
        $out = $def['presets'];
        if (empty($out['color'])) {
            foreach ($def['tokens'] as $name => $token) {
                if ($token['type'] === 'color') {
                    $out['color'][$name] = ['label' => $token['label'], 'value' => "var(--{$name})"];
                }
            }
        }
        foreach (array_keys(BlockDesign::PRESET_KINDS) as $kind) {
            if (empty($out[$kind])) {
                $out[$kind] = BlockDesign::DEFAULTS[$kind];
            }
        }

        return $out;
    }

    /** @return list<string> stylesheet links for pages (always one: the block design presets are in it) */
    public function stylesheetUrls(): array
    {
        $css = $this->publishedCss();

        return $css === '' ? [] : [self::ROUTE . '?v=' . substr(hash('sha256', $css), 0, 12)];
    }

    /**
     * Font files worth fetching before the stylesheet asks for them: the one
     * main file of each font the published styles use (a theme's declared
     * "preload", or a hosted font's regular latin file). At most two.
     *
     * @return list<string>
     */
    public function preloadUrls(): array
    {
        $def = $this->definition();
        $urls = [];
        foreach ($this->resolve($this->published()) as $name => $value) {
            $font = $def['tokens'][$name]['type'] === 'font' ? ($def['fonts'][$value] ?? null) : null;
            $url = null;
            if (isset($font['preload'])) {
                $url = $this->themeAsset($font['preload']['theme'], $font['preload']['path']);
            } elseif (!empty($font['hosted']) && $this->fonts !== null) {
                $url = $this->fonts->mainFileUrl((string) $value);
            }
            if ($url !== null && !in_array($url, $urls, true)) {
                $urls[] = $url;
            }
        }

        return array_slice($urls, 0, 2);
    }

    private function themeAsset(string $slug, string $path): ?string
    {
        foreach ($this->cms->extensions()->themeChain() as $package) {
            if ($package->slug() === $slug) {
                return \Kestello\Cms\Extension\AssetController::resolve($package->assetsPath(), $path) === null ? null
                    : "/assets/themes/{$slug}/~" . rawurlencode($package->version()) . '/' . $path;
            }
        }

        return null;
    }

    /** Whether a theme in the chain (other than the root) sets values, CSS or uses a hosted font. */
    private function childChanges(): bool
    {
        $def = $this->definition();
        if ($def['theme_css'] !== '') {
            return true;
        }
        foreach ($def['tokens'] as $t) {
            if ($t['type'] === 'font' && !empty($def['fonts'][$t['value']]['hosted'])) {
                return true;
            }
        }
        $chain = $this->cms->extensions()->themeChain();
        foreach (array_slice($chain, 0, -1) as $package) {
            $raw = is_file($package->path('styles.json')) ? json_decode((string) file_get_contents($package->path('styles.json')), true) : null;
            if (is_array($raw) && !empty($raw['values'])) {
                return true;
            }
        }

        return false;
    }

    /** @internal tests and publishing */
    public function forget(): void
    {
        $this->cssMemo = null;
        $this->definitions = [];
        $this->cms->blocks->design->forget();
    }

    /**
     * Custom CSS that can't escape its stylesheet, run script, or call out
     * to other sites. Null when fine, the problem otherwise.
     */
    public static function checkCss(string $css): ?string
    {
        if (strlen($css) > self::MAX_CSS) {
            return 'Custom CSS is limited to 50 KB.';
        }
        if (preg_match('/\\\\[0-9a-f]/i', $css) === 1) {
            return 'Escaped characters (\\ followed by a code) aren\'t allowed in custom CSS: they could spell anything.';
        }
        $flat = strtolower(preg_replace('#/\*.*?\*/#s', '', $css) ?? $css);
        foreach (['<' => 'HTML tags', '@import' => '@import', 'expression(' => 'expression()', 'javascript:' => 'javascript: addresses', 'vbscript:' => 'vbscript: addresses', '-moz-binding' => '-moz-binding', '@charset' => '@charset', '@namespace' => '@namespace'] as $needle => $what) {
            if (str_contains($flat, $needle)) {
                return "Custom CSS can't contain {$what}.";
            }
        }
        if (preg_match('/(?<![a-z-])behavior\s*:/', $flat) === 1) {
            return "Custom CSS can't contain behavior.";
        }
        if (preg_match_all('/url\(\s*([\'"]?)(.*?)\1\s*\)/s', $flat, $m) !== false) {
            foreach ($m[2] as $url) {
                $url = trim($url);
                if (!(str_starts_with($url, '/') && !str_starts_with($url, '//')) && !str_starts_with($url, 'data:image/') && !str_starts_with($url, '#')) {
                    return 'Images in custom CSS must come from this site (addresses starting with /), so visitors\' browsers don\'t contact other sites.';
                }
            }
        }
        // image-set() and src() take plain strings as addresses, no url() needed
        if (preg_match_all('/(?:image-set|src)\(([^()]*(?:\([^()]*\)[^()]*)*)\)/s', $flat, $m) > 0) {
            foreach ($m[1] as $args) {
                preg_match_all('/([\'"])(.*?)\1/s', $args, $strings);
                foreach ($strings[2] as $url) {
                    $url = trim($url);
                    if (!(str_starts_with($url, '/') && !str_starts_with($url, '//')) && !str_starts_with($url, 'data:image/')) {
                        return 'Images in custom CSS must come from this site (addresses starting with /), so visitors\' browsers don\'t contact other sites.';
                    }
                }
            }
        }
        if (preg_match('/@font-face/', $flat) === 1 && preg_match('/src\s*:[^;]*(https?:|\/\/)/', $flat) === 1) {
            return 'Fonts must come from this site.';
        }

        return null;
    }

    private static function safeStack(string $stack): bool
    {
        return strlen($stack) <= 300 && preg_match('/^[A-Za-z0-9 ,\'"._-]+$/', $stack) === 1;
    }

    private static function num(float $n): string
    {
        return rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.');
    }
}
