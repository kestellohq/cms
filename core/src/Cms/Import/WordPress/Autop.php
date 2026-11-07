<?php

declare(strict_types=1);

namespace Kestello\Cms\Import\WordPress;

/**
 * WordPress's wpautop(): turns the blank lines of classic content into
 * paragraphs and single line breaks into <br>, exactly where WordPress would
 * have shown them, leaving block-level HTML (lists, tables, headings, …) alone.
 *
 * Content written in the classic editor is stored without <p> tags; WordPress
 * adds them every time the post is shown. Imported, the content has to carry them.
 */
final class Autop
{
    private const string BLOCKS = '(?:table|thead|tfoot|caption|col|colgroup|tbody|tr|td|th|div|dl|dd|dt|ul|ol|li|pre|form|map|area|blockquote|address|math|style|p|h[1-6]|hr|fieldset|legend|section|article|aside|hgroup|header|footer|nav|figure|figcaption|details|menu|summary)';

    /**
     * The text with paragraphs and line breaks as WordPress shows it. Text
     * too large or odd for the patterns comes back as it was, never emptied.
     */
    public static function apply(string $text): string
    {
        try {
            return self::paragraphs($text);
        } catch (\RuntimeException) {
            return $text;
        }
    }

    private static function paragraphs(string $text): string
    {
        if (trim($text) === '') {
            return '';
        }
        $text .= "\n";

        // <pre> keeps its line breaks exactly: set it aside
        $pre = [];
        $text = self::checked(preg_replace_callback('#<pre[^>]*>.*?</pre>#is', static function (array $m) use (&$pre): string {
            $key = '<pre wp-pre-tag-' . count($pre) . '></pre>';
            $pre[$key] = $m[0];
            return $key;
        }, $text));

        $text = self::checked(preg_replace('|<br\s*/?>\s*<br\s*/?>|', "\n\n", $text));
        $text = self::checked(preg_replace('!(<' . self::BLOCKS . '[\s/>])!', "\n\n$1", $text));
        $text = self::checked(preg_replace('!(</' . self::BLOCKS . '>)!', "$1\n\n", $text));
        $text = self::checked(preg_replace('!(<hr\s*?/?>)!', "$1\n\n", $text));
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // line breaks inside a tag are not line breaks of the text
        $text = self::checked(preg_replace_callback('/<[^>]*>/', static fn(array $m): string => str_replace("\n", ' ', $m[0]), $text));

        // keep <option>, <object> and media sources together, as WordPress does
        if (str_contains($text, '<option')) {
            $text = self::checked(preg_replace('|\s*<option|', '<option', $text));
            $text = self::checked(preg_replace('|</option>\s*|', '</option>', $text));
        }
        $text = self::checked(preg_replace('%\s*(<(?:source|track)[^>]*>)\s*%', '$1', $text));

        $text = self::checked(preg_replace("/\n\n+/", "\n\n", $text));
        $out = '';
        foreach (preg_split('/\n\s*\n/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $piece) {
            if (trim($piece) !== '') {
                $out .= '<p>' . trim($piece, "\n") . "</p>\n";
            }
        }
        $text = $out;

        $text = self::checked(preg_replace('|<p>\s*</p>|', '', $text));
        $text = self::checked(preg_replace('!<p>([^<]+)</(div|address|form)>!', '<p>$1</p></$2>', $text));
        $text = self::checked(preg_replace('!<p>\s*(</?' . self::BLOCKS . '[^>]*>)\s*</p>!', '$1', $text));
        $text = self::checked(preg_replace('|<p>(<li.+?)</p>|', '$1', $text));
        $text = self::checked(preg_replace('|<p><blockquote([^>]*)>|i', '<blockquote$1><p>', $text));
        $text = str_replace('</blockquote></p>', '</p></blockquote>', $text);
        $text = self::checked(preg_replace('!<p>\s*(</?' . self::BLOCKS . '[^>]*>)!', '$1', $text));
        $text = self::checked(preg_replace('!(</?' . self::BLOCKS . '[^>]*>)\s*</p>!', '$1', $text));

        // single line breaks, except right after a block tag
        $text = self::checked(preg_replace('|(?<!<br />)\s*\n|', "<br />\n", $text));
        $text = self::checked(preg_replace('!(</?' . self::BLOCKS . '[^>]*>)\s*<br />!', '$1', $text));
        $text = self::checked(preg_replace('!<br />(\s*</?(?:p|li|div|dl|dd|dt|th|pre|td|ul|ol)[^>]*>)!', '$1', $text));
        $text = self::checked(preg_replace("|\n</p>$|", '</p>', $text));

        $text = strtr($text, $pre);
        // a trailing <br /> after the last paragraph is an artefact of the added newline
        return trim(self::checked(preg_replace('|<br />\s*$|', '', $text)));
    }

    /** A pattern's result; a pattern that gave up (backtracking or size limits) stops the conversion. */
    private static function checked(?string $result): string
    {
        return $result ?? throw new \RuntimeException('A pattern failed: ' . preg_last_error_msg());
    }
}
