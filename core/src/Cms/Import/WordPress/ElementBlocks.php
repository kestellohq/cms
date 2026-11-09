<?php

declare(strict_types=1);

namespace Kestello\Cms\Import\WordPress;

use Dom\Comment;
use Dom\Element;
use Dom\Node;
use Dom\Text;
use Kestello\Cms\Blocks\CoreBlocks;
use Kestello\Cms\Content\Fields\TableField;
use Kestello\Support\Html;

/**
 * Turns HTML into Kestello blocks. The rule everywhere: a structured block
 * only when it holds the content without loss, otherwise a text block that
 * keeps the HTML (lists with links, tables with merged cells, headings with
 * formatting all stay as they were).
 *
 * Used for classic content, for the HTML inside Gutenberg blocks and for
 * blocks Kestello does not have.
 *
 * @internal
 */
final class ElementBlocks
{
    public const int TEXT_MAX = 100_000;
    private const int HEADING_MAX = 300;
    private const int LIST_ITEMS_MAX = 100;
    private const int LIST_ITEM_MAX = 500;
    private const int QUOTE_MAX = 2000;
    private const int CITE_MAX = 200;
    private const int CAPTION_MAX = 500;
    private const int TABLE_ROWS_MAX = 200;
    private const int TABLE_COLUMNS_MAX = 12;
    private const int BUTTON_MAX = 80;

    /** Attributes that only meant something to the WordPress theme or point at the old site's resized copies. */
    private const array DROPPED_ATTRIBUTES = ['class', 'id', 'style', 'srcset', 'sizes', 'decoding', 'fetchpriority'];

    public function __construct(
        private readonly Links $links,
        public readonly Notes $notes,
        public readonly Placeholders $placeholders = new Placeholders(),
    ) {}

    /**
     * @param array<string, mixed> $props
     * @param list<array<string, mixed>> $children
     * @return array<string, mixed>
     */
    public static function node(string $type, array $props = [], array $children = []): array
    {
        return ['type' => $type, 'props' => $props, 'children' => $children];
    }

    // ---------------------------------------------------------------- HTML

    /**
     * $html made ready for a text block: addresses pointed at the new site,
     * WordPress classes and styles removed, then sanitized like any rich text.
     * '' when nothing visible is left.
     */
    public function html(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }
        $root = Fragment::parse($html);
        // what the sanitizer will take out, so the report can say so
        foreach ($root->querySelectorAll('iframe, script, style, form, object, embed, video, audio, svg, canvas') as $removed) {
            $tag = strtolower($removed->localName);
            $this->notes->add($tag === 'iframe' ? 'frame:' . (self::host((string) $removed->getAttribute('src')) ?: 'unknown') : 'removed:' . $tag);
        }
        foreach ($root->querySelectorAll('*') as $element) {
            foreach (self::DROPPED_ATTRIBUTES as $name) {
                $element->removeAttribute($name);
            }
            foreach (iterator_to_array($element->attributes) as $attribute) {
                if (str_starts_with($attribute->name, 'data-') || str_starts_with($attribute->name, 'aria-')) {
                    $element->removeAttribute($attribute->name);
                }
            }
            foreach (['href', 'src'] as $name) {
                if ($element->hasAttribute($name)) {
                    $element->setAttribute($name, $this->links->url(trim((string) $element->getAttribute($name))));
                }
            }
        }
        $clean = trim(Html::sanitize(Fragment::inner($root), 'rich-text'));
        $visible = trim(html_entity_decode(strip_tags($clean, '<img><hr><table>'), ENT_QUOTES | ENT_HTML5, 'UTF-8'), " \t\n\r\0\x0B\u{a0}");

