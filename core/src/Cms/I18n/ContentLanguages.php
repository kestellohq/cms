<?php

declare(strict_types=1);

namespace Kestello\Cms\I18n;

use Kestello\Cms\Cms;
use Kestello\Framework\Settings\Settings;
use Kestello\Support\Translator;

/**
 * The languages a site's content is written in: the site's main language
 * (SiteLanguage) and the others listed in the "languages" setting, else
 * config app.languages.
 *
 * The main language lives at the site's own addresses (/projects/house);
 * each other language under its code (/es/projects/casa). An entry in the
 * main language has locale '' so changing the main language rewrites
 * nothing; an entry in another language has that language's code.
 *
 * Codes are written as they appear in addresses: lowercase, a hyphen
 * between parts ("es", "pt-br", "sr-latn").
 *
 * @api
 */
final class ContentLanguages
{
    public const string SETTING = 'languages';

    /** Languages offered when adding one (any other code can be typed). */
    public const array COMMON = ['ar', 'bg', 'bn', 'bs', 'ca', 'cs', 'cy', 'da', 'de', 'el', 'en', 'es', 'et', 'eu', 'fa', 'fi', 'fr', 'ga', 'gl', 'he', 'hi', 'hr', 'hu',
        'id', 'is', 'it', 'ja', 'ko', 'lt', 'lv', 'mk', 'ms', 'mt', 'nb', 'nl', 'pl', 'pt', 'pt-br', 'ro', 'ru', 'sk', 'sl', 'sq', 'sr', 'sr-latn', 'sv', 'sw', 'th', 'tr', 'uk',
        'ur', 'vi', 'zh-hans', 'zh-hant'];

    /** At most this many languages besides the main one. */
    public const int MAX = 20;

    /** The language of the request being answered: '' is the main language. */
    private static string $current = '';

    /**
     * The other languages now: the setting, else config app.languages.
     *
     * @return list<string>
     */
    public static function others(): array
    {
        $setting = Settings::getInstance()->get(self::SETTING);
        $value = $setting === null || $setting === '' || $setting === [] ? Cms::instance()?->config->get('app.languages', []) : $setting;

        return self::parse($value, SiteLanguage::site());
    }

    /**
     * The other languages from a stored value: a list or a comma-separated
     * string, cleaned up, without the main language, duplicates or more
     * than MAX. Malformed codes are dropped.
     *
     * @return list<string>
     */
    public static function parse(mixed $value, string $main): array
    {
        $items = is_string($value) ? explode(',', $value) : (is_array($value) ? $value : []);
        $main = self::code($main);
        $codes = [];
        foreach ($items as $item) {
            $code = is_string($item) ? self::code($item) : '';
            if ($code !== '' && $code !== $main && !in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        return array_slice($codes, 0, self::MAX);
    }

    /**
     * Checks a new value for the setting, written as "es, pt-br": the codes
     * to store, and what is wrong. A code is refused when it isn't one, when
     * there are more than MAX, or when $owner says something already lives
     * at /{code} (only for codes not in $current: a language the site has
     * already holds its address).
     *
     * @param callable(string): ?string $owner what answers at /{code} now, or null
     * @param list<string> $current the other languages now
     * @return array{0: list<string>, 1: list<string>} the codes, and the errors
     */
    public static function check(string $value, string $main, callable $owner, array $current = []): array
    {
        $codes = [];
        $errors = [];
        foreach (array_filter(array_map('trim', explode(',', $value)), static fn(string $v) => $v !== '') as $raw) {
            $code = self::code($raw);
            if ($code === '') {
                $errors[] = Translator::get('"{code}" isn\'t a language code. Use one such as es, de or pt-br.', ['code' => $raw]);
            } elseif ($code !== self::code($main) && !in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }
        if (count($codes) > self::MAX) {
            $errors[] = Translator::get('A site can have at most {max} languages besides its main one.', ['max' => self::MAX]);
        }
        foreach ($codes as $code) {
            if (!in_array($code, $current, true) && ($taken = $owner($code)) !== null) {
                $errors[] = Translator::get('/{code} already belongs to {owner}. Change its address first.', ['code' => $code, 'owner' => $taken]);
            }
        }

        return [$codes, $errors];
    }

    /** "pt_BR" → "pt-br"; '' when it isn't a language code. */
    public static function code(string $locale): string
    {
        $code = strtolower(str_replace('_', '-', trim($locale)));

        return preg_match('/^[a-z]{2,3}(-[a-z0-9]{2,8}){0,2}$/', $code) === 1 ? $code : '';
    }

    /** Whether $code is one of the other languages (never the main one, which has no code in addresses). */
    public static function has(string $code): bool
    {
        return $code !== '' && in_array($code, self::others(), true);
    }

    /** The language of the request being answered: '' for the main language. */
    public static function current(): string
    {
        return self::$current;
    }

    /**
     * Run $work in $code ('' = the main language): content is looked up in
     * it, links get its prefix and interface text is translated into it.
     */
    public static function within(string $code, callable $work): mixed
    {
        $previous = self::$current;
        self::$current = $code;
        try {
            return $code === '' ? $work() : Translator::withLocale($code, $work);
        } finally {
            self::$current = $previous;
        }
    }

    /** The address prefix of a language: '' for the main one, "/es" for Spanish. */
    public static function prefix(string $code): string
    {
        return $code === '' ? '' : '/' . $code;
    }

    /** $path as it is addressed in $code: ("/about", "es") → "/es/about"; ("/", "es") → "/es". */
    public static function localize(string $path, string $code): string
    {
        if ($code === '') {
            return $path;
        }

        return $path === '/' || $path === '' ? self::prefix($code) : self::prefix($code) . '/' . ltrim($path, '/');
    }

    /**
     * The language a path is in and the rest of it: ("/es/about", ["es"]) →
     * ["es", "/about"]; ("/es", ["es"]) → ["es", "/"]; a path without a
     * known code → ["", $path].
     *
     * @param list<string> $codes the other languages
     * @return array{0: string, 1: string}
     */
    public static function split(string $path, array $codes): array
    {
        $first = explode('/', ltrim($path, '/'), 2)[0];
        if ($first === '' || !in_array($first, $codes, true)) {
            return ['', $path];
        }
        $rest = substr(ltrim($path, '/'), strlen($first));

        return [$first, $rest === '' || $rest === '/' ? '/' : $rest];
    }
}
