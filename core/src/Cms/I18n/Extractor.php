<?php

declare(strict_types=1);

namespace Kestello\Cms\I18n;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Finds the text a package shows people, for a translation template (.pot).
 *
 * PHP (read as tokens, so strings in comments never count):
 *   __('…'), trans_choice('…', $n), Translator::get('…'), Translator::choice('…', $n)
 *   ->label('…'[, '…']), ->help('…'), ->description('…')
 *   $admin->page($path, $component, '…'), ->group($id, '…'), ->link($id, '…'),
 *   ->widget($area, $component, '…'), ->column($list, $key, '…'), label: '…', title: '…', labels: […]
 *   'label' => '…', 'title' => '…', 'description' => '…', 'singular' => '…', 'plural' => '…',
 *   and every value of 'labels' => ['key' => '…'] and of Schema::enum(['value' => '…'])
 * JavaScript and Vue: __('…') (also the one useI18n() returns).
 *
 * Only literal strings are found: __($text) cannot be, so pass literals.
 *
 * @internal
 */
final class Extractor
{
    /** method => which arguments are text */
    private const array METHODS = [
        'label' => [0, 1], 'help' => [0], 'description' => [0],
        'page' => [2], 'group' => [1], 'link' => [1], 'widget' => [2], 'column' => [2],
    ];

    /** function => which arguments are text */
    private const array FUNCTIONS = ['__' => [0], 'trans_choice' => [0]];

    /** Translator::<method> => which arguments are text */
    private const array STATICS = ['get' => [0], 'choice' => [0]];

    private const array KEYS = ['label', 'title', 'description', 'singular', 'plural', 'help'];

    private const array NAMED = ['label', 'title', 'description'];

    /** Directories never scanned. */
    public const array SKIP = ['node_modules', 'vendor', 'build', 'storage', 'tests', 'releases', '.git'];

    /** @var array<string, array<string, true>> msgid => files it is in */
    private array $found = [];

