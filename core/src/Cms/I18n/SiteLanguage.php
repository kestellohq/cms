<?php

declare(strict_types=1);

namespace Kestello\Cms\I18n;

use Kestello\Support\Translator;

/**
 * The site's language: the "language" setting (Settings → General), else
 * app.locale from config, else English. The admin, emails and the theme's
 * built-in text follow it; content is written in whatever language its
 * author uses.
 *
 * A language can be chosen once core has a pack for it
 * (core/resources/lang/<locale>.po|json|php). English is the source
 * language and is always there.
 *
 * @api
 */
final class SiteLanguage
{
    public const string SETTING = 'language';

    /** The locale to use: a well-formed setting wins over config. */
    public static function resolve(?string $setting, ?string $config): string
    {
        foreach ([$setting, $config] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '' && Translator::isLocale($candidate)) {
                return trim($candidate);
            }
        }

        return 'en';
    }

    /**
     * The languages to choose from, locale => its name in that language
     * ("sr" => "srpski"), English first.
     *
     * @param list<string>|null $installed the locales core has packs for (default: Translator::locales())
     * @return array<string, string>
     */
    public static function choices(?array $installed = null): array
    {
        $choices = ['en' => self::name('en')];
        foreach ($installed ?? Translator::locales('core') as $locale) {
            $choices[$locale] ??= self::name($locale);
        }

        return $choices;
    }

    /** "sr_Latn" → "srpski (latinica)"; the code itself without intl. */
    public static function name(string $locale): string
    {
        if (!class_exists(\Locale::class)) {
            return $locale;
        }
        $name = \Locale::getDisplayName($locale, $locale);

        return is_string($name) && $name !== '' ? mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1) : $locale;
    }
}
