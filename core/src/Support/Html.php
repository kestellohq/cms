<?php

declare(strict_types=1);

namespace Kestello\Support;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use InvalidArgumentException;

/**
 * HTML helpers and an allowlist sanitizer.
 *
 *   Html::escape($userText)
 *   Html::attributes(['class' => ['btn', 'active' => $on], 'disabled' => true, 'data-id' => 5])
 *   Html::sanitize($commentHtml, 'basic')        // safe to output
 *   Html::sanitize($svg, 'svg')                  // for SVG uploads
 *   Html::toText($html)                          // plain text with line breaks
 *
 * The sanitizer parses with the HTML5 algorithm browsers use (Dom\HTMLDocument),
 * so it sees the same elements a browser will: libxml's older HTML4 parser
 * read some markup differently, which is how sanitizer bypasses work. It
 * keeps only allowlisted elements and
 * attributes, checks every URL's scheme, and drops dangerous elements with
 * their content (script, style, iframe, form controls…). Unknown elements are
 * unwrapped (their text kept). Plugins can add profiles with Html::profile().
 *
 * @api
 */
final class Html
{
    /** Removed together with everything inside them. */
    private const array DROP_WITH_CONTENT = [
        'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'template', 'noscript',
        'noembed', 'noframes', 'math', 'form', 'input', 'button', 'select', 'option', 'textarea', 'link', 'meta',
        'base', 'title', 'head', 'xmp', 'plaintext', 'listing', 'canvas', 'dialog', 'portal', 'svg', 'audio', 'video',
        'foreignobject', 'animate', 'animatemotion', 'animatetransform', 'set', 'handler', 'iframe',
    ];

    private const array GLOBAL_ATTRIBUTES = ['title', 'lang', 'dir'];

    private const array URL_ATTRIBUTES = ['href', 'src', 'cite', 'action', 'formaction', 'poster', 'background', 'xlink:href', 'srcset'];

    /** @var array<string, array{elements: array<string, list<string>>, link_schemes: list<string>, image_schemes: list<string>, classes: bool, nofollow: bool}> */
    private static array $profiles = [];

    // ── Escaping and building ───────────────────────────────────────

