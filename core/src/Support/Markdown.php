<?php

declare(strict_types=1);

namespace Kestello\Support;

/**
 * Markdown → safe HTML (CommonMark-style with GitHub extensions).
 *
 *   Markdown::toHtml($text)                       // rich-text profile
 *   Markdown::toHtml($comment, profile: 'basic')  // comments
 *
 * Blocks: ATX and setext headings, paragraphs, block quotes, ordered,
 * unordered and task lists (nested, tight/loose), fenced and indented code,
 * thematic breaks, tables with alignment. Inlines: emphasis, strong,
 * strikethrough, code spans, links and images (inline and reference),
 * autolinks, bare URLs, hard breaks, backslash escapes.
 *
 * Raw HTML in the source is escaped, never passed through, and the output
 * still goes through Html::sanitize(), so unsafe URLs (javascript:) never
 * survive.
 *
 * @api
 */
final class Markdown
{
    /** @var array<string, array{url: string, title: ?string}> */
    private array $refs = [];

    /** @var list<string> */
    private array $slots = [];

    private function __construct(private readonly string $profile) {}

    public static function toHtml(string $markdown, string $profile = 'rich-text'): string
    {
        $md = new self($profile);
        $text = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", '    '], $markdown);
        $text = $md->collectReferences($text);
        $html = $md->blocks(explode("\n", $text));

        return Html::sanitize($html, $profile);
    }

    // ── blocks ───────────────────────────────────────────────────

    /** @param list<string> $lines */
    private function blocks(array $lines, bool $tight = false): string
    {
        $out = [];
        $i = 0;
        $n = count($lines);
        while ($i < $n) {
            $line = $lines[$i];

            if (trim($line) === '') {
                $i++;
                continue;
            }

            // Fenced code
            if (preg_match('/^( {0,3})(`{3,}|~{3,})\s*([^`\s]*)[^`]*$/', $line, $m) === 1) {
                $fence = $m[2];
                $indent = strlen($m[1]);
                $code = [];
                $i++;
                while ($i < $n && preg_match('/^ {0,3}' . preg_quote($fence[0], '/') . '{' . strlen($fence) . ',}\s*$/', $lines[$i]) !== 1) {
                    $code[] = preg_replace('/^ {0,' . $indent . '}/', '', $lines[$i]);
                    $i++;
                }
                $i++; // closing fence (or end of input)
                $lang = $m[3] !== '' ? ' class="language-' . Html::escape(preg_replace('/[^\w+#.-]/', '', $m[3]) ?? '') . '"' : '';
                $out[] = '<pre><code' . $lang . '>' . Html::escape(implode("\n", $code) . ($code !== [] ? "\n" : '')) . '</code></pre>';
                continue;
            }

            // Indented code
            if (str_starts_with($line, '    ')) {
                $code = [];
                while ($i < $n && (str_starts_with($lines[$i], '    ') || trim($lines[$i]) === '')) {
                    $code[] = substr($lines[$i], 4);
                    $i++;
                }
                while ($code !== [] && trim(end($code)) === '') {
                    array_pop($code);
                }
                $out[] = '<pre><code>' . Html::escape(implode("\n", $code) . "\n") . '</code></pre>';
                continue;
            }

            // ATX heading
            if (preg_match('/^ {0,3}(#{1,6})(?:[ ]+(.*?))?(?:[ ]+#+)?[ ]*$/', $line, $m) === 1) {
                $level = strlen($m[1]);
                $out[] = "<h{$level}>" . $this->inline($m[2] ?? '') . "</h{$level}>";
                $i++;
                continue;
            }

            // Thematic break
            if (preg_match('/^ {0,3}([-*_])(?:[ ]*\1){2,}[ ]*$/', $line) === 1) {
                $out[] = '<hr>';
                $i++;
                continue;
            }

            // Block quote
            if (preg_match('/^ {0,3}>/', $line) === 1) {
                $quote = [];
                while ($i < $n && trim($lines[$i]) !== '') {
                    if (preg_match('/^ {0,3}> ?(.*)$/', $lines[$i], $q) === 1) {
                        $quote[] = $q[1];
                    } elseif ($quote !== [] && !$this->startsBlock($lines[$i])) {
                        $quote[] = $lines[$i]; // lazy continuation
                    } else {
                        break;
                    }
                    $i++;
                }
                $out[] = "<blockquote>\n" . $this->blocks($quote) . "\n</blockquote>";
                continue;
            }

            // List
            if (($marker = $this->listMarker($line)) !== null) {
                [$html, $i] = $this->parseList($lines, $i, $marker);
                $out[] = $html;
                continue;
            }

            // Table
            if ($i + 1 < $n && str_contains($line, '|') && preg_match('/^ {0,3}\|?\s*:?-+:?\s*(\|\s*:?-+:?\s*)*\|?\s*$/', $lines[$i + 1]) === 1) {
                $head = $this->cells($line);
                $aligns = array_map(static function (string $c): string {
                    $c = trim($c);
                    return match (true) {
                        str_starts_with($c, ':') && str_ends_with($c, ':') => 'center',
                        str_ends_with($c, ':') => 'right',
                        str_starts_with($c, ':') => 'left',
                        default => '',
                    };
                }, $this->cells($lines[$i + 1]));
                if (count($aligns) === count($head)) {
                    $cell = function (string $tag, string $content, int $k) use ($aligns): string {
                        $class = ($aligns[$k] ?? '') !== '' ? ' class="text-' . $aligns[$k] . '"' : '';
                        return "<{$tag}{$class}>" . $this->inline(trim($content)) . "</{$tag}>";
                    };
                    $html = '<table><thead><tr>';
                    foreach ($head as $k => $c) {
                        $html .= $cell('th', $c, $k);
                    }
                    $html .= '</tr></thead><tbody>';
                    $i += 2;
                    while ($i < $n && trim($lines[$i]) !== '' && str_contains($lines[$i], '|')) {
                        $row = array_pad($this->cells($lines[$i]), count($head), '');
                        $html .= '<tr>';
                        foreach (array_slice($row, 0, count($head)) as $k => $c) {
                            $html .= $cell('td', $c, $k);
                        }
                        $html .= '</tr>';
                        $i++;
                    }
                    $out[] = $html . '</tbody></table>';
                    continue;
                }
            }

            // Paragraph (with setext heading detection)
            $para = [];
            while ($i < $n && trim($lines[$i]) !== '') {
                if ($para !== [] && preg_match('/^ {0,3}(=+|-+)[ ]*$/', $lines[$i], $m) === 1) {
                    $level = $m[1][0] === '=' ? 1 : 2;
                    $out[] = "<h{$level}>" . $this->inline(implode("\n", $para)) . "</h{$level}>";
                    $para = [];
                    $i++;
                    continue 2;
                }
                if ($para !== [] && $this->startsBlock($lines[$i])) {
                    break;
                }
                $para[] = $lines[$i];
                $i++;
            }
            $content = $this->inline(implode("\n", array_map('ltrim', $para)));
            $out[] = $tight ? $content : "<p>{$content}</p>";
        }

        return implode("\n", $out);
    }

