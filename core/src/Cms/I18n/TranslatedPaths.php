<?php

declare(strict_types=1);

namespace Kestello\Cms\I18n;

use Kestello\Framework\Settings\Settings;
use Kestello\Support\Str;
use Kestello\Support\Translator;

/**
 * Content type and taxonomy prefixes in the site's other languages:
 * /projects is /es/proyectos in Spanish. Stored as one setting by language,
 * then by the prefix as the site declares it:
 * {"es": {"/projects": "/proyectos", "/category": "/categoria"}}.
 *
 * Routes keep the declared prefixes. ContentLanguages::localize() writes
 * an address with the language's own prefix (out()), and LanguagePrefix
 * reads it back (in()) before routing; the declared prefix under /es then
 * redirects to the translated one, so each page has one address.
 *
 * @api
 */
final class TranslatedPaths
{
    public const string SETTING = 'path_translations';

    /** @var array{0: string, 1: array<string, array<string, string>>}|null the stored value and what it decodes to */
    private static ?array $memo = null;

    /** @return array<string, array<string, string>> by language: declared prefix => translated prefix */
    public static function all(): array
    {
        $stored = (string) (Settings::getInstance()->get(self::SETTING) ?? '');
        if (self::$memo === null || self::$memo[0] !== $stored) {
            $decoded = $stored !== '' ? json_decode($stored, true) : null;
            $map = [];
            foreach (is_array($decoded) ? $decoded : [] as $code => $paths) {
                foreach (is_array($paths) ? $paths : [] as $declared => $translated) {
                    if (is_string($code) && is_string($declared) && is_string($translated) && $declared !== '' && $translated !== '') {
                        $map[$code][$declared] = $translated;
                    }
                }
            }
            self::$memo = [$stored, $map];
        }

        return self::$memo[1];
    }

    /** $path (declared prefixes, no language prefix) with $code's own prefix: ("/projects/casa", "es") → "/proyectos/casa". */
    public static function out(string $path, string $code): string
    {
        foreach (self::sorted($code) as $declared => $translated) {
            if (self::under($path, $declared)) {
                return $translated . substr($path, strlen($declared));
            }
        }

        return $path;
    }

    /**
     * $path as asked in $code (after the language prefix) with the declared
     * prefix routes know: ("/proyectos/casa", "es") → "/projects/casa".
     */
    public static function in(string $path, string $code): string
    {
        foreach (self::sorted($code, byTranslation: true) as $declared => $translated) {
            if (self::under($path, $translated)) {
                return $declared . substr($path, strlen($translated));
            }
        }

        return $path;
    }

    /**
     * Where $path, asked in $code under a declared prefix that $code
     * translates (/es/projects/casa), lives instead ("/proyectos/casa"),
     * or null when it is already right.
     */
    public static function moved(string $path, string $code): ?string
    {
        if ($code === '' || self::in($path, $code) !== $path) {
            return null;        // already the translated address
        }
        $out = self::out($path, $code);

        return $out !== $path ? $out : null;
    }

    /**
     * A value for the setting, cleaned up: only the site's other languages
     * and declared prefixes, each translation one slug ("proyectos" or
     * "/proyectos" → "/proyectos"), empty or unchanged ones left out.
     * Refused: one that isn't a slug, two prefixes with the same translation
     * in a language, or one $owner says something else answers at.
     *
     * @param list<string> $languages the site's other languages
     * @param list<string> $declared the prefixes types and taxonomies declare
     * @param callable(string, string): ?string $owner what answers at /{code}/{segment} besides this, or null
     * @return array{0: array<string, array<string, string>>, 1: list<string>}
     */
    public static function clean(mixed $value, array $languages, array $declared, callable $owner): array
    {
        $out = [];
        $errors = [];
        foreach (is_array($value) ? $value : [] as $code => $paths) {
            if (!is_string($code) || !in_array($code, $languages, true) || !is_array($paths)) {
                continue;
            }
            $taken = [];
            foreach ($paths as $from => $to) {
                if (!is_string($from) || !in_array($from, $declared, true) || !is_string($to) || trim($to, ' /') === '') {
                    continue;
                }
                $slug = trim($to, ' /');
                $language = SiteLanguage::name($code);
                if (Str::slug($slug) !== $slug) {
                    $errors[] = Translator::get('{language}: "{path}" can only have lowercase letters, digits and hyphens.', ['language' => $language, 'path' => $slug]);
                    continue;
                }
                if ('/' . $slug === $from) {
                    continue;       // the same as the declared one
                }
                if (isset($taken[$slug])) {
                    $errors[] = Translator::get('{language}: /{path} is given twice.', ['language' => $language, 'path' => $slug]);
                    continue;
                }
                if (($other = $owner($slug, $code)) !== null) {
                    $errors[] = Translator::get('{language}: /{code}/{path} already belongs to {owner}.', ['language' => $language, 'code' => $code, 'path' => $slug, 'owner' => $other]);
                    continue;
                }
                $taken[$slug] = true;
                $out[$code][$from] = '/' . $slug;
            }
        }

        return [$out, $errors];
    }

    /** @return array<string, string> $code's translations, the longest first (so /blog/news wins over /blog) */
    private static function sorted(string $code, bool $byTranslation = false): array
    {
        $map = $code === '' ? [] : (self::all()[$code] ?? []);
        uksort($map, static fn(string $a, string $b): int => $byTranslation ? strlen($map[$b]) <=> strlen($map[$a]) : strlen($b) <=> strlen($a));

        return $map;
    }

    private static function under(string $path, string $prefix): bool
    {
        return $path === $prefix || str_starts_with($path, rtrim($prefix, '/') . '/');
    }
}