    public static function escape(?string $value, bool $doubleEncode = true): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', $doubleEncode);
    }

    /**
     * Build an attribute string (leading space included when non-empty).
     * true → bare attribute, false/null → omitted, "class" accepts a list or
     * [class => condition] map, arrays elsewhere become JSON. Event handler
     * attributes (on*) are refused: attach behaviour in JavaScript.
     *
     * @param array<string, mixed> $attributes
     */
    public static function attributes(array $attributes): string
    {
        $out = '';
        foreach ($attributes as $name => $value) {
            $name = strtolower((string) $name);
            if (preg_match('/^[a-z_:][a-z0-9_.:-]*$/', $name) !== 1) {
                throw new InvalidArgumentException("Invalid attribute name \"{$name}\".");
            }
            if (str_starts_with($name, 'on')) {
                throw new InvalidArgumentException("Event handler attribute \"{$name}\" is not allowed; bind events in JavaScript.");
            }
            if ($value === null || $value === false) {
                continue;
            }
            if ($value === true) {
                $out .= ' ' . $name;
                continue;
            }
            if ($name === 'class' && is_array($value)) {
                $classes = [];
                foreach ($value as $k => $v) {
                    if (is_int($k)) {
                        $classes[] = (string) $v;
                    } elseif ($v) {
                        $classes[] = (string) $k;
                    }
                }
                $value = implode(' ', array_filter($classes, static fn($c) => $c !== ''));
                if ($value === '') {
                    continue;
                }
            } elseif (is_array($value)) {
                $value = Json::encode($value);
            }
            $out .= ' ' . $name . '="' . self::escape((string) $value) . '"';
        }

        return $out;
    }

    /** Opening tag, content (escaped unless $raw) and closing tag. */
    public static function tag(string $name, array $attributes = [], ?string $content = null, bool $raw = false): string
    {
        $name = strtolower($name);
        if (preg_match('/^[a-z][a-z0-9-]*$/', $name) !== 1) {
            throw new InvalidArgumentException("Invalid tag name \"{$name}\".");
        }
        $void = in_array($name, ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr'], true);
        $open = '<' . $name . self::attributes($attributes) . '>';

        return $void ? $open : $open . ($raw ? (string) $content : self::escape($content)) . '</' . $name . '>';
    }

    // ── Text ────────────────────────────────────────────────────────

    /** Remove all tags (and script/style contents). */
    public static function stripTags(string $html): string
    {
        $html = preg_replace('#<(script|style|template|noscript)\b[^>]*>.*?</\1\s*>#is', '', $html) ?? '';

        return strip_tags($html);
    }

    /** Plain text: tags removed, entities decoded, block elements become line breaks. */
    public static function toText(string $html): string
    {
        if (!str_contains($html, '<') && !str_contains($html, '&')) {
            return $html;
        }
        $html = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
        $html = preg_replace('#</(p|div|h[1-6]|li|tr|blockquote|pre|section|article|header|footer|table|ul|ol|figure)\s*>#i', "\n", $html) ?? $html;
        $text = html_entity_decode(self::stripTags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t\x{00A0}]+/u", ' ', $text) ?? $text;
        $text = preg_replace("/ *\n */", "\n", $text) ?? $text;

        return trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);
    }

    // ── Sanitizer ───────────────────────────────────────────────────

    /**
     * Register or replace a sanitizer profile.
     *
     * @param array{elements: array<string, list<string>>, link_schemes?: list<string>, image_schemes?: list<string>, classes?: bool, nofollow?: bool} $config
     *        elements: tag => allowed attributes
     */
    public static function profile(string $name, array $config): void
    {
        self::$profiles[$name] = [
            'elements' => array_change_key_case($config['elements'], CASE_LOWER),
            'link_schemes' => $config['link_schemes'] ?? ['http', 'https', 'mailto', 'tel'],
            'image_schemes' => $config['image_schemes'] ?? ['http', 'https'],
            'classes' => $config['classes'] ?? false,
            'nofollow' => $config['nofollow'] ?? false,
        ];
    }

    /** @param string $profile "basic", "rich-text", "svg" or a registered profile */
    public static function sanitize(string $html, string $profile = 'rich-text'): string
    {
        if ($profile === 'svg') {
            return Svg::sanitize($html);
        }

        self::defaultProfiles();
        $config = self::$profiles[$profile] ?? throw new InvalidArgumentException("Unknown sanitizer profile \"{$profile}\".");

        if (trim($html) === '') {
            return '';
        }
        if (!mb_check_encoding($html, 'UTF-8')) {
            $html = mb_convert_encoding($html, 'UTF-8', 'UTF-8');
        }

        // parsed as the content of a <div>, the way a browser parses it where it ends up
        $doc = HTMLDocument::createEmpty();
        $root = $doc->createElement('div');
        $doc->appendChild($root);
        $root->innerHTML = $html;

        self::clean($root, $config);

        return trim($root->innerHTML);
    }

    /** @param array<string, mixed> $config */
    private static function clean(Node $node, array $config): void
    {
        // Iterate over a snapshot: we remove and unwrap while walking.
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                continue;
            }
            if (!$child instanceof Element) {
                $node->removeChild($child); // comments, processing instructions, entities
                continue;
            }

            $tag = strtolower($child->localName);

            // only HTML elements are allowed: an <svg> or <math> one (by name a <style>, an <a>)
            // follows other parsing rules, and is dropped with what it holds
            if ($child->namespaceURI !== 'http://www.w3.org/1999/xhtml') {
                $node->removeChild($child);
                continue;
            }

            if (in_array($tag, self::DROP_WITH_CONTENT, true) && !isset($config['elements'][$tag])) {
                $node->removeChild($child);
                continue;
            }

            if (!isset($config['elements'][$tag])) {
                self::clean($child, $config);
                while ($child->firstChild !== null) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }

            self::cleanAttributes($child, $tag, $config);
            self::clean($child, $config);
        }
    }

    /** @param array<string, mixed> $config */
    private static function cleanAttributes(Element $el, string $tag, array $config): void
    {
        $allowed = [...self::GLOBAL_ATTRIBUTES, ...$config['elements'][$tag]];
        if ($config['classes']) {
            $allowed[] = 'class';
        }

        foreach (iterator_to_array($el->attributes) as $attr) {
            $name = strtolower($attr->name);
            $value = $attr->value;

            $keep = in_array($name, $allowed, true) && !str_starts_with($name, 'on');
            if ($keep && in_array($name, self::URL_ATTRIBUTES, true)) {
                $schemes = in_array($name, ['src', 'srcset', 'poster'], true) ? $config['image_schemes'] : $config['link_schemes'];
                $keep = $name === 'srcset' ? self::safeSrcset($value, $schemes) : self::safeUrl($value, $schemes);
            }
            if ($keep && $name === 'target') {
                $keep = $value === '_blank';
            }
            if ($keep && $name === 'class') {
                $value = trim(preg_replace('/[^A-Za-z0-9_ -]/', '', $value) ?? '');
                $el->setAttribute('class', $value);
                $keep = $value !== '';
            }
            if ($keep && in_array($name, ['width', 'height', 'colspan', 'rowspan', 'start'], true)) {
                $keep = preg_match('/^\d{1,5}%?$/', trim($value)) === 1;
            }

            if (!$keep) {
                $el->removeAttributeNode($attr);
            }
        }

        if ($tag === 'a' && $el->hasAttribute('href')) {
            $rel = [];
            if ($el->getAttribute('target') === '_blank') {
                $rel = ['noopener', 'noreferrer'];
            }
            if ($config['nofollow'] && preg_match('#^(https?:)?//#i', $el->getAttribute('href')) === 1) {
                $rel = [...$rel, 'nofollow', 'ugc'];
            }
            $rel === [] ? $el->removeAttribute('rel') : $el->setAttribute('rel', implode(' ', array_unique($rel)));
        }
    }

    /** @param list<string> $schemes */
    public static function safeUrl(string $url, array $schemes = ['http', 'https', 'mailto', 'tel']): bool
    {
        // Browsers ignore control characters and whitespace inside schemes ("java\tscript:").
        $normalized = preg_replace('/[\x00-\x20\x7F]+/', '', html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '';
        if ($normalized === '') {
            return false;
        }
        if (preg_match('/^([a-z][a-z0-9+.-]*):/i', $normalized, $m) === 1) {
            return in_array(strtolower($m[1]), $schemes, true);
        }

        // Relative URL: "/path", "page", "#anchor", "?q", or protocol-relative "//host" (treated as http(s)).
        // No backslash: browsers read /\host as //host, another site.
        return !str_contains($normalized, '\\');
    }

    /** @param list<string> $schemes */
    private static function safeSrcset(string $srcset, array $schemes): bool
    {
        foreach (explode(',', $srcset) as $candidate) {
            $url = strtok(trim($candidate), " \t\n");
            if ($url === false || !self::safeUrl($url, $schemes)) {
                return false;
            }
        }

        return true;
    }

    private static function defaultProfiles(): void
    {
        if (isset(self::$profiles['basic'])) {
            return;
        }

        $basic = [
            'p' => [], 'br' => [], 'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 's' => [], 'del' => [],
            'code' => [], 'blockquote' => ['cite'], 'ul' => [], 'ol' => ['start'], 'li' => [], 'a' => ['href', 'target', 'rel'],
        ];
        self::profile('basic', ['elements' => $basic, 'nofollow' => true]);

        self::profile('rich-text', [
            'elements' => $basic + [
                'h1' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => [], 'hr' => [], 'pre' => [],
                'span' => [], 'div' => [], 'sub' => [], 'sup' => [], 'mark' => [], 'small' => [], 'ins' => [], 'abbr' => [],
                'cite' => [], 'q' => ['cite'], 'kbd' => [], 'samp' => [], 'var' => [], 'time' => ['datetime'],
                'figure' => [], 'figcaption' => [], 'img' => ['src', 'srcset', 'sizes', 'alt', 'width', 'height', 'loading'],
                'picture' => [], 'source' => ['srcset', 'type', 'media', 'sizes'],
                'table' => [], 'caption' => [], 'thead' => [], 'tbody' => [], 'tfoot' => [], 'tr' => [],
                'th' => ['colspan', 'rowspan', 'scope'], 'td' => ['colspan', 'rowspan'], 'col' => [], 'colgroup' => [],
                'dl' => [], 'dt' => [], 'dd' => [], 'details' => [], 'summary' => [],
            ],
            'classes' => true,
        ]);
    }
}