    /** @return array{type: string, indent: int, width: int, start: ?int, bullet: string}|null */
    private function listMarker(string $line): ?array
    {
        if (preg_match('/^( {0,3})([-*+]|(\d{1,9})([.)]))( +|$)/', $line, $m) !== 1) {
            return null;
        }
        if (preg_match('/^ {0,3}([-*_])(?:[ ]*\1){2,}[ ]*$/', $line) === 1) {
            return null; // "* * *" is a thematic break
        }
        $spaces = strlen($m[5]);

        return [
            'type' => isset($m[3]) && $m[3] !== '' ? 'ol' : 'ul',
            'indent' => strlen($m[1]),
            'width' => strlen($m[1]) + strlen($m[2]) + ($spaces >= 5 || $spaces === 0 ? 1 : $spaces),
            'start' => isset($m[3]) && $m[3] !== '' ? (int) $m[3] : null,
            'bullet' => isset($m[4]) && $m[4] !== '' ? $m[4] : $m[2],
        ];
    }

    /**
     * @param list<string> $lines
     * @param array{type: string, indent: int, width: int, start: ?int, bullet: string} $first
     * @return array{0: string, 1: int}
     */
    private function parseList(array $lines, int $i, array $first): array
    {
        $n = count($lines);
        $items = [];
        $loose = false;
        $blankBefore = false;
        while ($i < $n) {
            $marker = $this->listMarker($lines[$i]);
            if ($marker === null || $marker['type'] !== $first['type'] || $marker['bullet'] !== $first['bullet'] || $marker['indent'] > $first['indent'] + 3) {
                break;
            }
            if ($blankBefore) {
                $loose = true; // a blank line between two items of this list
            }
            $item = [substr($lines[$i], $marker['width'])];
            $i++;
            $sawBlank = false;
            while ($i < $n) {
                $line = $lines[$i];
                if (trim($line) === '') {
                    $sawBlank = true;
                    $item[] = '';
                    $i++;
                    continue;
                }
                $indent = strlen($line) - strlen(ltrim($line, ' '));
                if ($indent >= $marker['width']) {
                    if ($sawBlank) {
                        $loose = $loose || !$this->onlyNestedList($item);
                    }
                    $item[] = substr($line, $marker['width']);
                    $sawBlank = false;
                    $i++;
                    continue;
                }
                if (!$sawBlank && !$this->startsBlock($line)) {
                    $item[] = ltrim($line); // lazy continuation
                    $i++;
                    continue;
                }
                break;
            }
            $blankBefore = false;
            while ($item !== [] && end($item) === '') {
                array_pop($item);
                $blankBefore = true;
            }
            $items[] = $item;
            if ($blankBefore && $i < $n && $this->listMarker($lines[$i]) === null) {
                break;
            }
        }

        $tag = $first['type'];
        $start = $tag === 'ol' && $first['start'] !== null && $first['start'] !== 1 ? ' start="' . $first['start'] . '"' : '';
        $html = "<{$tag}{$start}>\n";
        foreach ($items as $item) {
            $task = '';
            if (preg_match('/^\[([ xX])\][ ]+/', $item[0], $t) === 1) {
                $done = $t[1] !== ' ';
                $item[0] = substr($item[0], strlen($t[0]));
                $task = '<span class="task-checkbox' . ($done ? ' is-done' : '') . '">' . ($done ? '☑' : '☐') . '</span> ';
            }
            $body = $this->blocks($item, tight: !$loose);
            $class = $task !== '' ? ' class="task-list-item"' : '';
            $html .= "<li{$class}>" . $task . $body . "</li>\n";
        }

        return [$html . "</{$tag}>", $i];
    }

