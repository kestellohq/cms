<?php

declare(strict_types=1);

namespace Kestello\Support;

use Kestello\Support\I18n\PluralRule;
use Kestello\Support\I18n\PoFile;

/**
 * Translations per domain (core = "core", plugins and themes = their slug).
 *
 *   use function Kestello\{__, trans_choice};
 *   __('Save changes');
 *   __('Hello, {name}!', ['name' => $user->name]);
 *   trans_choice('{count, plural, one {# comment} other {# comments}}', $n);
 *   __('Greeting', domain: 'acme-shop');
 *
 * Keys are the English source text (or a stable id with an English
 * default). Messages use ICU MessageFormat ({name}, plural, select,
 * number, date) through intl; without intl, {name} placeholders still work.
 *
 * Language packs in <package>/resources/lang/:
 *   <locale>.json          {"Save changes": "Sačuvaj izmene"}
 *   <locale>.php           return ['Save changes' => '…'];
 *   <locale>.po            gettext catalogue (msgctxt, plurals via Plural-Forms)
 * Lookup: locale ("sr_Latn_RS") → its language ("sr") → fallback ("en") → the key.
 *
 * @api
 */
final class Translator
{
    private const array RTL = ['ar', 'arc', 'ckb', 'dv', 'fa', 'ha', 'he', 'iw', 'khw', 'ks', 'ku', 'ps', 'sd', 'ug', 'ur', 'yi'];

    private static string $locale = 'en';
    private static string $fallback = 'en';
    /** @var array<string, list<string>> domain => directories */
    private static array $paths = [];
    /** @var array<string, array<string, string|list<string>>> "domain|locale" => messages */
    private static array $loaded = [];
    /** @var array<string, ?PluralRule> "domain|locale" => PO plural rule */
    private static array $plurals = [];

    public static function setLocale(string $locale): void
    {
        self::$locale = self::normalize($locale);
    }

    public static function locale(): string
    {
        return self::$locale;
    }

    public static function setFallback(string $locale): void
    {
        self::$fallback = self::normalize($locale);
    }

    /** Run $fn with another locale (e.g. an email in the recipient's language). */
    public static function withLocale(string $locale, callable $fn): mixed
    {
        $previous = self::$locale;
        self::setLocale($locale);
        try {
            return $fn();
        } finally {
            self::$locale = $previous;
        }
    }

    public static function addPath(string $domain, string $directory): void
    {
        if (is_dir($directory) && !in_array($directory, self::$paths[$domain] ?? [], true)) {
            self::$paths[$domain][] = $directory;
            foreach (array_keys(self::$loaded) as $key) {
                if (str_starts_with($key, $domain . '|')) {
                    unset(self::$loaded[$key], self::$plurals[$key]);
                }
            }
        }
    }

    /** Add messages at runtime (tests, plugins building strings in code). @param array<string, string> $messages */
    public static function add(string $domain, string $locale, array $messages): void
    {
        $key = $domain . '|' . self::normalize($locale);
        self::$loaded[$key] = $messages + (self::$loaded[$key] ?? self::load($domain, self::normalize($locale)));
    }

    /** @param array<string, mixed> $params */
    public static function get(string $key, array $params = [], string $domain = 'core', ?string $locale = null, ?string $context = null): string
    {
        $locale = $locale !== null ? self::normalize($locale) : self::$locale;
        [$message, $found] = self::lookup($domain, $locale, $context !== null ? $context . "\x04" . $key : $key);
        if (is_array($message)) {
            $message = $message[0] ?? $key;
        }

        return self::format($message ?? $key, $params, $found ?? $locale);
    }

    /** Pluralized message; {count} / # is the number. @param array<string, mixed> $params */
    public static function choice(string $key, int|float $count, array $params = [], string $domain = 'core', ?string $locale = null): string
    {
        $locale = $locale !== null ? self::normalize($locale) : self::$locale;
        $params += ['count' => $count];
        [$message, $found] = self::lookup($domain, $locale, $key);
        if (is_array($message)) { // gettext plural forms
            $rule = self::$plurals[$domain . '|' . $found] ?? null;
            $index = $rule?->index((int) $count) ?? ((int) $count === 1 ? 0 : 1);
            $message = $message[$index] ?? end($message);
        }

        return self::format($message ?? $key, $params, $found ?? $locale);
    }

