<?php

declare(strict_types=1);

namespace Kestello\Cms\I18n;

use Kestello\Framework\Settings\Settings;
use Kestello\Support\Translator;

/**
 * The site's name and tagline in its other languages: Settings → General →
 * "In other languages", stored as one setting,
 * {"es": {"site_name": "…", "site_description": "…"}}. A language without
 * its own uses the main language's.
 *
 *   $name = SiteText::translated('site_name') ?? $settings->get('site_name');
 *
 * @api
 */
final class SiteText
{
    public const string SETTING = 'site_translations';

    /** What can be translated, with its longest length (as the main language's fields). */
    public const array KEYS = ['site_name' => 100, 'site_description' => 300];

    /**
     * $key in $language (default: the page's), or null when it has none of
     * its own (and in the main language, whose value is the setting itself).
     */
    public static function translated(string $key, ?string $language = null): ?string
    {
        $language ??= ContentLanguages::current();
        if ($language === '' || !isset(self::KEYS[$key])) {
            return null;
        }
        $value = trim((string) (self::all()[$language][$key] ?? ''));

        return $value === '' ? null : $value;
    }

    /** @return array<string, array<string, string>> by language, then key */
    public static function all(): array
    {
        $stored = Settings::getInstance()->get(self::SETTING);
        $decoded = is_string($stored) && $stored !== '' ? json_decode($stored, true) : null;

        return is_array($decoded) ? self::clean($decoded, ContentLanguages::others())[0] : [];
    }

    /**
     * A value for the setting, cleaned up: only the site's other languages
     * and the known keys, trimmed, empty ones left out. Returns it and what
     * is wrong (too long).
     *
     * @param list<string> $languages the site's other languages
     * @return array{0: array<string, array<string, string>>, 1: list<string>}
     */
    public static function clean(mixed $value, array $languages): array
    {
        $out = [];
        $errors = [];
        foreach (is_array($value) ? $value : [] as $code => $texts) {
            if (!is_string($code) || !in_array($code, $languages, true) || !is_array($texts)) {
                continue;
            }
            foreach (self::KEYS as $key => $max) {
                $text = is_string($texts[$key] ?? null) ? trim($texts[$key]) : '';
                if (mb_strlen($text) > $max) {
                    $errors[] = Translator::get('{language}: must be at most {max} characters.', ['language' => SiteLanguage::name($code), 'max' => $max]);
                } elseif ($text !== '') {
                    $out[$code][$key] = $text;
                }
            }
        }

        return [$out, $errors];
    }
}