    /** @param list<string> $item */
    private function onlyNestedList(array $item): bool
    {
        $last = '';
        foreach (array_reverse($item) as $line) {
            if (trim($line) !== '') {
                $last = $line;
                break;
            }
        }

        return $this->listMarker(ltrim($last)) !== null;
    }

    private function startsBlock(string $line): bool
    {
        return preg_match('/^ {0,3}(#{1,6}([ ]|$)|>|`{3,}|~{3,}|([-*_])(?:[ ]*\3){2,}[ ]*$)/', $line) === 1
            || $this->listMarker($line) !== null;
    }

    /** @return list<string> */
    private function cells(string $line): array
    {
        $line = trim($line);
        $line = preg_replace('/^\||(?<!\\\\)\|$/', '', $line) ?? $line;
        $parts = preg_split('/(?<!\\\\)\|/', $line) ?: [];

        return array_map(static fn(string $c) => str_replace('\\|', '|', $c), $parts);
    }

    private function collectReferences(string $text): string
    {
        return (string) preg_replace_callback(
            '/^ {0,3}\[([^\]\n]{1,999})\]:[ ]*<?([^\s>]+)>?(?:[ ]+(?:"([^"\n]*)"|\'([^\'\n]*)\'|\(([^)\n]*)\)))?[ ]*$/m',
            function (array $m): string {
                $label = mb_strtolower(trim((string) preg_replace('/\s+/', ' ', $m[1])));
                $title = ($m[3] ?? '') !== '' ? $m[3] : (($m[4] ?? '') !== '' ? $m[4] : (($m[5] ?? '') !== '' ? $m[5] : null));
                $this->refs[$label] ??= ['url' => $m[2], 'title' => $title];
                return '';
            },
            $text,
        );
    }

    // ── inlines ──────────────────────────────────────────────────

