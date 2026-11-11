<?php

declare(strict_types=1);

namespace Kestello\Cms\Mail;

use Kestello\Cms\Cms;
use Kestello\Cms\Design\ThemeStyles;
use Kestello\Framework\Settings\Settings;
use Kestello\Support\Color;

/**
 * How an email looks: the site's name and logo, the footer text, and the
 * colours and font the layout writes into its styles, for light mail apps
 * and for dark ones.
 *
 *   MailBrand::site()                  the site as it is now: theme colours, logo, footer
 *   MailBrand::site('Studio Ana')      the same with a given name
 *   MailBrand::named('Studio Ana')     a name and Kestello's own look, nothing read
 *
 * Colours are #rrggbb, by role: page, card, ink, muted, line, accent (the
 * button) and accentInk (its label). Roles left out take the defaults.
 * The button must be readable: when its label is below 4.5:1 against it,
 * the button becomes ink on card. Text too faint on its card falls back to
 * the defaults the same way.
 *
 * @api
 */
final class MailBrand
{
    public const array DEFAULT_COLORS = [
        'page' => '#f8f7f3',
        'card' => '#ffffff',
        'ink' => '#111827',
        'muted' => '#64748b',
        'line' => '#e5e7eb',
        'accent' => '#111827',
        'accentInk' => '#ffffff',
    ];
    public const array DEFAULT_DARK = [
        'page' => '#0f1115',
        'card' => '#1a1d23',
        'ink' => '#f3f4f6',
        'muted' => '#a1a8b3',
        'line' => '#2e333b',
        'accent' => '#f3f4f6',
        'accentInk' => '#111827',
    ];
    public const string DEFAULT_FONT = '-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif';
    /** How tall the logo is drawn, in pixels. */
    public const int LOGO_HEIGHT = 40;

    /** @var array<string, string> */
    private readonly array $light;
    /** @var array<string, string> */
    private readonly array $dark;

    /**
     * @param array<string, string> $colors light colours by role
     * @param array<string, string> $dark dark colours by role; an accent left out stays the light one when it shows on the dark card
     * @param array{url: string, width: int, height: int}|null $logo a whole http(s) address
     */
    public function __construct(
        public readonly string $name,
        array $colors = [],
        public readonly string $font = self::DEFAULT_FONT,
        array $dark = [],
        public readonly ?array $logo = null,
        public readonly string $footer = '',
    ) {
        self::check($colors);
        self::check($dark);
        if (preg_match('/[;{}<>"]/', $font) === 1) {
            throw new \InvalidArgumentException('An email font stack cannot contain ; { } < > or double quotes.');
        }
        if ($logo !== null && (preg_match('#^https?://[^/\s]#i', $logo['url']) !== 1 || $logo['width'] < 1 || $logo['height'] < 1)) {
            throw new \InvalidArgumentException('An email logo needs a whole http(s) address, a width and a height.');
        }
        $this->light = self::readable(array_replace(self::DEFAULT_COLORS, $colors), self::DEFAULT_COLORS);
        $base = array_replace(self::DEFAULT_DARK, $dark);
        if (!isset($dark['accent'])) {
            // the light button, when it shows on the dark card and its label is readable
            $keep = self::ratio($this->light['accent'], $base['card']) >= 3.0 && self::ratio($this->light['accentInk'], $this->light['accent']) >= 4.5;
            [$base['accent'], $base['accentInk']] = $keep ? [$this->light['accent'], $this->light['accentInk']] : [$base['ink'], $base['card']];
        }
        $this->dark = self::readable($base, self::DEFAULT_DARK);
    }

    public static function named(string $name): self
    {
        return new self(trim($name) !== '' ? trim($name) : 'Kestello');
    }

    /**
     * The site's look as it is now: its name (Settings → General) unless one
     * is given, the colours and font its theme maps for email, the email
     * logo (Settings → Emails, else the publisher logo) and the footer text.
     * Anything that can't be read falls back to the defaults.
     */
    public static function site(?string $name = null): self
    {
        $settings = Settings::getInstance();
        $name = trim($name ?? (string) $settings->get('site_name', 'Kestello'));
        $theme = ['light' => [], 'dark' => [], 'font' => null];
        $cms = Cms::instance();
        try {
            if ($cms !== null && $cms->container->has(ThemeStyles::class)) {
                $theme = $cms->container->make(ThemeStyles::class)->email();
            }
        } catch (\Throwable $e) {
            error_log('[kestello] email colours: the theme could not be read (' . $e->getMessage() . '); using the defaults.');
        }

        return new self(
            $name !== '' ? $name : 'Kestello',
            $theme['light'],
            $theme['font'] ?? self::DEFAULT_FONT,
            $theme['dark'],
            $cms !== null ? self::siteLogo($settings) : null,
            trim((string) $settings->get('email_footer', '')),
        );
    }

    /** One light colour by role: page, card, ink, muted, line, accent, accentInk. */
    public function color(string $role): string
    {
        return $this->light[$role] ?? throw new \InvalidArgumentException("Unknown email colour \"{$role}\".");
    }

    /** One colour for mail apps in dark mode. */
    public function darkColor(string $role): string
    {
        return $this->dark[$role] ?? throw new \InvalidArgumentException("Unknown email colour \"{$role}\".");
    }

    /** Contrast between two #rrggbb colours, from 1 to 21. */
    public static function ratio(string $a, string $b): float
    {
        return Color::parse($a)->contrast(Color::parse($b));
    }

    /** @param array<string, string> $colors */
    private static function check(array $colors): void
    {
        foreach ($colors as $role => $value) {
            if (!array_key_exists($role, self::DEFAULT_COLORS)) {
                throw new \InvalidArgumentException("Unknown email colour \"{$role}\". Known: " . implode(', ', array_keys(self::DEFAULT_COLORS)) . '.');
            }
            if (preg_match('/^#[0-9a-fA-F]{6}$/', $value) !== 1) {
                throw new \InvalidArgumentException("The email colour \"{$role}\" must be #rrggbb, got \"{$value}\".");
            }
        }
    }

    /**
     * Text at least 4.5:1 on its card, else the defaults' text colours; a
     * button label at least 4.5:1 on the button, else ink on card.
     *
     * @param array<string, string> $c
     * @param array<string, string> $defaults
     * @return array<string, string>
     */
    private static function readable(array $c, array $defaults): array
    {
        if (self::ratio($c['ink'], $c['card']) < 4.5) {
            error_log("[kestello] email colours: text {$c['ink']} on {$c['card']} is too faint; using the default text and card.");
            foreach (['ink', 'card', 'muted', 'line'] as $role) {
                $c[$role] = $defaults[$role];
            }
        }
        if (self::ratio($c['muted'], $c['card']) < 4.5) {
            $c['muted'] = $c['ink'];
        }
        if (self::ratio($c['accentInk'], $c['accent']) < 4.5) {
            [$c['accent'], $c['accentInk']] = [$c['ink'], $c['card']];
        }

        return array_map('strtolower', $c);
    }

    /** @return array{url: string, width: int, height: int}|null */
    private static function siteLogo(Settings $settings): ?array
    {
        if (!filter_var($settings->get('email_show_logo', true), FILTER_VALIDATE_BOOLEAN)) {
            return null;
        }

        return MailMedia::image((int) $settings->get('email_logo', 0) ?: $settings->get('seo_publisher_logo', 0));
    }
}
