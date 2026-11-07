<?php

declare(strict_types=1);

namespace Kestello\Support;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * SVG sanitizer for uploads and inline icons (used by Html::sanitize($svg, 'svg')).
 *
 * Parses as XML with no DTDs, entities or network access, keeps an
 * allowlist of shape/paint elements and presentation attributes, allows
 * only local references (href="#id"), and strips scripts, event handlers,
 * foreignObject, animation and external resources. Returns '' for input
 * that isn't a single well-formed <svg> document.
 *
 * @api
 */
final class Svg
{
    private const array ELEMENTS = [
        'svg', 'g', 'defs', 'symbol', 'use', 'title', 'desc', 'path', 'rect', 'circle', 'ellipse', 'line',
        'polyline', 'polygon', 'text', 'tspan', 'textpath', 'lineargradient', 'radialgradient', 'stop', 'clippath',
        'mask', 'pattern', 'marker', 'filter', 'fegaussianblur', 'feoffset', 'feblend', 'fecolormatrix', 'feflood',
        'fecomposite', 'femerge', 'femergenode', 'femorphology', 'fedropshadow',
    ];

    private const array ATTRIBUTES = [
        'id', 'class', 'viewbox', 'width', 'height', 'x', 'y', 'x1', 'x2', 'y1', 'y2', 'cx', 'cy', 'r', 'rx', 'ry',
        'd', 'points', 'transform', 'fill', 'fill-opacity', 'fill-rule', 'stroke', 'stroke-width', 'stroke-linecap',
        'stroke-linejoin', 'stroke-miterlimit', 'stroke-dasharray', 'stroke-dashoffset', 'stroke-opacity', 'opacity',
        'clip-path', 'clip-rule', 'mask', 'offset', 'stop-color', 'stop-opacity', 'gradientunits', 'gradienttransform',
        'spreadmethod', 'fx', 'fy', 'preserveaspectratio', 'xmlns', 'version', 'font-family', 'font-size',
        'font-weight', 'text-anchor', 'dominant-baseline', 'letter-spacing', 'dx', 'dy', 'role', 'aria-hidden',
        'aria-label', 'focusable', 'marker-start', 'marker-mid', 'marker-end', 'markerwidth', 'markerheight',
        'refx', 'refy', 'orient', 'patternunits', 'patterncontentunits', 'patterntransform', 'maskunits',
        'maskcontentunits', 'clippathunits', 'filter', 'stddeviation', 'in', 'in2', 'result', 'mode', 'values',
        'operator', 'flood-color', 'flood-opacity', 'radius', 'href', 'xlink:href', 'xmlns:xlink', 'visibility',
        'display', 'color', 'vector-effect', 'shape-rendering', 'paint-order', 'filterunits',
    ];

    /** Attributes that may reference other elements: url(#id) only. */
    private const array REFERENCE_ATTRIBUTES = ['fill', 'stroke', 'clip-path', 'mask', 'filter', 'marker-start', 'marker-mid', 'marker-end'];

    public static function sanitize(string $svg, int $maxBytes = 2_097_152): string
    {
        if ($svg === '' || strlen($svg) > $maxBytes) {
            return '';
        }
        // No DTDs at all: blocks entity expansion (billion laughs) and XXE.
        if (preg_match('/<!(DOCTYPE|ENTITY)/i', $svg) === 1) {
            return '';
        }

        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $doc->loadXML($svg, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $doc->documentElement;
        if (!$loaded || !$root instanceof DOMElement || strtolower($root->localName) !== 'svg') {
            return '';
        }

        self::clean($root);
        self::cleanAttributes($root);

        $out = $doc->saveXML($root);

        return $out === false ? '' : $out;
    }

    private static function clean(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                continue;
            }
            if (!$child instanceof DOMElement || !in_array(strtolower($child->localName), self::ELEMENTS, true)) {
                $node->removeChild($child); // script, style, foreignObject, animate, comments, PIs, CDATA…
                continue;
            }
            self::cleanAttributes($child);
            self::clean($child);
        }
    }

    private static function cleanAttributes(DOMElement $el): void
    {
        foreach (iterator_to_array($el->attributes) as $attr) {
            $name = strtolower($attr->nodeName);
            $value = trim((string) $attr->nodeValue);
            $keep = in_array($name, self::ATTRIBUTES, true);

            if ($keep && in_array($name, ['href', 'xlink:href'], true)) {
                $keep = preg_match('/^#[A-Za-z_][A-Za-z0-9_.:-]*$/', $value) === 1; // local references only
            }
            if ($keep && in_array($name, self::REFERENCE_ATTRIBUTES, true) && str_contains(strtolower($value), 'url(')) {
                $keep = preg_match('/^url\(\s*[\'"]?#[A-Za-z_][A-Za-z0-9_.:-]*[\'"]?\s*\)$/i', $value) === 1;
            }
            // whitespace and control characters can hide a scheme ("java\tscript:")
            $squashed = strtolower((string) preg_replace('/[\x00-\x20\x7f]+/', '', $value));
            if ($keep && preg_match('/(javascript|vbscript|data|file):|expression\(/', $squashed) === 1) {
                $keep = false;
            }

            if (!$keep) {
                $el->removeAttributeNode($attr);
            }
        }

        // Namespaced attributes (xlink:*, ev:*, etc.) not covered above.
        foreach (iterator_to_array($el->attributes) as $attr) {
            if ($attr->prefix !== '' && $attr->prefix !== null && !in_array(strtolower($attr->nodeName), ['xlink:href', 'xmlns:xlink'], true)) {
                $el->removeAttributeNode($attr);
            }
        }
    }

    /**
     * Width and height an SVG states (attributes in px or unitless, else the
     * viewBox), or null.
     *
     * @return array{width: ?int, height: ?int}
     */
    public static function dimensions(string $svg): array
    {
        $none = ['width' => null, 'height' => null];
        if (preg_match('/<svg\b[^>]*>/is', $svg, $tag) !== 1) {
            return $none;
        }
        $attr = static fn(string $n): string => preg_match('/\s' . $n . '\s*=\s*["\']([^"\']*)["\']/i', $tag[0], $m) === 1 ? $m[1] : '';
        $num = static fn(string $v): ?float => preg_match('/^\s*(\d+(?:\.\d+)?)\s*(px)?\s*$/', $v, $m) === 1 ? (float) $m[1] : null;
        $w = $num($attr('width'));
        $h = $num($attr('height'));
        if (($w === null || $h === null) && preg_match('/^\s*[-\d.]+[\s,]+[-\d.]+[\s,]+(\d+(?:\.\d+)?)[\s,]+(\d+(?:\.\d+)?)\s*$/', $attr('viewBox'), $m) === 1) {
            $ratio = (float) $m[2] > 0 ? (float) $m[1] / (float) $m[2] : 1.0;
            if ($w === null && $h === null) {
                [$w, $h] = [(float) $m[1], (float) $m[2]];
            } elseif ($w === null) {
                $w = $h * $ratio;
            } else {
                $h = $w / $ratio;
            }
        }
        $ok = static fn(?float $v): ?int => $v !== null && $v >= 1 && $v <= 100000 ? (int) round($v) : null;

        return ['width' => $ok($w), 'height' => $ok($h)];
    }
}
