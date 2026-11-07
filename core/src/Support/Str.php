<?php

declare(strict_types=1);

namespace Kestello\Support;

/**
 * String helpers. Multibyte-safe throughout.
 *
 *   Str::slug('Ђорђе & Straße')        // "dorde-strasse"
 *   Str::limit('A long title', 6)      // "A long…"
 *   Str::uuid7()                       // time-ordered UUID
 *
 * @api
 */
final class Str
{
    private const array PLURAL_IRREGULAR = [
        'person' => 'people', 'man' => 'men', 'woman' => 'women', 'child' => 'children', 'tooth' => 'teeth',
        'foot' => 'feet', 'mouse' => 'mice', 'goose' => 'geese', 'ox' => 'oxen', 'leaf' => 'leaves',
        'life' => 'lives', 'knife' => 'knives', 'wife' => 'wives', 'half' => 'halves', 'criterion' => 'criteria',
        'datum' => 'data', 'medium' => 'media', 'index' => 'indices', 'matrix' => 'matrices', 'vertex' => 'vertices',
        'analysis' => 'analyses', 'thesis' => 'theses', 'crisis' => 'crises', 'cactus' => 'cacti', 'focus' => 'foci',
        'quiz' => 'quizzes', 'move' => 'moves', 'sex' => 'sexes',
    ];

    private const array UNCOUNTABLE = [
        'audio', 'equipment', 'information', 'rice', 'money', 'species', 'series', 'fish', 'sheep', 'deer',
        'news', 'software', 'hardware', 'feedback', 'metadata', 'traffic', 'content', 'furniture', 'advice',
        'staff', 'police', 'jeans', 'moose', 'aircraft', 'education', 'knowledge', 'luggage', 'music', 'evidence',
    ];

    private const string ALNUM = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    private const string CROCKFORD = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    // ── Transliteration and slugs ───────────────────────────────────

    /** Transliterate to ASCII ("Ђорђе" → "Dorde", "Straße" → "Strasse"). */
    public static function ascii(string $value): string
    {
        if (function_exists('transliterator_transliterate')) {
            $out = transliterator_transliterate('Any-Latin; Latin-ASCII; [^\u0000-\u007F] remove', $value);
            if (is_string($out)) {
                return $out;
            }
        }

        // Degraded path without intl: strip accents via iconv, drop the rest.
        $out = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return preg_replace('/[^\x20-\x7E]/', '', $out === false ? $value : $out) ?? '';
    }

    public static function slug(string $value, string $separator = '-'): string
    {
        $value = strtolower(self::ascii($value));
        $value = str_replace(['@', '&'], [' at ', ' and '], $value);
        $value = preg_replace('/[^a-z0-9]+/', $separator, $value) ?? '';

        return trim($value, $separator);
    }

    // ── Case ────────────────────────────────────────────────────────

    /** Words of an identifier or phrase: "userProfile_ID-x" → ["user", "Profile", "ID", "x"]. */
    private static function words_(string $value): array
    {
        $value = preg_replace('/([a-z\d])([A-Z])/u', '$1 $2', $value) ?? $value;
        $value = preg_replace('/([A-Z]+)([A-Z][a-z])/u', '$1 $2', $value) ?? $value;

        return preg_split('/[^\p{L}\p{N}]+/u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    public static function camel(string $value): string
    {
        $studly = self::studly($value);

        return mb_strtolower(mb_substr($studly, 0, 1)) . mb_substr($studly, 1);   // "Ćirić" → "ćirić", not lcfirst's bytes
    }

    public static function studly(string $value): string
    {
        return implode('', array_map(static fn(string $w) => self::ucfirst(mb_strtolower($w)), self::words_($value)));
    }

    public static function snake(string $value, string $delimiter = '_'): string
    {
        return mb_strtolower(implode($delimiter, self::words_($value)));
    }

    public static function kebab(string $value): string
    {
        return self::snake($value, '-');
    }

    public static function title(string $value): string
    {
        return mb_convert_case($value, MB_CASE_TITLE, 'UTF-8');
    }

    /** "user_profile-id" → "User Profile Id" */
    public static function headline(string $value): string
    {
        return implode(' ', array_map(static fn(string $w) => self::ucfirst(mb_strtolower($w)), self::words_($value)));
    }

    public static function lower(string $value): string
    {
        return mb_strtolower($value, 'UTF-8');
    }

    public static function upper(string $value): string
    {
        return mb_strtoupper($value, 'UTF-8');
    }

    public static function ucfirst(string $value): string
    {
        return mb_strtoupper(mb_substr($value, 0, 1)) . mb_substr($value, 1);
    }

    public static function length(string $value): int
    {
        return mb_strlen($value);
    }

    public static function substr(string $value, int $start, ?int $length = null): string
    {
        return mb_substr($value, $start, $length);
    }

    // ── Truncation ──────────────────────────────────────────────────

    public static function limit(string $value, int $limit = 100, string $end = '…'): string
    {
        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, max(0, $limit))) . $end;
    }