    /**
     * Every message one domain has for a locale, as the browser needs it: the
     * fallback language first, then the language, then the region on top. Gettext
     * plural forms are left out (the browser formats ICU messages only), and so is
     * the fallback when it is the source language itself (English keys need nothing).
     *
     * @return array<string, string>
     */
    public static function catalogue(string $domain = 'core', ?string $locale = null): array
    {
        $locale = $locale !== null ? self::normalize($locale) : self::$locale;
        $chain = array_values(array_unique(array_filter([self::$fallback === 'en' ? null : self::$fallback, explode('_', $locale)[0], $locale])));
        $out = [];
        foreach ($chain as $candidate) {
            $cacheKey = $domain . '|' . $candidate;
            self::$loaded[$cacheKey] ??= self::load($domain, $candidate);
            foreach (self::$loaded[$cacheKey] as $key => $message) {
                if (is_string($message) && $message !== '') {
                    $out[$key] = $message;
                }
            }
        }

        return $out;
    }

    /**
     * The domains with language folders: "core", then plugins and themes.
     *
     * @return list<string>
     */
    public static function domains(): array
    {
        return array_keys(self::$paths);
    }

    /**
     * The locales a domain has a language pack for ("sr", "de_CH"), sorted. English is
     * the source language and needs none, so it is only listed when a pack exists.
     *
     * @return list<string>
     */
    public static function locales(string $domain = 'core'): array
    {
        $found = [];
        foreach (self::$paths[$domain] ?? [] as $dir) {
            foreach (scandir($dir) ?: [] as $file) {
                if (preg_match('/^([a-zA-Z]{2,3}(?:[_-][a-zA-Z0-9]{2,8})*)\.(?:json|php|po)$/', $file, $m) === 1) {
                    $found[self::normalize($m[1])] = true;
                }
            }
        }
        $locales = array_keys($found);
        sort($locales);

        return $locales;
    }

    /**
     * The pack files that hold messages for a locale, in every domain (the locale,
     * its language and a non-English fallback), so a cache can tell when they change.
     *
     * @return list<string>
     */
    public static function packFiles(string $locale): array
    {
        $locale = self::normalize($locale);
        $chain = array_values(array_unique(array_filter([self::$fallback === 'en' ? null : self::$fallback, explode('_', $locale)[0], $locale])));
        $files = [];
        foreach (self::$paths as $dirs) {
            foreach ($dirs as $dir) {
                foreach ($chain as $candidate) {
                    foreach (['php', 'json', 'po'] as $ext) {
                        $file = rtrim($dir, '/') . "/{$candidate}.{$ext}";
                        if (is_file($file)) {
                            $files[] = $file;
                        }
                    }
                }
            }
        }

        return $files;
    }

