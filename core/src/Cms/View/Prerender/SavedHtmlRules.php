<?php

declare(strict_types=1);

namespace Kestello\Cms\View\Prerender;

use Kestello\Support\Html;

/**
 * What saved page HTML may hold (PrerenderStore::refuse): the elements and
 * attributes Vue draws for pages, nothing that runs, loads or redirects on
 * its own. One element at a time, so each rule is easy to test and to read.
 *
 * @internal
 */
final class SavedHtmlRules
{
    /** HTML elements pages and blocks draw. */
    public const array HTML = [
        'a', 'abbr', 'address', 'article', 'aside', 'audio', 'b', 'bdi', 'bdo', 'blockquote', 'br', 'button',
        'caption', 'cite', 'code', 'col', 'colgroup', 'data', 'dd', 'del', 'details', 'dfn', 'dialog', 'div',
        'dl', 'dt', 'em', 'fieldset', 'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5',
        'h6', 'header', 'hr', 'i', 'iframe', 'img', 'input', 'ins', 'kbd', 'label', 'legend', 'li', 'main',
        'mark', 'nav', 'ol', 'optgroup', 'option', 'output', 'p', 'picture', 'pre', 'q', 's', 'samp', 'section',
        'select', 'small', 'source', 'span', 'strike', 'strong', 'sub', 'summary', 'sup', 'table', 'tbody', 'td',
        'textarea', 'tfoot', 'th', 'thead', 'time', 'tr', 'track', 'u', 'ul', 'var', 'video', 'wbr',
    ];

    /** SVG elements icons are drawn with (no animation, no scripts, no foreign content). */
    public const array SVG = [
        'svg', 'g', 'path', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'rect', 'defs', 'title', 'desc',
        'use', 'symbol', 'clipPath', 'mask', 'linearGradient', 'radialGradient', 'stop',
    ];

    /** The video players the embed block makes (CoreBlocks::embed). */
    public const array PLAYERS = ['https://www.youtube-nocookie.com/embed/', 'https://player.vimeo.com/video/'];

    /** Attributes holding an address. */
    private const array URLS = ['href', 'src', 'action', 'poster', 'cite', 'background', 'xlink:href'];

    /** Why this element is refused, or null. */
    public static function element(\Dom\Element $el): ?string
    {
        $name = $el->localName;
        $svg = $el->namespaceURI === 'http://www.w3.org/2000/svg';
        if ($svg ? !in_array($name, self::SVG, true) : ($el->namespaceURI !== 'http://www.w3.org/1999/xhtml' || !in_array($name, self::HTML, true))) {
            return "The HTML holds a <{$name}> element, which pages never draw.";
        }
        foreach ($el->attributes as $attr) {
            $problem = self::attribute($name, strtolower($attr->name), $attr->value, $svg);
            if ($problem !== null) {
                return $problem;
            }
        }
        if ($name === 'iframe' && !self::isPlayer($el->getAttribute('src') ?? '')) {
            return 'The HTML holds a frame that is not a YouTube or Vimeo player.';
        }

        return null;
    }

    /** Why this attribute is refused, or null. */
    public static function attribute(string $element, string $name, string $value, bool $svg = false): ?string
    {
        if (str_starts_with($name, 'on') || in_array($name, ['srcdoc', 'formaction'], true)) {
            return "The HTML holds a \"{$name}\" attribute.";
        }
        if ($name === 'id' && ($value === 'app' || str_starts_with($value, 'kestello-'))) {
            return "The HTML holds the id \"{$value}\", which the page itself uses.";
        }
        if (in_array($name, self::URLS, true)) {
            if ($svg && $element === 'use' && !str_starts_with($value, '#')) {
                return 'The HTML holds an icon that points outside the page.';
            }
            if ($element === 'form' && $name === 'action' && !self::isLocalPath($value)) {
                return 'The HTML holds a form that posts to another site.';
            }
            if (!Html::safeUrl($value)) {
                return "The HTML holds an unsafe address in \"{$name}\".";
            }
        }
        if ($name === 'srcset') {
            foreach (explode(',', $value) as $candidate) {
                $url = strtok(trim($candidate), " \t\n");
                if ($url !== false && !Html::safeUrl($url, ['http', 'https'])) {
                    return 'The HTML holds an unsafe address in "srcset".';
                }
            }
        }
        if ($name === 'style') {
            return self::style($value);
        }

        return null;
    }

    /** Why a style attribute is refused, or null: no script, imports or outside behaviours; images only from safe addresses. */
    public static function style(string $css): ?string
    {
        if (preg_match('/expression\s*\(|javascript\s*:|@import|behavior\s*:|-moz-binding/i', $css) === 1) {
            return 'The HTML holds a style that runs code.';
        }
        preg_match_all('/url\(\s*([\'"]?)(.*?)\1\s*\)/i', $css, $m);
        foreach ($m[2] as $url) {
            // a raster data image is the blurred placeholder behind a loading picture; it can't run anything
            if (preg_match('#^data:image/(?:png|jpeg|gif|webp|avif);base64,[A-Za-z0-9+/=]*$#', $url) === 1) {
                continue;
            }
            if (!Html::safeUrl($url, ['http', 'https'])) {
                return 'The HTML holds an unsafe address in a style.';
            }
        }

        return null;
    }

    public static function isPlayer(string $src): bool
    {
        foreach (self::PLAYERS as $prefix) {
            if (str_starts_with($src, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** "/search", never "//host" or "/\host". */
    private static function isLocalPath(string $url): bool
    {
        return $url === '' || (str_starts_with($url, '/') && !str_starts_with($url, '//') && !str_starts_with($url, '/\\'));
    }
}