    public static function words(string $value, int $words = 100, string $end = '…'): string
    {
        preg_match('/^\s*+(?:\S++\s*+){1,' . max(1, $words) . '}/u', $value, $m);
        if (!isset($m[0]) || mb_strlen($value) === mb_strlen($m[0])) {
            return $value;
        }

        return rtrim($m[0]) . $end;
    }

    /** Markdown → sanitized HTML ("rich-text" or "basic" profile). */
    public static function markdown(string $markdown, string $profile = 'rich-text'): string
    {
        return Markdown::toHtml($markdown, $profile);
    }

    /** Plain-text excerpt of HTML or text, cut at a word boundary. */
    public static function excerpt(string $htmlOrText, int $length = 160, string $end = '…'): string
    {
        $text = self::squish(Html::toText($htmlOrText));
        if (mb_strlen($text) <= $length) {
            return $text;
        }

        $cut = mb_substr($text, 0, $length + 1);
        $space = mb_strrpos($cut, ' ');
        $cut = $space !== false && $space > $length * 0.6 ? mb_substr($cut, 0, $space) : mb_substr($cut, 0, $length);

        return rtrim($cut, " \t\n,.;:-") . $end;
    }

    public static function squish(string $value): string
    {
        return trim(preg_replace('/[\s\x{00A0}\x{200B}\x{FEFF}]+/u', ' ', $value) ?? $value);
    }

    // ── Searching ───────────────────────────────────────────────────

    public static function before(string $subject, string $search): string
    {
        if ($search === '') {
            return $subject;
        }
        $pos = mb_strpos($subject, $search);

        return $pos === false ? $subject : mb_substr($subject, 0, $pos);
    }

    public static function beforeLast(string $subject, string $search): string
    {
        $pos = $search === '' ? false : mb_strrpos($subject, $search);

        return $pos === false ? $subject : mb_substr($subject, 0, $pos);
    }

    public static function after(string $subject, string $search): string
    {
        if ($search === '') {
            return $subject;
        }
        $pos = mb_strpos($subject, $search);

        return $pos === false ? $subject : mb_substr($subject, $pos + mb_strlen($search));
    }

    public static function afterLast(string $subject, string $search): string
    {
        $pos = $search === '' ? false : mb_strrpos($subject, $search);

        return $pos === false ? $subject : mb_substr($subject, $pos + mb_strlen($search));
    }

    public static function between(string $subject, string $from, string $to): string
    {
        if ($from === '' || $to === '') {
            return $subject;
        }

        return self::beforeLast(self::after($subject, $from), $to);
    }

    /** @param string|iterable<string> $needles */
    public static function startsWith(string $haystack, string|iterable $needles): bool
    {
        foreach (is_string($needles) ? [$needles] : $needles as $needle) {
            if ((string) $needle !== '' && str_starts_with($haystack, (string) $needle)) {
                return true;
            }
        }

        return false;
    }

    /** @param string|iterable<string> $needles */
    public static function endsWith(string $haystack, string|iterable $needles): bool
    {
        foreach (is_string($needles) ? [$needles] : $needles as $needle) {
            if ((string) $needle !== '' && str_ends_with($haystack, (string) $needle)) {
                return true;
            }
        }

        return false;
    }