    /** Is this a well-formed locale ("sr", "sr-Latn-RS", "pt_BR")? */
    public static function isLocale(string $locale): bool
    {
        try {
            self::normalize($locale);
            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    public static function has(string $key, string $domain = 'core', ?string $locale = null): bool
    {
        return self::lookup($domain, $locale !== null ? self::normalize($locale) : self::$locale, $key, withFallback: false)[0] !== null;
    }

    public static function isRtl(?string $locale = null): bool
    {
        return in_array(strtolower(explode('_', $locale ?? self::$locale)[0]), self::RTL, true);
    }

    public static function direction(?string $locale = null): string
    {
        return self::isRtl($locale) ? 'rtl' : 'ltr';
    }

    /** @internal tests */
    public static function reset(): void
    {
        self::$locale = self::$fallback = 'en';
        self::$paths = self::$loaded = self::$plurals = [];
    }

    /** @param array<string, mixed> $params */
    public static function format(string $message, array $params, ?string $locale = null): string
    {
        if ($params === [] && !str_contains($message, '{')) {
            return $message;
        }
        if (class_exists(\MessageFormatter::class) && str_contains($message, '{')) {
            $formatted = @\MessageFormatter::formatMessage(str_replace('_', '-', $locale ?? self::$locale), $message, self::icuParams($params));
            if (is_string($formatted)) {
                return $formatted;
            }
        }

        // No intl (or not ICU syntax): plain {name} replacement.
        return (string) preg_replace_callback('/\{(\w+)\}/', static fn(array $m) => array_key_exists($m[1], $params) ? (string) self::scalar($params[$m[1]]) : $m[0], $message);
    }

    /** @return array{0: string|list<string>|null, 1: ?string} message, locale it came from */
    private static function lookup(string $domain, string $locale, string $key, bool $withFallback = true): array
    {
        $chain = [$locale];
        if (str_contains($locale, '_')) {
            $chain[] = explode('_', $locale)[0];
        }
        if ($withFallback && !in_array(self::$fallback, $chain, true)) {
            $chain[] = self::$fallback;
        }
        foreach ($chain as $candidate) {
            $cacheKey = $domain . '|' . $candidate;
            self::$loaded[$cacheKey] ??= self::load($domain, $candidate);
            $message = self::$loaded[$cacheKey][$key] ?? null;
            if ($message !== null && $message !== '' && $message !== []) {
                return [$message, $candidate];
            }
        }

        return [null, null];
    }

    /** @return array<string, string|list<string>> */
    private static function load(string $domain, string $locale): array
    {
        $messages = [];
        foreach (self::$paths[$domain] ?? [] as $dir) {
            $base = rtrim($dir, '/') . '/' . $locale;
            if (is_file($base . '.php')) {
                $data = (static fn(string $f) => require $f)($base . '.php');
                $messages = (is_array($data) ? array_filter($data, 'is_string') : []) + $messages;
            }
            if (is_file($base . '.json')) {
                $data = json_decode((string) file_get_contents($base . '.json'), true);
                $messages = (is_array($data) ? array_filter($data, 'is_string') : []) + $messages;
            }
            if (is_file($base . '.po')) {
                $po = PoFile::parse((string) file_get_contents($base . '.po'));
                $messages = $po->messages + $messages;
                self::$plurals[$domain . '|' . $locale] = $po->pluralRule;
            }
        }

        return $messages;
    }

    /** @param array<string, mixed> $params @return array<string, mixed> */
    private static function icuParams(array $params): array
    {
        $out = [];
        foreach ($params as $k => $v) {
            $out[$k] = match (true) {
                $v instanceof Date => $v->toDateTimeImmutable(),
                $v instanceof \DateTimeInterface, is_int($v), is_float($v) => $v,
                default => (string) self::scalar($v),
            };
        }

        return $out;
    }

    private static function scalar(mixed $v): string|int|float
    {
        return match (true) {
            is_int($v), is_float($v), is_string($v) => $v,
            is_bool($v) => $v ? 'true' : 'false',
            $v instanceof \Stringable => (string) $v,
            $v instanceof \DateTimeInterface => $v->format('Y-m-d H:i'),
            default => '',
        };
    }

    private static function normalize(string $locale): string
    {
        $locale = str_replace('-', '_', trim($locale));
        if (preg_match('/^[a-zA-Z]{2,3}(_[a-zA-Z0-9]{2,8})*$/', $locale) !== 1) {
            throw new \InvalidArgumentException("Invalid locale \"{$locale}\".");
        }
        $parts = explode('_', $locale);
        $parts[0] = strtolower($parts[0]);
        foreach ($parts as $i => $p) {
            if ($i > 0) {
                $parts[$i] = strlen($p) === 4 ? ucfirst(strtolower($p)) : (strlen($p) === 2 ? strtoupper($p) : $p);
            }
        }

        return implode('_', $parts);
    }
}
