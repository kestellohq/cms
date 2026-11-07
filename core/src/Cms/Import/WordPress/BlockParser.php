<?php

declare(strict_types=1);

namespace Kestello\Cms\Import\WordPress;

/**
 * Splits saved WordPress content into blocks, the way WordPress's own parser
 * (WP_Block_Parser) does, so a post comes out as WordPress itself sees it.
 *
 * A block is:
 *   name          'core/paragraph', 'jetpack/contact-form', … or null for HTML between blocks
 *   attrs         the JSON from the opening comment ([] when missing or broken)
 *   innerBlocks   nested blocks
 *   innerHTML     the block's own HTML, without its inner blocks
 *   innerContent  innerHTML split around inner blocks: strings, with null where each inner block sits
 *
 * Content written before blocks existed (no block comments) comes back as one
 * block with a null name, like WordPress's "classic" content. Unclosed blocks
 * are closed at the end of the content, as WordPress does.
 */
final class BlockParser
{
    private const string TOKEN = '/<!--\s+(?P<closer>\/)?wp:(?P<namespace>[a-z][a-z0-9_-]*\/)?(?P<name>[a-z][a-z0-9_-]*)\s+(?P<attrs>{(?:(?:[^}]+|}+(?=})|(?!}\s+\/?-->).)*+)?}\s+)?(?P<void>\/)?-->/s';

    private string $document = '';
    private int $offset = 0;
    /** @var list<array<string, mixed>> */
    private array $output = [];
    /** @var list<array{block: array<string, mixed>, start: int, length: int, prev: int, leading: ?int}> */
    private array $stack = [];

    /**
     * @return list<array{name: ?string, attrs: array<string, mixed>, innerBlocks: list<array<string, mixed>>, innerHTML: string, innerContent: list<?string>}>
     */
    public static function parse(string $content): array
    {
        $parser = new self();
        $parser->document = $content;
        while ($parser->proceed()) {
            // one token at a time
        }

        /** @var list<array{name: ?string, attrs: array<string, mixed>, innerBlocks: list<array<string, mixed>>, innerHTML: string, innerContent: list<?string>}> */
        return $parser->output;
    }

    /** True when the content uses block comments at all. */
    public static function hasBlocks(string $content): bool
    {
        return str_contains($content, '<!-- wp:');
    }

    /**
     * @param array<string, mixed> $attrs
     * @param list<array<string, mixed>> $inner
     * @param list<?string> $content
     * @return array{name: ?string, attrs: array<string, mixed>, innerBlocks: list<array<string, mixed>>, innerHTML: string, innerContent: list<?string>}
     */
    private static function block(?string $name, array $attrs = [], array $inner = [], string $html = '', array $content = []): array
    {
        return ['name' => $name, 'attrs' => $attrs, 'innerBlocks' => $inner, 'innerHTML' => $html, 'innerContent' => $content];
    }

    private function proceed(): bool
    {
        [$type, $name, $attrs, $start, $length] = $this->nextToken();
        $depth = count($this->stack);

        switch ($type) {
            case 'none':
                if ($depth === 0) {
                    $this->addFreeform();
                    return false;
                }
                while ($this->stack !== []) {
                    $this->addBlockFromStack();
                }
                return false;

            case 'void':
                $block = self::block($name, $attrs);
                if ($depth === 0) {
                    if ($start > $this->offset) {
                        $this->output[] = self::freeform(substr($this->document, $this->offset, $start - $this->offset));
                    }
                    $this->output[] = $block;
                    $this->offset = $start + $length;
                    return true;
                }
                $this->addInnerBlock($block, $start, $length);
                $this->offset = $start + $length;
                return true;

            case 'opener':
                $this->stack[] = ['block' => self::block($name, $attrs), 'start' => $start, 'length' => $length, 'prev' => $start + $length, 'leading' => $start > $this->offset ? $this->offset : null];
                $this->offset = $start + $length;
                return true;

            default: // closer
                if ($depth === 0) {
                    // a closer with nothing open: keep the rest as HTML
                    $this->addFreeform();
                    return false;
                }
                if ($depth === 1) {
                    $this->addBlockFromStack($start);
                    $this->offset = $start + $length;
                    return true;
                }
                $top = array_pop($this->stack);
                $html = substr($this->document, $top['prev'], $start - $top['prev']);
                $top['block']['innerHTML'] .= $html;
                $top['block']['innerContent'][] = $html;
                $top['prev'] = $start + $length;
                $this->addInnerBlock($top['block'], $top['start'], $top['length'], $start + $length);
                $this->offset = $start + $length;
                return true;
        }
    }

    /**
     * @return array{0: string, 1: ?string, 2: array<string, mixed>, 3: int, 4: int}
     */
    private function nextToken(): array
    {
        if (preg_match(self::TOKEN, $this->document, $m, PREG_OFFSET_CAPTURE, $this->offset) !== 1) {
            return ['none', null, [], 0, 0];
        }
        [$match, $start] = $m[0];
        $length = strlen($match);
        $closer = $m['closer'][1] !== -1;
        $void = isset($m['void']) && $m['void'][1] !== -1;
        $namespace = $m['namespace'][1] !== -1 ? $m['namespace'][0] : 'core/';
        $name = $namespace . $m['name'][0];
        $attrs = [];
        if (isset($m['attrs']) && $m['attrs'][1] !== -1) {
            $decoded = json_decode($m['attrs'][0], true);
            $attrs = is_array($decoded) ? $decoded : [];
        }

        return [$closer ? 'closer' : ($void ? 'void' : 'opener'), $name, $attrs, $start, $length];
    }

    /**
     * @return array<string, mixed>
     */
    private static function freeform(string $html): array
    {
        return self::block(null, [], [], $html, [$html]);
    }

    private function addFreeform(): void
    {
        $rest = substr($this->document, $this->offset);
        if ($rest !== '') {
            $this->output[] = self::freeform($rest);
        }
    }

    /**
     * @param array<string, mixed> $block
     */
    private function addInnerBlock(array $block, int $start, int $length, ?int $last = null): void
    {
        $parent = &$this->stack[count($this->stack) - 1];
        $parent['block']['innerBlocks'][] = $block;
        $html = substr($this->document, $parent['prev'], $start - $parent['prev']);
        if ($html !== '') {
            $parent['block']['innerHTML'] .= $html;
            $parent['block']['innerContent'][] = $html;
        }
        $parent['block']['innerContent'][] = null;
        $parent['prev'] = $last ?? $start + $length;
    }

    private function addBlockFromStack(?int $end = null): void
    {
        $top = array_pop($this->stack);
        if ($top === null) {
            return;
        }
        $html = $end === null ? substr($this->document, $top['prev']) : substr($this->document, $top['prev'], $end - $top['prev']);
        if ($html !== '') {
            $top['block']['innerHTML'] .= $html;
            $top['block']['innerContent'][] = $html;
        }
        if ($this->stack === []) {
            if ($top['leading'] !== null) {
                $this->output[] = self::freeform(substr($this->document, $top['leading'], $top['start'] - $top['leading']));
            }
            $this->output[] = $top['block'];
            return;
        }
        // an unclosed block inside another, closed at the end of the content: it ran to the end, so its parent did too
        $this->addInnerBlock($top['block'], $top['start'], $top['length'], strlen($this->document));
    }
}