    /** True if $haystack contains any of $needles. @param string|iterable<string> $needles */
    public static function contains(string $haystack, string|iterable $needles, bool $ignoreCase = false): bool
    {
        if ($ignoreCase) {
            $haystack = mb_strtolower($haystack);
        }
        foreach (is_string($needles) ? [$needles] : $needles as $needle) {
            $needle = $ignoreCase ? mb_strtolower((string) $needle) : (string) $needle;
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** @param iterable<string> $needles */
    public static function containsAll(string $haystack, iterable $needles, bool $ignoreCase = false): bool
    {
        foreach ($needles as $needle) {
            if (!self::contains($haystack, (string) $needle, $ignoreCase)) {
                return false;
            }
        }

        return true;
    }

    /** Wildcard match: Str::is('admin/*', $path). @param string|iterable<string> $patterns */
    public static function is(string|iterable $patterns, string $value): bool
    {
        foreach (is_string($patterns) ? [$patterns] : $patterns as $pattern) {
            $pattern = (string) $pattern;
            if ($pattern === $value) {
                return true;
            }
            $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '\z#u';
            if (preg_match($regex, $value) === 1) {
                return true;
            }
        }

        return false;
    }

    public static function replaceFirst(string $search, string $replace, string $subject): string
    {
        $pos = $search === '' ? false : strpos($subject, $search);

        return $pos === false ? $subject : substr_replace($subject, $replace, $pos, strlen($search));
    }

    public static function replaceLast(string $search, string $replace, string $subject): string
    {
        $pos = $search === '' ? false : strrpos($subject, $search);

        return $pos === false ? $subject : substr_replace($subject, $replace, $pos, strlen($search));
    }

    // ── Formatting ──────────────────────────────────────────────────

    /** Str::mask('4242424242424242', '*', 0, -4) → "************4242" */
    public static function mask(string $value, string $character, int $index, ?int $length = null): string
    {
        $len = mb_strlen($value);
        $start = $index < 0 ? max(0, $len + $index) : min($index, $len);
        $count = $length === null ? $len - $start : ($length < 0 ? max(0, $len - $start + $length) : min($length, $len - $start));
        if ($character === '' || $count <= 0) {
            return $value;
        }

        return mb_substr($value, 0, $start) . str_repeat(mb_substr($character, 0, 1), $count) . mb_substr($value, $start + $count);
    }

    public static function padBoth(string $value, int $length, string $pad = ' '): string
    {
        $missing = max(0, $length - mb_strlen($value));

        return self::repeatTo($pad, intdiv($missing, 2)) . $value . self::repeatTo($pad, $missing - intdiv($missing, 2));
    }

    public static function padLeft(string $value, int $length, string $pad = ' '): string
    {
        return self::repeatTo($pad, max(0, $length - mb_strlen($value))) . $value;
    }

    public static function padRight(string $value, int $length, string $pad = ' '): string
    {
        return $value . self::repeatTo($pad, max(0, $length - mb_strlen($value)));
    }

    private static function repeatTo(string $pad, int $length): string
    {
        if ($length <= 0 || $pad === '') {
            return '';
        }

        return mb_substr(str_repeat($pad, (int) ceil($length / mb_strlen($pad))), 0, $length);
    }

    public static function wrap(string $value, string $before, ?string $after = null): string
    {
        return $before . $value . ($after ?? $before);
    }

    public static function start(string $value, string $prefix): string
    {
        return $prefix . preg_replace('/^(?:' . preg_quote($prefix, '/') . ')+/u', '', $value);
    }

    public static function finish(string $value, string $cap): string
    {
        return preg_replace('/(?:' . preg_quote($cap, '/') . ')+$/u', '', $value) . $cap;
    }

    public static function wordCount(string $value): int
    {
        return count(preg_split('/[\s]+/u', trim(Html::toText($value)), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /** Minutes to read, rounded up (minimum 1). */
    public static function readingTime(string $value, int $wordsPerMinute = 200): int
    {
        return max(1, (int) ceil(self::wordCount($value) / max(1, $wordsPerMinute)));
    }

    // ── Plurals (English) ───────────────────────────────────────────

    public static function plural(string $word, int|float|\Countable|array $count = 2): string
    {
        $n = is_countable($count) ? count($count) : $count;
        if ((float) $n === 1.0 || $word === '') {
            return $word;
        }

        $lower = strtolower($word);
        if (in_array($lower, self::UNCOUNTABLE, true)) {
            return $word;
        }
        if (isset(self::PLURAL_IRREGULAR[$lower])) {
            return self::matchCase(self::PLURAL_IRREGULAR[$lower], $word);
        }

        $rules = [
            '/(quiz)$/i' => '$1zes', '/^(ox)$/i' => '$1en', '/(matr|vert|ind)(ix|ex)$/i' => '$1ices',
            '/(x|ch|ss|sh|zz)$/i' => '$1es', '/([^aeiouy]|qu)y$/i' => '$1ies', '/(hive)$/i' => '$1s',
            '/(?:([^f])fe|([lr])f)$/i' => '$1$2ves', '/sis$/i' => 'ses', '/([ti])um$/i' => '$1a',
            '/(buffal|tomat|potat|her|ech)o$/i' => '$1oes', '/(bu|campu|statu|viru|alia)s$/i' => '$1ses',
            '/s$/i' => 's', '/$/' => 's',
        ];
        foreach ($rules as $pattern => $replacement) {
            if (preg_match($pattern, $word) === 1) {
                return preg_replace($pattern, $replacement, $word) ?? $word;
            }
        }

        return $word;
    }

    public static function singular(string $word): string
    {
        $lower = strtolower($word);
        if (in_array($lower, self::UNCOUNTABLE, true)) {
            return $word;
        }
        $irregular = array_search($lower, self::PLURAL_IRREGULAR, true);
        if ($irregular !== false) {
            return self::matchCase($irregular, $word);
        }

        $rules = [
            '/(quiz)zes$/i' => '$1', '/(matr)ices$/i' => '$1ix', '/(vert|ind)ices$/i' => '$1ex', '/^(ox)en/i' => '$1',
            '/(alias|status|campus|bus|virus)(es)?$/i' => '$1', '/(analy|ba|diagno|parenthe|progno|synop|the|cri)ses$/i' => '$1sis',
            '/(hive)s$/i' => '$1', '/(tive)s$/i' => '$1', '/([lr])ves$/i' => '$1f', '/([^f])ves$/i' => '$1fe',
            '/([^aeiouy]|qu)ies$/i' => '$1y', '/(x|ch|ss|sh|zz)es$/i' => '$1', '/(buffal|tomat|potat|her|ech)oes$/i' => '$1o',
            '/([ti])a$/i' => '$1um', '/(n)ews$/i' => '$1ews', '/(ss)$/i' => '$1', '/s$/i' => '',
        ];
        foreach ($rules as $pattern => $replacement) {
            if (preg_match($pattern, $word) === 1) {
                return preg_replace($pattern, $replacement, $word) ?? $word;
            }
        }

        return $word;
    }

    private static function matchCase(string $value, string $like): string
    {
        if ($like === strtoupper($like)) {
            return strtoupper($value);
        }

        return ctype_upper($like[0] ?? '') ? ucfirst($value) : $value;
    }

    // ── Random and identifiers ──────────────────────────────────────

    /** Random alphanumeric string from a CSPRNG. */
    public static function random(int $length = 32): string
    {
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= self::ALNUM[random_int(0, 61)];
        }

        return $out;
    }

    /** RFC 9562 version 4 (random) UUID. */
    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return self::formatUuid($bytes);
    }

    /**
     * RFC 9562 version 7 UUID: time-ordered, good for primary keys. Ids made
     * by one process are strictly increasing, even within a millisecond
     * (the 12-bit rand_a field becomes a counter, RFC 9562 §6.2 method 1).
     */
    public static function uuid7(?\DateTimeInterface $time = null): string
    {
        static $lastMs = 0, $counter = 0;
        $ms = $time !== null ? (int) $time->format('Uv') : (int) floor(microtime(true) * 1000);
        if ($time === null) {
            if ($ms <= $lastMs) {
                $ms = $lastMs;
                if (++$counter > 0xfff) { // counter exhausted: borrow the next millisecond
                    $ms = ++$lastMs;
                    $counter = random_int(0, 0x7ff);
                }
            } else {
                $lastMs = $ms;
                $counter = random_int(0, 0x7ff); // leave headroom to count up
            }
            $bytes = substr(pack('J', $ms), 2) . pack('n', $counter) . random_bytes(8);
        } else {
            $bytes = substr(pack('J', $ms), 2) . random_bytes(10);
        }
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x70);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return self::formatUuid($bytes);
    }

    public static function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
    }

    /** ULID: 26 Crockford base32 characters, lexicographically time-ordered. */
    public static function ulid(?\DateTimeInterface $time = null): string
    {
        $ms = $time !== null ? (int) $time->format('Uv') : (int) floor(microtime(true) * 1000);

        $timePart = '';
        for ($i = 0; $i < 10; $i++) {
            $timePart = self::CROCKFORD[$ms % 32] . $timePart;
            $ms = intdiv($ms, 32);
        }

        $random = '';
        for ($i = 0; $i < 16; $i++) {
            $random .= self::CROCKFORD[random_int(0, 31)];
        }

        return $timePart . $random;
    }

    public static function isUlid(string $value): bool
    {
        return preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/', $value) === 1;
    }

    private static function formatUuid(string $bytes): string
    {
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    public static function of(string $value = ''): Stringable
    {
        return new Stringable($value);
    }
}
