<?php

declare(strict_types=1);

namespace Kestello\Cms\Import\WordPress;

use Dom\Comment;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use Dom\Text;

/**
 * Small helpers over PHP's HTML5 parser, so post HTML is read the way a
 * browser reads it (unclosed <p>, stray tags and all).
 *
 * @internal
 */
final class Fragment
{
    /** The parsed fragment's container: its children are the fragment's top-level nodes. */
    public static function parse(string $html): Element
    {
        $doc = HTMLDocument::createFromString('<!DOCTYPE html><html><body>' . $html . '</body></html>', LIBXML_NOERROR, 'UTF-8');

        return $doc->body ?? $doc->createElement('body');
    }

    public static function inner(Element $element): string
    {
        return $element->innerHTML;
    }

    public static function outer(Node $node): string
    {
        $doc = $node->ownerDocument;

        return $doc instanceof HTMLDocument ? $doc->saveHtml($node) : '';
    }

    /**
     * Child nodes that matter: elements and text that is not only whitespace.
     *
     * @return list<Node>
     */
    public static function significant(Node $node): array
    {
        $out = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof Element || ($child instanceof Text && trim($child->textContent, " \t\n\r\x0B\u{a0}") !== '') || ($child instanceof Comment && Placeholders::is($child))) {
                $out[] = $child;
            }
        }

        return $out;
    }

    /**
     * @return list<Element>
     */
    public static function elements(Node $node, ?string $name = null): array
    {
        $out = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof Element && ($name === null || $child->localName === $name)) {
                $out[] = $child;
            }
        }

        return $out;
    }

    /** The first descendant with this tag name, or null. */
    public static function find(Node $node, string $name): ?Element
    {
        return $node instanceof Element || $node instanceof HTMLDocument ? $node->querySelector($name) : null;
    }

    /** True when the element holds text only: no tags inside (comments ignored). */
    public static function isPlain(Element $element): bool
    {
        foreach ($element->childNodes as $child) {
            if ($child instanceof Element) {
                return false;
            }
        }

        return true;
    }

    /** Text content with runs of whitespace collapsed to one space. */
    public static function text(Node $node): string
    {
        return trim((string) preg_replace('/[\s\x{a0}]+/u', ' ', (string) $node->textContent));
    }
}
