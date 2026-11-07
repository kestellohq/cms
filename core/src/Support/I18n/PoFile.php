<?php

declare(strict_types=1);

namespace Kestello\Support\I18n;

/**
 * Minimal gettext .po reader: msgctxt ("ctx\x04id" keys), msgid,
 * msgid_plural + msgstr[n], multi-line strings, C escapes, fuzzy entries
 * skipped, and the Plural-Forms header.
 *
 * @internal
 */
final readonly class PoFile
{
    /** @param array<string, string|list<string>> $messages */
    private function __construct(public array $messages, public ?PluralRule $pluralRule) {}

    public static function parse(string $source): self
    {
        $messages = [];
        $header = '';
        $entry = [];
        $field = null;
        $fuzzy = false;
        $flush = static function () use (&$entry, &$messages, &$header, &$fuzzy): void {
            if (isset($entry['msgid'])) {
                $id = $entry['msgid'];
                if ($id === '' && !isset($entry['msgctxt'])) {
                    $header = $entry['msgstr'] ?? '';
                } elseif (!$fuzzy) {
                    $key = isset($entry['msgctxt']) ? $entry['msgctxt'] . "\x04" . $id : $id;
                    if (isset($entry['msgid_plural'])) {
                        $forms = [];
                        foreach ($entry as $k => $v) {
                            if (preg_match('/^msgstr\[(\d+)\]$/', $k, $m) === 1) {
                                $forms[(int) $m[1]] = $v;
                            }
                        }
                        ksort($forms);
                        if (array_filter($forms, static fn($f) => $f !== '') !== []) {
                            $messages[$key] = array_values($forms);
                        }
                    } elseif (($entry['msgstr'] ?? '') !== '') {
                        $messages[$key] = $entry['msgstr'];
                    }
                }
            }
            $entry = [];
            $fuzzy = false;
        };

        foreach (preg_split('/\R/', $source) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                $flush();
                $field = null;
                continue;
            }
            if (str_starts_with($line, '#,') && str_contains($line, 'fuzzy')) {
                $fuzzy = true;
                continue;
            }
            if (str_starts_with($line, '#')) {
                continue;
            }
            if (preg_match('/^(msgctxt|msgid_plural|msgid|msgstr(?:\[\d+\])?)\s+"(.*)"$/', $line, $m) === 1) {
                if (($m[1] === 'msgid' || $m[1] === 'msgctxt') && isset($entry['msgstr']) || ($m[1] === 'msgctxt' && isset($entry['msgid']))) {
                    $flush();
                }
                if ($m[1] === 'msgid' && isset($entry['msgid'])) {
                    $flush();
                }
                $field = $m[1];
                $entry[$field] = self::unescape($m[2]);
                continue;
            }
            if ($field !== null && preg_match('/^"(.*)"$/', $line, $m) === 1) {
                $entry[$field] .= self::unescape($m[1]);
            }
        }
        $flush();

        $rule = null;
        if (preg_match('/^Plural-Forms:\s*(.+)$/mi', $header, $m) === 1 && preg_match('/plural\s*=\s*([^;]+);?/i', $m[1], $p) === 1) {
            try {
                $rule = new PluralRule(trim($p[1]));
            } catch (\InvalidArgumentException) {
                $rule = null;
            }
        }

        return new self($messages, $rule);
    }

    private static function unescape(string $s): string
    {
        return strtr($s, ['\\n' => "\n", '\\t' => "\t", '\\r' => "\r", '\\"' => '"', '\\\\' => '\\']);
    }
}