    /** Read every .php, .js, .mjs and .vue file under $dir (paths in the template are relative to $root). */
    public function scan(string $root, string $dir): self
    {
        if (!is_dir($dir)) {
            throw new \InvalidArgumentException("There is no folder {$dir}.");
        }
        $files = new RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            static fn(\SplFileInfo $f) => !($f->isDir() && in_array($f->getFilename(), self::SKIP, true)),
        ));
        $list = [];
        foreach ($files as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile() && in_array($file->getExtension(), ['php', 'js', 'mjs', 'vue'], true)) {
                $list[] = $file->getPathname();
            }
        }
        sort($list);
        foreach ($list as $path) {
            $relative = ltrim(substr($path, strlen(rtrim($root, '/'))), '/');
            $code = (string) file_get_contents($path);
            $this->add($relative, str_ends_with($path, '.php') ? self::php($code) : self::js($code));
        }

        return $this;
    }

    /** @param list<string> $messages */
    public function add(string $file, array $messages): self
    {
        foreach ($messages as $message) {
            $this->found[$message][$file] = true;
        }

        return $this;
    }

    /** @return array<string, list<string>> msgid => files, sorted */
    public function messages(): array
    {
        $out = [];
        foreach ($this->found as $id => $files) {
            $names = array_keys($files);
            sort($names);
            $out[(string) $id] = $names;
        }
        ksort($out, SORT_STRING);

        return $out;
    }

    /**
     * The template. No creation date, so it only changes when the text does
     * (CI checks that the committed one is current).
     */
    public function pot(string $project, string $domain): string
    {
        $out = "# Translation template for {$project} (domain \"{$domain}\").\n"
            . "# Made by `bin/cms i18n:extract`. Messages use ICU MessageFormat: keep {placeholders} and plural cases.\n"
            . "msgid \"\"\n"
            . "msgstr \"\"\n"
            . '"Project-Id-Version: ' . self::escape($project) . "\\n\"\n"
            . "\"MIME-Version: 1.0\\n\"\n"
            . "\"Content-Type: text/plain; charset=UTF-8\\n\"\n"
            . "\"Content-Transfer-Encoding: 8bit\\n\"\n"
            . '"X-Domain: ' . self::escape($domain) . "\\n\"\n";
        foreach ($this->messages() as $id => $files) {
            $out .= "\n";
            foreach ($files as $file) {
                $out .= "#: {$file}\n";
            }
            if (str_contains($id, '{') && preg_match('/\{\s*\w+\s*,\s*(plural|select|selectordinal)\s*,/', $id) === 1) {
                $out .= "#. ICU message: translate every case, keep the {name, plural, ...} structure.\n";
            }
            $out .= 'msgid ' . self::quote($id) . "\nmsgstr \"\"\n";
        }

        return $out;
    }

    // ── PHP ──────────────────────────────────────────────────────────

    /** @return list<string> */
    public static function php(string $code): array
    {
        $tokens = array_values(array_filter(
            token_get_all($code),
            static fn($t) => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));
        $out = [];
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            $prev = $tokens[$i - 1] ?? null;
            $next = $tokens[$i + 1] ?? null;

            // 'label' => '…'  and  'labels' => ['key' => '…', …]
            if (self::isString($t) && self::is($next, T_DOUBLE_ARROW)) {
                $key = self::unquote($t[1]);
                $value = $tokens[$i + 2] ?? null;
                if ($key !== null && in_array($key, self::KEYS, true) && self::isString($value)) {
                    $out[] = self::unquote($value[1]);
                } elseif ($key === 'labels' && $value === '[') {
                    $out = [...$out, ...self::arrayValues($tokens, $i + 2)];
                }
                continue;
            }

            // label: '…' and labels: ['key' => '…'] (named arguments)
            if (is_array($t) && $t[0] === T_STRING && $next === ':' && ($prev === '(' || $prev === ',')) {
                $value = $tokens[$i + 2] ?? null;
                if (in_array($t[1], self::NAMED, true) && self::isString($value)) {
                    $out[] = self::unquote($value[1]);
                } elseif ($t[1] === 'labels' && $value === '[') {
                    $out = [...$out, ...self::arrayValues($tokens, $i + 2)];
                }
                continue;
            }

            if (!is_array($t) || $next !== '(') {
                continue;
            }
            $name = ltrim(strtolower($t[1]), '\\');
            $short = str_contains($name, '\\') ? substr($name, (int) strrpos($name, '\\') + 1) : $name;
            $indexes = null;
            if (self::is($prev, T_OBJECT_OPERATOR) || self::is($prev, T_NULLSAFE_OBJECT_OPERATOR)) {
                $indexes = self::METHODS[$t[1]] ?? null;
            } elseif (self::is($prev, T_DOUBLE_COLON)) {
                $class = $tokens[$i - 2] ?? null;
                // Schema::enum(['value' => 'Label', …]): the labels, never the values of a plain list.
                if ($t[1] === 'enum' && is_array($class) && preg_match('/(^|\\\\)Schema$/', $class[1]) === 1 && ($tokens[$i + 2] ?? null) === '[') {
                    $out = [...$out, ...self::arrayValues($tokens, $i + 2)];
                    continue;
                }
                $isTranslator = is_array($class) && preg_match('/(^|\\\\)Translator$/', $class[1]) === 1;
                $indexes = $isTranslator ? (self::STATICS[$t[1]] ?? null) : null;
            } elseif (in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) && !self::is($prev, T_FUNCTION)) {
                $indexes = self::FUNCTIONS[$short] ?? null;
            }
            if ($indexes === null) {
                continue;
            }
            $args = self::arguments($tokens, $i + 1);
            foreach ($indexes as $index) {
                $arg = $args[$index] ?? null;
                if ($arg !== null && count($arg) === 1 && self::isString($arg[0])) {
                    $out[] = self::unquote($arg[0][1]);
                }
            }
        }

        return array_values(array_filter($out, static fn(?string $s) => $s !== null && trim($s) !== ''));
    }

    /**
     * The top-level arguments of the call whose "(" is at $open, each as its tokens.
     *
     * @param list<mixed> $tokens
     * @return list<list<mixed>>
     */
    private static function arguments(array $tokens, int $open): array
    {
        $args = [[]];
        $depth = 0;
        for ($i = $open + 1, $n = count($tokens); $i < $n; $i++) {
            $t = $tokens[$i];
            if (in_array($t, ['(', '[', '{'], true) || self::is($t, T_CURLY_OPEN) || self::is($t, T_DOLLAR_OPEN_CURLY_BRACES)) {
                $depth++;
            } elseif (in_array($t, [')', ']', '}'], true)) {
                if ($depth === 0) {
                    break;
                }
                $depth--;
            } elseif ($t === ',' && $depth === 0) {
                $args[] = [];
                continue;
            }
            $args[count($args) - 1][] = $t;
        }

        return $args === [[]] ? [] : $args;
    }

    /**
     * The literal values of the array opened at $open ("key" => "value" pairs at its top level).
     *
     * @param list<mixed> $tokens
     * @return list<?string>
     */
    private static function arrayValues(array $tokens, int $open): array
    {
        $out = [];
        $depth = 0;
        for ($i = $open + 1, $n = count($tokens); $i < $n; $i++) {
            $t = $tokens[$i];
            if (in_array($t, ['(', '['], true)) {
                $depth++;
            } elseif (in_array($t, [')', ']'], true)) {
                if ($depth === 0) {
                    break;
                }
                $depth--;
            } elseif ($depth === 0 && self::is($t, T_DOUBLE_ARROW) && self::isString($tokens[$i + 1] ?? null)
                && in_array($tokens[$i + 2] ?? null, [',', ']'], true)) {
                $out[] = self::unquote($tokens[$i + 1][1]);
            }
        }

        return $out;
    }

    private static function is(mixed $token, int $id): bool
    {
        return is_array($token) && $token[0] === $id;
    }

    private static function isString(mixed $token): bool
    {
        return self::is($token, T_CONSTANT_ENCAPSED_STRING);
    }

    /** A PHP string literal's value; null for a double-quoted one with escapes we don't read. */
    private static function unquote(string $literal): ?string
    {
        $body = substr($literal, 1, -1);
        if ($literal[0] === "'") {
            return strtr($body, ['\\\\' => '\\', "\\'" => "'"]);
        }

        return stripcslashes(str_replace('\\$', '$', $body));
    }

    // ── JavaScript and Vue ───────────────────────────────────────────

    /** @return list<string> */
    public static function js(string $code): array
    {
        $out = [];
        $string = '(?:\'((?:\\\\.|[^\'\\\\\n])*)\'|"((?:\\\\.|[^"\\\\\n])*)"|`((?:\\\\.|[^`\\\\$])*)`)';
        preg_match_all('/(?<![\w$.])__\(\s*' . $string . '\s*[,)]/', $code, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            $raw = ($m[1] ?? '') !== '' ? $m[1] : ((($m[2] ?? '') !== '') ? $m[2] : ($m[3] ?? ''));
            $text = self::unescapeJs($raw);
            if (trim($text) !== '') {
                $out[] = $text;
            }
        }

        return $out;
    }

    private static function unescapeJs(string $s): string
    {
        return (string) preg_replace_callback(
            '/\\\\(u\{[0-9a-fA-F]+\}|u[0-9a-fA-F]{4}|x[0-9a-fA-F]{2}|.)/s',
            static function (array $m): string {
                $e = $m[1];
                return match (true) {
                    $e === 'n' => "\n",
                    $e === 't' => "\t",
                    $e === 'r' => "\r",
                    str_starts_with($e, 'u{') => mb_chr((int) hexdec(substr($e, 2, -1))),
                    $e[0] === 'u' => mb_chr((int) hexdec(substr($e, 1))),
                    $e[0] === 'x' => mb_chr((int) hexdec(substr($e, 1))),
                    default => $e,
                };
            },
            $s,
        );
    }

    // ── PO syntax ────────────────────────────────────────────────────

    private static function escape(string $s): string
    {
        return strtr($s, ['\\' => '\\\\', '"' => '\\"', "\n" => '\\n', "\t" => '\\t', "\r" => '\\r']);
    }

    private static function quote(string $s): string
    {
        if (!str_contains($s, "\n")) {
            return '"' . self::escape($s) . '"';
        }
        $lines = preg_split('/(?<=\n)/', $s) ?: [$s];

        return "\"\"\n" . implode("\n", array_map(static fn(string $l) => '"' . self::escape($l) . '"', array_filter($lines, static fn($l) => $l !== '')));
    }
}