        return $visible === '' ? '' : $clean;
    }

    /**
     * A text block holding $html, or nothing when no text is left.
     *
     * @return list<array<string, mixed>>
     */
    public function text(string $html, string $align = 'start'): array
    {
        $html = $this->html($html);
        if ($html === '') {
            return [];
        }
        $props = ['text' => $html];
        if ($align !== 'start') {
            $props['align'] = $align;
        }

        return [self::node('core/text', $props)];
    }

    /**
     * Blocks for an HTML fragment, top-level element by element.
     *
     * @return list<array<string, mixed>>
     */
    public function fromHtml(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }
        $nodes = [];
        foreach (Fragment::significant(Fragment::parse($html)) as $child) {
            array_push($nodes, ...$this->fromNode($child));
        }

        return $nodes;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fromNode(Node $node): array
    {
        if ($node instanceof Comment) {
            return Placeholders::is($node) ? $this->placeholders->take($node) : [];
        }
        if ($node instanceof Text) {
            return $this->text('<p>' . htmlspecialchars((string) $node->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</p>');
        }
        if (!$node instanceof Element) {
            return [];
        }
        $tag = strtolower($node->localName);

        return match (true) {
            $tag === 'p' => $this->paragraph($node),
            (bool) preg_match('/^h[1-6]$/', $tag) => $this->heading($node),
            $tag === 'ul', $tag === 'ol' => $this->list($node),
            $tag === 'blockquote' => $this->quote($node),
            $tag === 'table' => $this->table($node),
            $tag === 'figure' => $this->figure($node),
            $tag === 'hr' => [self::node('core/separator', ['look' => 'line'])],
            $tag === 'img', $tag === 'a' && self::onlyImage($node) !== null => $this->imageOrHtml($node),
            $tag === 'iframe' => $this->frame($node),
            in_array($tag, ['div', 'section', 'article', 'main', 'center'], true) => $this->container($node),
            default => $this->text(Fragment::outer($node)),
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function paragraph(Element $p): array
    {
        $parts = Fragment::significant($p);
        if (count($parts) === 1 && $parts[0] instanceof Comment) {
            return $this->placeholders->take($parts[0]);
        }
        if (count($parts) === 1 && $parts[0] instanceof Element && self::onlyImage($parts[0]) !== null) {
            return $this->imageOrHtml($parts[0], '', self::imageAlign($p));
        }
        $text = Fragment::text($p);
        if (count($parts) === 1 && $parts[0] instanceof Text && preg_match('#^https?://\S+$#i', $text) === 1) {
            return $this->embed($text);    // a bare address on its own line: WordPress embedded it
        }

        return $this->text(Fragment::outer($p), self::textAlign($p));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function heading(Element $h): array
    {
        $text = Fragment::text($h);
        if ($text === '') {
            return [];
        }
        if (!Fragment::isPlain($h) || mb_strlen($text) > self::HEADING_MAX) {
            return $this->text(Fragment::outer($h));
        }

        return [self::node('core/heading', ['text' => $text, 'level' => substr(strtolower($h->localName), 1)])];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(Element $list): array
    {
        $items = [];
        foreach (Fragment::significant($list) as $child) {
            if (!$child instanceof Element || $child->localName !== 'li' || !Fragment::isPlain($child)) {
                return $this->text(Fragment::outer($list));    // nested, formatted or linked: keep the HTML
            }
            $text = Fragment::text($child);
            if (mb_strlen($text) > self::LIST_ITEM_MAX) {
                return $this->text(Fragment::outer($list));
            }
            if ($text !== '') {
                $items[] = ['text' => $text];
            }
        }
        if ($items === []) {
            return [];
        }
        $ordered = $list->localName === 'ol';
        if (count($items) > self::LIST_ITEMS_MAX || ($ordered && ($list->hasAttribute('start') || $list->hasAttribute('reversed')))) {
            return $this->text(Fragment::outer($list));
        }

        return [self::node('core/list', ['ordered' => $ordered, 'items' => $items])];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function quote(Element $quote): array
    {
        $lines = [];
        $cite = '';
        foreach (Fragment::significant($quote) as $child) {
            if ($child instanceof Text) {
                $lines[] = Fragment::text($child);
            } elseif ($child instanceof Element && $child->localName === 'p' && Fragment::isPlain($child)) {
                $lines[] = Fragment::text($child);
            } elseif ($child instanceof Element && in_array($child->localName, ['cite', 'footer'], true) && Fragment::isPlain($child) && $cite === '') {
                $cite = Fragment::text($child);
            } else {
                return $this->text(Fragment::outer($quote));
            }
        }
        $text = implode("\n\n", array_filter($lines, static fn(string $l) => $l !== ''));
        if ($text === '') {
            return [];
        }
        if (mb_strlen($text) > self::QUOTE_MAX || mb_strlen($cite) > self::CITE_MAX) {
            return $this->text(Fragment::outer($quote));
        }

        return [self::node('core/quote', $cite === '' ? ['text' => $text] : ['text' => $text, 'cite' => $cite])];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function table(Element $table, string $caption = ''): array
    {
        $rows = [];
        $head = false;
        foreach ($table->querySelectorAll('tr') as $i => $tr) {
            $cells = [];
            foreach (Fragment::elements($tr) as $cell) {
                if (!in_array($cell->localName, ['td', 'th'], true)) {
                    continue;
                }
                $merged = (int) ($cell->getAttribute('colspan') ?: 1) > 1 || (int) ($cell->getAttribute('rowspan') ?: 1) > 1;
                $text = Fragment::text($cell);
                if ($merged || !Fragment::isPlain($cell) || mb_strlen($text) > TableField::CELL_MAX) {
                    return $this->tableHtml($table, $caption);
                }
                $cells[] = $text;
            }
            if ($i === 0 && $tr->parentElement?->localName === 'thead') {
                $head = true;
            }
            $rows[] = $cells;
        }
        $width = count($rows[0] ?? []);
        if ($width === 0 || $width > self::TABLE_COLUMNS_MAX || count($rows) > self::TABLE_ROWS_MAX || mb_strlen($caption) > 300) {
            return $this->tableHtml($table, $caption);
        }
        foreach ($rows as $row) {
            if (count($row) !== $width) {
                return $this->tableHtml($table, $caption);
            }
        }
        $props = ['table' => ['head' => $head, 'rows' => $rows]];
        if ($caption !== '') {
            $props['caption'] = $caption;
        }

        return [self::node('core/table', $props)];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tableHtml(Element $table, string $caption): array
    {
        $html = Fragment::outer($table);

        return $this->text($caption === '' ? $html : '<figure>' . $html . '<figcaption>' . htmlspecialchars($caption, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</figcaption></figure>');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function figure(Element $figure): array
    {
        $caption = '';
        $captionElement = Fragment::find($figure, 'figcaption');
        if ($captionElement !== null) {
            $caption = Fragment::text($captionElement);
        }
        $table = Fragment::find($figure, 'table');
        if ($table !== null) {
            return $captionElement === null || Fragment::isPlain($captionElement) ? $this->table($table, $caption) : $this->text(Fragment::outer($figure));
        }
        $media = null;
        foreach (Fragment::significant($figure) as $child) {
            if ($child instanceof Element && $child->localName !== 'figcaption' && self::onlyImage($child) !== null) {
                $media = $child;
                break;
            }
        }
        if ($media !== null && ($captionElement === null || Fragment::isPlain($captionElement))) {
            return $this->imageOrHtml($media, $caption, self::imageAlign($figure), Fragment::outer($figure));
        }

        return $this->text(Fragment::outer($figure));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function container(Element $element): array
    {
        $nodes = [];
        foreach (Fragment::significant($element) as $child) {
            array_push($nodes, ...$this->fromNode($child));
        }

        return $nodes;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function frame(Element $frame): array
    {
        $src = trim((string) $frame->getAttribute('src'));
        if ($src !== '' && CoreBlocks::embed($src) !== null) {
            return $this->embed($src, trim((string) $frame->getAttribute('title')));
        }
        $this->notes->add('frame:' . (self::host($src) ?: 'unknown'));

        return [];
    }

    // --------------------------------------------------------------- media

    /** The <img> when $element is an image or a link around only an image. */
    public static function onlyImage(Element $element): ?Element
    {
        if ($element->localName === 'img') {
            return $element;
        }
        if ($element->localName !== 'a') {
            return null;
        }
        $inside = Fragment::significant($element);

        return count($inside) === 1 && $inside[0] instanceof Element && $inside[0]->localName === 'img' ? $inside[0] : null;
    }

    /**
     * An image block for <img> or <a><img></a>, or the HTML when the file was not imported.
     *
     * @param string $fallback the HTML to keep instead (default: the element itself)
     * @return list<array<string, mixed>>
     */
    public function imageOrHtml(Element $element, string $caption = '', string $align = '', string $fallback = '', int $attachment = 0): array
    {
        $image = self::onlyImage($element);
        if ($image === null) {
            return $this->text($fallback !== '' ? $fallback : Fragment::outer($element));
        }
        // a caption longer than an image takes goes under it whole, never cut
        $long = mb_strlen($caption) > self::CAPTION_MAX;
        $node = $this->image($image, $element->localName === 'a' ? (string) $element->getAttribute('href') : '', $long ? '' : $caption, $align, $attachment);
        if ($node !== null) {
            return $long ? [$node, ...$this->text('<p>' . htmlspecialchars($caption, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</p>')] : [$node];
        }
        if ($attachment > 0 || $this->links->isOld(trim((string) $image->getAttribute('src')))) {
            $this->notes->add('media-missing');     // an image from another site is no news: it stays where it was
        }

        return $this->text($fallback !== '' ? $fallback : Fragment::outer($element));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function image(Element $img, string $href, string $caption, string $align, int $attachment = 0): ?array
    {
        $src = trim((string) $img->getAttribute('src'));
        if ($attachment === 0 && preg_match('/\bwp-image-(\d+)\b/', (string) $img->getAttribute('class'), $m) === 1) {
            $attachment = (int) $m[1];
        }
        $media = $this->links->media($attachment, $src);
        if ($media === null) {
            return null;
        }
        $props = ['image' => $media];
        $alt = trim((string) $img->getAttribute('alt'));
        if ($alt !== '') {
            $props['alt'] = mb_substr($alt, 0, 1000);
        }
        if ($caption !== '') {
            $props['caption'] = $caption;
        }
        $props['align'] = match ($align !== '' ? $align : self::alignClass((string) $img->getAttribute('class'))) {
            'left' => 'wrap-start',
            'right' => 'wrap-end',
            default => 'center',
        };
        $href = trim($href);
        if ($href !== '') {
            if ($this->links->media(0, $href) === $media) {
                $props['lightbox'] = true;      // linked to its own file: open it larger
            } else {
                $url = $this->links->url($href);
                if (self::safeLink($url)) {
                    $props['link'] = $url;
                }
            }
        }

        return self::node('core/image', $props);
    }

    /**
     * A YouTube or Vimeo embed, or a link to anything else.
     *
     * @return list<array<string, mixed>>
     */
    public function embed(string $url, string $title = ''): array
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (CoreBlocks::embed($url) !== null) {
            $props = ['url' => $url];
            if ($title !== '') {
                $props['title'] = mb_substr($title, 0, 200);
            }

            return [self::node('core/embed', $props)];
        }
        if (!self::safeLink($url)) {
            return [];
        }
        $this->notes->add('embed:' . (self::host($url) ?: 'link'));
        $href = $this->links->url($url);

        return $this->text('<p><a href="' . htmlspecialchars($href, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '">' . htmlspecialchars($url, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</a></p>');
    }

    /**
     * A button, or a text link when it cannot be one.
     *
     * @return list<array<string, mixed>>
     */
    public function button(string $html): array
    {
        $root = Fragment::parse($html);
        $a = Fragment::find($root, 'a');
        $text = $a === null ? Fragment::text($root) : Fragment::text($a);
        if ($text === '') {
            return [];
        }
        $href = $a === null ? '' : $this->links->url(trim((string) $a->getAttribute('href')));
        if ($href === '' || !self::safeLink($href) || mb_strlen($text) > self::BUTTON_MAX) {
            return $this->text($html);
        }
        $props = ['text' => $text, 'link' => $href];
        if (str_contains((string) ($root->querySelector('.wp-block-button')?->getAttribute('class') ?? ''), 'is-style-outline')) {
            $props['variant'] = 'secondary';
        }
        if ($a !== null && $a->getAttribute('target') === '_blank') {
            $props['new_tab'] = true;
        }

        return [self::node('core/button', $props)];
    }

    // --------------------------------------------------------------- small

    public static function safeLink(string $url): bool
    {
        return $url !== '' && !str_starts_with($url, '//') && Html::safeUrl($url, ['http', 'https', 'mailto', 'tel']) && strlen($url) <= 2048;
    }

    public static function host(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /** 'left', 'right', 'center' or '' from WordPress alignment classes. */
    private static function alignClass(string $class): string
    {
        return preg_match('/\balign(left|right|center)\b/', $class, $m) === 1 ? $m[1] : '';
    }

    /** Kestello text alignment (start, center, end) from a WordPress alignment class or style. */
    public static function textAlign(Element $element): string
    {
        $class = (string) $element->getAttribute('class');
        $style = (string) $element->getAttribute('style');
        if (preg_match('/\bhas-text-align-(left|center|right)\b/', $class, $m) === 1 || preg_match('/text-align:\s*(left|center|right)/', $style, $m) === 1) {
            return match ($m[1]) {
                'center' => 'center',
                'right' => 'end',
                default => 'start',
            };
        }

        return 'start';
    }

    /** Image placement (left, right, center or '') from a WordPress alignment class. */
    public static function imageAlign(Element $element): string
    {
        return self::alignClass((string) $element->getAttribute('class'));
    }
}
