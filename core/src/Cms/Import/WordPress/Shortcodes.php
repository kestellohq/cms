<?php

declare(strict_types=1);

namespace Kestello\Cms\Import\WordPress;

/**
 * WordPress shortcodes ([gallery ids="1,2"], [caption]…[/caption]) found the
 * way WordPress finds them (get_shortcode_regex, shortcode_parse_atts), so
 * escaped ones ([[gallery]]) stay text.
 */
final class Shortcodes
{
    /**
     * Replace each shortcode named in $names with what $replace returns for it.
     *
     * @param list<string> $names
     * @param callable(string $name, array<int|string, string> $atts, string $content): string $replace
     */
    public static function replace(string $text, array $names, callable $replace): string
    {
        if ($names === [] || !str_contains($text, '[')) {
            return $text;
        }

        return (string) preg_replace_callback('/' . self::regex($names) . '/s', static function (array $m) use ($replace): string {
            if ($m[1] === '[' && ($m[6] ?? '') === ']') {
                return substr($m[0], 1, -1);    // [[name]] is the escaped, literal form
            }

            return $m[1] . $replace($m[2], self::attributes($m[3]), $m[5] ?? '') . ($m[6] ?? '');
        }, $text);
    }

    /**
     * The names of shortcodes left in $text, with how often each appears.
     * Only [name …] forms whose name looks like a shortcode are counted, so
     * "[1]" or "[sic]" in prose are left alone unless closed like a shortcode.
     *
     * @return array<string, int>
     */
    public static function names(string $text): array
    {
        if (!str_contains($text, '[')) {
            return [];
        }
        $found = [];
        preg_match_all('/(?<!\[)\[([a-z][a-z0-9_-]*)(?=[\s\]\/])(?:[^\[\]]*)\](?!\])/i', $text, $m);
        foreach ($m[1] as $i => $name) {
            $whole = $m[0][$i];
            $closed = str_contains($text, '[/' . $name . ']');
            $looksLikeOne = $closed || str_contains($whole, '=') || str_ends_with($whole, '/]') || str_contains($name, '-') || str_contains($name, '_');
            if ($looksLikeOne) {
                $key = strtolower($name);
                $found[$key] = ($found[$key] ?? 0) + 1;
            }
        }

        return $found;
    }

    /**
     * @param list<string> $names
     */
    private static function regex(array $names): string
    {
        $names = implode('|', array_map(static fn(string $n) => preg_quote($n, '/'), $names));

        // WordPress's get_shortcode_regex(): [ [? name attrs (/] | ] (content [/name])? ) ]?
        return '\[(\[?)(' . $names . ')(?![\w-])([^\]\/]*(?:\/(?!\])[^\]\/]*)*?)(?:(\/)\]|\](?:([^\[]*+(?:\[(?!\/\2\])[^\[]*+)*+)\[\/\2\])?)(\]?)';
    }

    /**
     * WordPress's shortcode_parse_atts(): name="value", name='value',
     * name=value, and bare values (kept by position).
     *
     * @return array<int|string, string>
     */
    public static function attributes(string $text): array
    {
        $atts = [];
        $text = (string) preg_replace("/[\x{00a0}\x{200b}]+/u", ' ', $text);
        $pattern = '/([\w-]+)\s*=\s*"([^"]*)"(?:\s|$)|([\w-]+)\s*=\s*\'([^\']*)\'(?:\s|$)|([\w-]+)\s*=\s*([^\s\'"]+)(?:\s|$)|"([^"]*)"(?:\s|$)|\'([^\']*)\'(?:\s|$)|(\S+)(?:\s|$)/';
        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER) === 0) {
            return [];
        }
        foreach ($matches as $m) {
            if (($m[1] ?? '') !== '') {
                $atts[strtolower($m[1])] = stripcslashes($m[2]);
            } elseif (($m[3] ?? '') !== '') {
                $atts[strtolower($m[3])] = stripcslashes($m[4]);
            } elseif (($m[5] ?? '') !== '') {
                $atts[strtolower($m[5])] = stripcslashes($m[6]);
            } elseif (isset($m[7]) && $m[7] !== '') {
                $atts[] = stripcslashes($m[7]);
            } elseif (isset($m[8]) && $m[8] !== '') {
                $atts[] = stripcslashes($m[8]);
            } elseif (isset($m[9])) {
                $atts[] = stripcslashes($m[9]);
            }
        }

        return $atts;
    }
}