    private function inline(string $text): string
    {
        // Code spans and backslash escapes become placeholders first, so nothing else touches them.
        $text = (string) preg_replace_callback('/(`+)(?!`)(.+?)(?<!`)\1(?!`)/s', function (array $m): string {
            $code = $m[2];
            if (strlen($code) > 2 && $code[0] === ' ' && str_ends_with($code, ' ') && trim($code) !== '') {
                $code = substr($code, 1, -1);
            }
            return $this->slot('<code>' . Html::escape(str_replace("\n", ' ', $code)) . '</code>');
        }, $text);
        $text = (string) preg_replace_callback('/\\\\([!-\/:-@\[-`{-~])/', fn(array $m) => $this->slot(Html::escape($m[1])), $text);

        // Autolinks <https://…> and <mail@x>
        $text = (string) preg_replace_callback('/<((?:https?|ftp):\/\/[^\s<>]+|[\w.+-]+@[\w-]+(?:\.[\w-]+)+)>/i', function (array $m): string {
            $href = str_contains($m[1], '://') ? $m[1] : 'mailto:' . $m[1];
            return $this->slot('<a href="' . Html::escape($href) . '">' . Html::escape($m[1]) . '</a>');
        }, $text);

        // Images and links (inline, then reference / collapsed / shortcut)
        $text = $this->links($text);

        $text = Html::escape($text, false);

        // Bare URLs (GFM autolink extension)
        $text = (string) preg_replace_callback('/(?<![\w\/"=])(https?:\/\/[^\s<]*[^\s<.,:;"\')\]!?*_~])/i', fn(array $m) => $this->slot('<a href="' . $m[1] . '">' . $m[1] . '</a>'), $text);

        // Emphasis (strongest first). Underscores only at word boundaries.
        $text = (string) preg_replace('/\*\*\*(?=\S)(.+?)(?<=\S)\*\*\*/s', '<strong><em>$1</em></strong>', $text);
        $text = (string) preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/s', '<strong>$1</strong>', $text);
        $text = (string) preg_replace('/(?<![\w_])__(?=\S)(.+?)(?<=\S)__(?![\w_])/s', '<strong>$1</strong>', $text);
        $text = (string) preg_replace('/(?<![*\w])\*(?=[^\s*])(.+?)(?<=[^\s*])\*(?![*])/s', '<em>$1</em>', $text);
        $text = (string) preg_replace('/(?<![\w_])_(?=[^\s_])(.+?)(?<=[^\s_])_(?![\w_])/s', '<em>$1</em>', $text);
        $text = (string) preg_replace('/~~(?=\S)(.+?)(?<=\S)~~/s', '<del>$1</del>', $text);

        // Hard breaks: two trailing spaces or a backslash (already a slot) before the newline.
        $text = (string) preg_replace('/ {2,}\n/', "<br>\n", $text);

        return $this->restore($text);
    }

    private function links(string $text): string
    {
        $pattern = '/(!?)\[((?:[^\[\]]|\[[^\[\]]*\])*)\](?:\(\s*<?([^\s()<>]*(?:\([^\s()]*\)[^\s()<>]*)*)>?(?:\s+(?:"([^"]*)"|\'([^\']*)\'))?\s*\)|\[([^\]]*)\])?/';

        return (string) preg_replace_callback($pattern, function (array $m): string {
            $isImage = $m[1] === '!';
            $label = $m[2];
            if (isset($m[3]) && ($m[3] !== '' || str_contains($m[0], ']('))) {
                $url = $m[3];
                $title = ($m[4] ?? '') !== '' ? $m[4] : (($m[5] ?? '') !== '' ? $m[5] : null);
            } else {
                $key = mb_strtolower(trim((string) preg_replace('/\s+/', ' ', ($m[6] ?? '') !== '' ? $m[6] : $label)));
                $ref = $this->refs[$key] ?? null;
                if ($ref === null) {
                    return $m[0]; // not a link: leave the brackets as text
                }
                ['url' => $url, 'title' => $title] = $ref;
            }
            $titleAttr = $title !== null ? ' title="' . Html::escape($title) . '"' : '';
            if ($isImage) {
                return $this->slot('<img src="' . Html::escape($url) . '" alt="' . Html::escape(Html::toText($this->inline($label))) . '"' . $titleAttr . '>');
            }

            return $this->slot('<a href="' . Html::escape($url) . '"' . $titleAttr . '>' . $this->inline($label) . '</a>');
        }, $text);
    }

    private function slot(string $html): string
    {
        $this->slots[] = $html;

        return "\u{E000}" . (count($this->slots) - 1) . "\u{E001}";
    }

    private function restore(string $text): string
    {
        for ($pass = 0; $pass < 5 && str_contains($text, "\u{E000}"); $pass++) {
            $text = (string) preg_replace_callback("/\u{E000}(\\d+)\u{E001}/u", fn(array $m) => $this->slots[(int) $m[1]] ?? '', $text);
        }

        return $text;
    }
}
