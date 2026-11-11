<?php

declare(strict_types=1);

namespace Kestello\Cms\Mail;

use Kestello\Framework\Settings\Settings;

/**
 * How an email looks: the site's name at the foot, and the colours and font
 * the layout writes into its inline styles.
 *
 *   MailBrand::site()                  the site's name, Kestello's colours
 *   MailBrand::named('Studio Ana')     the same with a given name
 *
 * Colours are #rrggbb. "accent" is the button and "accentInk" its label.
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
    public const string DEFAULT_FONT = '-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif';

    /** @param array<string, string> $colors keys of DEFAULT_COLORS; missing ones take the default */
    public function __construct(
        public readonly string $name,
        private readonly array $colors = [],
        public readonly string $font = self::DEFAULT_FONT,
    ) {
        foreach ($colors as $role => $value) {
            if (!array_key_exists($role, self::DEFAULT_COLORS)) {
                throw new \InvalidArgumentException("Unknown email colour \"{$role}\". Known: " . implode(', ', array_keys(self::DEFAULT_COLORS)) . '.');
            }
            if (preg_match('/^#[0-9a-fA-F]{6}$/', $value) !== 1) {
                throw new \InvalidArgumentException("The email colour \"{$role}\" must be #rrggbb, got \"{$value}\".");
            }
        }
        if (preg_match('/[;{}<>"]/', $font) === 1) {
            throw new \InvalidArgumentException('An email font stack cannot contain ; { } < > or double quotes.');
        }
    }

    public static function named(string $name): self
    {
        return new self(trim($name) !== '' ? trim($name) : 'Kestello');
    }

    /** The site's name (Settings → General), else "Kestello". */
    public static function site(): self
    {
        return self::named((string) Settings::getInstance()->get('site_name', 'Kestello'));
    }

    /** One colour by role: page, card, ink, muted, line, accent, accentInk. */
    public function color(string $role): string
    {
        return $this->colors[$role] ?? self::DEFAULT_COLORS[$role] ?? throw new \InvalidArgumentException("Unknown email colour \"{$role}\".");
    }
}
