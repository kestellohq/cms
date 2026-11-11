<?php

declare(strict_types=1);

namespace Kestello\Framework\Mail;

/**
 * The plain-text part of an email that was written as HTML only: the words,
 * paragraph breaks and link addresses, with markup, styles and the
 * document head left out. Mail readers that show text, and spam filters
 * that compare the two parts, read this.
 *
 * @internal
 */
final class PlainText
{
    public static function fromHtml(string $html): string
    {
        // what is never read: the head (title, styles), scripts and comments
        $text = (string) preg_replace('~<(head|style|script|title)\b[^>]*>.*?</\1\s*>|<!--.*?-->~is', '', $html);
        // a link keeps its address, unless the address is its text
        $text = (string) preg_replace_callback(
            '~<a\s[^>]*?(?<![\w-])href\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a\s*>~is',
            static function (array $m): string {
                $href = trim(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $label = trim(html_entity_decode(strip_tags($m[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if (preg_match('~^(https?|mailto):~i', $href) !== 1) {
                    return $m[3];          // a fragment or a script: only the words mean something
                }

                return match ($label) {
                    '', $href => $m[2],
                    default => $m[3] . ' (' . $m[2] . ')',
                };
            },
            $text,
        );
        $text = (string) preg_replace('~<br\s*/?>~i', "\n", $text);
        $text = (string) preg_replace('~</t[hd]>\s*<td\b~i', ': <td', $text);
        $text = (string) preg_replace(['~<tr\b[^>]*>~i', '~</tr\s*>~i'], ['', "\n"], $text);
        $text = (string) preg_replace('~</?(p|div|h[1-6]|table|ul|ol|li|blockquote|section|article|header|footer|body|html)\b[^>]*>~i', "\n\n", $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $lines = [];
        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $text)) as $line) {
            $lines[] = trim((string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $line));
        }

        return trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));
    }
}
