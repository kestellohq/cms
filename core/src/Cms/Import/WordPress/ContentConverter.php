<?php

declare(strict_types=1);

namespace Kestello\Cms\Import\WordPress;

use Kestello\Cms\Blocks\CoreBlocks;

/**
 * Converts a WordPress post body (block editor or classic editor) into a
 * Kestello block tree, and says what could not be carried over exactly.
 *
 * Block content is read with WordPress's own parser rules; each block maps to
 * the Kestello block that holds it without loss, or to a text block keeping
 * its HTML. Classic content gets WordPress's paragraphs (wpautop) and its
 * [caption], [gallery], [embed], [video] and [audio] shortcodes become blocks.
 *
 * Pure: everything about the site comes through Links.
 */
final class ContentConverter
{
    /** Blocks that only list or show other things on the old site; nothing of the post is lost without them. */
    private const array DROPPED = [
        'core/more', 'core/nextpage', 'core/latest-posts', 'core/latest-comments', 'core/archives', 'core/calendar',
        'core/categories', 'core/tag-cloud', 'core/search', 'core/rss', 'core/page-list', 'core/navigation', 'core/loginout',
        'core/query', 'core/post-template', 'core/comments', 'core/post-comments-form', 'core/site-logo',
        'core/site-title', 'core/site-tagline', 'core/template-part', 'core/post-title', 'core/post-content', 'core/post-date',
        'core/post-excerpt', 'core/post-featured-image', 'core/post-author', 'core/read-more', 'core/footnotes',
    ];
    private const array SHORTCODES = ['caption', 'wp_caption', 'gallery', 'embed', 'video', 'audio', 'playlist'];
    private const int MAX_REUSABLE_DEPTH = 5;

    /** Social link services whose names are not their slug with a capital. */
    private const array SERVICES = [
        'fivehundredpx' => '500px', 'github' => 'GitHub', 'gitlab' => 'GitLab', 'linkedin' => 'LinkedIn', 'youtube' => 'YouTube',
        'tiktok' => 'TikTok', 'wordpress' => 'WordPress', 'x' => 'X', 'mail' => 'Email', 'feed' => 'RSS feed', 'soundcloud' => 'SoundCloud',
        'deviantart' => 'DeviantArt', 'whatsapp' => 'WhatsApp', 'threads' => 'Threads', 'bluesky' => 'Bluesky', 'mastodon' => 'Mastodon',
    ];

    /** Reusable blocks copied into one post at most: one that uses another many times must not multiply without end. */
    private const int MAX_REUSABLE = 100;

    private int $reusableLeft = self::MAX_REUSABLE;

    /** @var array<int, true> the reusable blocks being copied in right now, to stop one that contains itself */
    private array $reusableOpen = [];

    public function __construct(private readonly Links $links) {}

    /**
     * @return array{blocks: list<array<string, mixed>>, notes: array<string, int>}
     */
    public function convert(string $content): array
    {
        $notes = new Notes();
        $el = new ElementBlocks($this->links, $notes);
        $this->reusableLeft = self::MAX_REUSABLE;
        $this->reusableOpen = [];
        $nodes = BlockParser::hasBlocks($content)
            ? $this->blocks(BlockParser::parse($content), $el, 0)
            : $this->classic($content, $el, autop: true);

        $nodes = self::merge($nodes);
        foreach (self::texts($nodes) as $html) {
            foreach (Shortcodes::names($html) as $name => $count) {
                $notes->add('shortcode:' . $name, $count);
            }
        }

        return ['blocks' => $nodes, 'notes' => $notes->all()];
    }

    /**
     * The whole body as text blocks, for content whose converted blocks the
     * site refuses (more blocks or deeper nesting than a page may have).
     * Nothing visible is lost; the layout is.
     *
     * @return list<array<string, mixed>>
     */
    public function fallback(string $content): array
    {
        $el = new ElementBlocks($this->links, new Notes());
        $html = $el->html(BlockParser::hasBlocks($content) ? $content : Autop::apply($content));
        if ($html === '') {
            return [];
        }
        $nodes = [];
        $chunk = '';
        foreach (Fragment::parse($html)->childNodes as $child) {
            $piece = Fragment::outer($child);
            if ($chunk !== '' && strlen($chunk) + strlen($piece) > ElementBlocks::TEXT_MAX) {
                $nodes[] = ElementBlocks::node('core/text', ['text' => $chunk]);
                $chunk = '';
            }
            $chunk .= $piece;
        }
        if (trim($chunk) !== '') {
            $nodes[] = ElementBlocks::node('core/text', ['text' => $chunk]);
        }

        return $nodes;
    }

    // ------------------------------------------------------------ classic

    /**
     * @return list<array<string, mixed>>
     */
    private function classic(string $html, ElementBlocks $el, bool $autop): array
    {
        $html = Shortcodes::replace($html, self::SHORTCODES, fn(string $name, array $atts, string $inner): string => $el->placeholders->add($this->shortcode($name, $atts, $inner, $el)));
        if ($autop) {
            $html = Autop::apply($html);
        }

        return $el->fromHtml($html);
    }

    /**
     * @param array<int|string, string> $atts
     * @return list<array<string, mixed>>
     */
    private function shortcode(string $name, array $atts, string $inner, ElementBlocks $el): array
    {
        switch ($name) {
            case 'caption':
            case 'wp_caption':
                $root = Fragment::parse($inner);
                $media = null;
                foreach (Fragment::significant($root) as $child) {
                    if ($child instanceof \Dom\Element && ElementBlocks::onlyImage($child) !== null) {
                        $media = $child;
                        break;
                    }
                }
                if ($media === null) {
                    return $el->fromHtml($inner);
                }
                $captionHtml = trim(str_replace(Fragment::outer($media), '', $inner));
                $caption = trim((string) ($atts['caption'] ?? ''));
                if ($caption === '') {
                    $caption = Fragment::text(Fragment::parse($captionHtml));
                }
                $id = preg_match('/^attachment_(\d+)$/', (string) ($atts['id'] ?? ''), $m) === 1 ? (int) $m[1] : 0;
                $align = preg_match('/^align(left|right|center)$/', (string) ($atts['align'] ?? ''), $m) === 1 ? $m[1] : '';
                $figure = '<figure>' . Fragment::outer($media) . ($captionHtml === '' ? '' : '<figcaption>' . $captionHtml . '</figcaption>') . '</figure>';

                return $el->imageOrHtml($media, $caption, $align, $figure, $id);

            case 'gallery':
                $ids = array_values(array_filter(array_map('intval', explode(',', (string) ($atts['ids'] ?? $atts['include'] ?? '')))));

                return $this->gallery($ids, [], (int) ($atts['columns'] ?? 3), $el);

            case 'embed':
                return $el->embed(trim($inner !== '' ? $inner : (string) ($atts['src'] ?? $atts[0] ?? '')));

            case 'video':
                $src = trim((string) ($atts['src'] ?? $atts['mp4'] ?? $atts['webm'] ?? $atts['m4v'] ?? $atts['ogv'] ?? $inner));

                return $this->video(0, $src, $el);

            default: // audio, playlist
                $src = trim((string) ($atts['src'] ?? $atts['mp3'] ?? $atts['m4a'] ?? $atts['ogg'] ?? $atts['wav'] ?? ''));

                return $this->fileLink($src, $name, $el);
        }
    }

    // ------------------------------------------------------------- blocks

    /**
     * @param list<array<string, mixed>> $blocks parsed by BlockParser
     * @return list<array<string, mixed>>
     */
    private function blocks(array $blocks, ElementBlocks $el, int $depth): array
    {
        $nodes = [];
        foreach ($blocks as $block) {
            array_push($nodes, ...$this->block($block, $el, $depth));
        }

        return $nodes;
    }

    /**
     * @param array<string, mixed> $block
     * @return list<array<string, mixed>>
     */
    private function block(array $block, ElementBlocks $el, int $depth): array
    {
        $notes = $el->notes;
        /** @var ?string $name */
        $name = $block['name'];
        /** @var array<string, mixed> $attrs */
        $attrs = $block['attrs'];
        $html = self::render($block);
        if ($name === null || $name === 'core/freeform') {
            return $this->classic($html, $el, autop: false);
        }
        if (in_array($name, self::DROPPED, true)) {
            $notes->add('dropped:' . $name);
            return [];
        }
        if (str_starts_with($name, 'core-embed/')) {
            return $this->embedBlock($attrs, $html, $el);
        }
        $inner = static fn(): array => $block['innerBlocks'];

        switch ($name) {
            case 'core/paragraph':
            case 'core/heading':
            case 'core/list':
            case 'core/quote':
            case 'core/table':
                return $el->fromHtml($html);

            case 'core/pullquote':
                $quote = Fragment::find(Fragment::parse($html), 'blockquote');
                return $quote === null ? $el->text($html) : $el->quote($quote);

            case 'core/verse':
            case 'core/preformatted':
                return $el->text($html);

            case 'core/code':
                $code = Fragment::find(Fragment::parse($html), 'code') ?? Fragment::find(Fragment::parse($html), 'pre');
                $text = $code === null ? '' : (string) $code->textContent;
                if (trim($text) === '') {
                    return [];
                }
                if (mb_strlen($text) > 50_000) {
                    return $el->text($html);
                }
                $props = ['code' => $text];
                $language = (string) ($attrs['language'] ?? '');
                if ($language === '' && preg_match('/\blanguage-([a-z0-9+#-]+)/i', (string) $code?->getAttribute('class'), $m) === 1) {
                    $language = $m[1];
                }
                if ($language !== '') {
                    $props['language'] = mb_substr($language, 0, 40);
                }
                return [ElementBlocks::node('core/code', $props)];

            case 'core/html':
                $notes->add('html');
                return $el->fromHtml($html);

            case 'core/shortcode':
                return $this->classic($html, $el, autop: true);

            case 'core/separator':
                $class = (string) ($attrs['className'] ?? '') . ' ' . $html;
                $look = str_contains($class, 'is-style-dots') ? 'dots' : (str_contains($class, 'is-style-wide') ? 'wide' : 'line');
                return [ElementBlocks::node('core/separator', ['look' => $look])];

            case 'core/spacer':
                $height = (int) preg_replace('/\D/', '', (string) ($attrs['height'] ?? '100'));
                $size = match (true) {
                    $height < 40 => 'sm',
                    $height < 80 => 'md',
                    $height < 140 => 'lg',
                    default => 'xl',
                };
                return [ElementBlocks::node('core/spacer', ['size' => $size])];

            case 'core/image':
                $root = Fragment::parse($html);
                $img = Fragment::find($root, 'img');
                if ($img === null) {
                    return [];
                }
                $holder = $img->parentElement !== null && $img->parentElement->localName === 'a' ? $img->parentElement : $img;
                $caption = Fragment::find($root, 'figcaption');
                if ($caption !== null && !Fragment::isPlain($caption)) {
                    // a caption with links: keep the figure as HTML so the links stay
                    $node = $el->image($img, '', '', '', (int) ($attrs['id'] ?? 0));
                    return $node === null ? $this->missing($html, (string) $img->getAttribute('src'), (int) ($attrs['id'] ?? 0), $el) : [...[$node], ...$el->text('<p>' . $caption->innerHTML . '</p>')];
                }
                $align = (string) ($attrs['align'] ?? '');
                return $el->imageOrHtml($holder, $caption === null ? '' : Fragment::text($caption), in_array($align, ['left', 'right', 'center'], true) ? $align : '', $html, (int) ($attrs['id'] ?? 0));

            case 'core/gallery':
                $ids = [];
                $urls = [];
                if ($block['innerBlocks'] !== []) {
                    foreach ($inner() as $image) {
                        $img = Fragment::find(Fragment::parse(self::render($image)), 'img');
                        $ids[] = (int) ($image['attrs']['id'] ?? 0);
                        $urls[] = $img === null ? '' : (string) $img->getAttribute('src');
                    }
                } else {
                    $ids = array_map('intval', (array) ($attrs['ids'] ?? []));
                    foreach (Fragment::parse($html)->querySelectorAll('img') as $i => $img) {
                        $urls[$i] = (string) $img->getAttribute('src');
                    }
                }
                return $this->gallery($ids, $urls, (int) ($attrs['columns'] ?? 3), $el, $html);

            case 'core/embed':
                return $this->embedBlock($attrs, $html, $el);

            case 'core/video':
                $video = Fragment::find(Fragment::parse($html), 'video');
                return $this->video((int) ($attrs['id'] ?? 0), (string) ($video?->getAttribute('src') ?? $attrs['src'] ?? ''), $el);

            case 'core/audio':
            case 'core/file':
                $root = Fragment::parse($html);
                $src = (string) (Fragment::find($root, 'audio')?->getAttribute('src') ?? Fragment::find($root, 'a')?->getAttribute('href') ?? '');
                return $this->fileLink($src, $name === 'core/audio' ? 'audio' : 'file', $el, Fragment::text($root));

            case 'core/buttons':
                $buttons = [];
                $loose = [];
                foreach ($inner() as $button) {
                    foreach ($el->button(self::render($button)) as $node) {
                        $node['type'] === 'core/button' ? $buttons[] = $node : $loose[] = $node;
                    }
                }
                $justify = (string) ($attrs['layout']['justifyContent'] ?? '');
                $align = match ($justify) {
                    'center' => 'center',
                    'right' => 'end',
                    'space-between' => 'between',
                    default => 'start',
                };
                return [...($buttons === [] ? [] : [ElementBlocks::node('core/buttons', $align === 'start' ? [] : ['align' => $align], $buttons)]), ...$loose];

            case 'core/button':
                $nodes = $el->button($html);
                return array_map(static fn(array $n) => $n['type'] === 'core/button' ? ElementBlocks::node('core/buttons', [], [$n]) : $n, $nodes);

            case 'core/columns':
                return $this->columns($inner(), $el, $depth);

            case 'core/column':
            case 'core/group':
            case 'core/row':
            case 'core/stack':
                $children = self::merge($this->blocks($inner(), $el, $depth));
                return $children === [] ? [] : [ElementBlocks::node('core/group', [], $children)];

            case 'core/cover':
                $props = [];
                $media = ($attrs['backgroundType'] ?? 'image') === 'image' ? $this->links->media((int) ($attrs['id'] ?? 0), (string) ($attrs['url'] ?? '')) : null;
                if ($media !== null) {
                    $props['image'] = $media;
                    $props['strength'] = max(0, min(90, (int) ($attrs['dimRatio'] ?? 50)));
                } elseif (($attrs['backgroundType'] ?? 'image') === 'video') {
                    $notes->add('cover-video');     // a cover's background is an image here
                } elseif ((int) ($attrs['id'] ?? 0) > 0 || $this->links->isOld((string) ($attrs['url'] ?? ''))) {
                    $notes->add('media-missing');
                }
                $children = self::merge($this->blocks($inner(), $el, $depth));
                return [ElementBlocks::node('core/cover', $props, $children)];

            case 'core/media-text':
                $children = self::merge($this->blocks($inner(), $el, $depth));
                if (($attrs['mediaType'] ?? 'image') === 'video') {
                    // the media side holds images only here: the video becomes a link next to the text
                    $video = Fragment::find(Fragment::parse($html), 'video');
                    return [...$this->video((int) ($attrs['mediaId'] ?? 0), (string) ($video?->getAttribute('src') ?? $attrs['mediaUrl'] ?? ''), $el), ...$children];
                }
                $media = ($attrs['mediaType'] ?? 'image') === 'image' ? $this->links->media((int) ($attrs['mediaId'] ?? 0), (string) ($attrs['mediaUrl'] ?? '')) : null;
                if ($media === null) {
                    $notes->add('media-missing');
                    $figure = Fragment::find(Fragment::parse($html), 'figure');
                    return [...($figure === null ? [] : $el->text(Fragment::outer($figure))), ...$children];
                }
                $props = ['image' => $media, 'side' => ($attrs['mediaPosition'] ?? 'left') === 'right' ? 'end' : 'start'];
                $width = (int) ($attrs['mediaWidth'] ?? 50);
                $props['share'] = $width <= 45 ? '40' : ($width >= 55 ? '60' : '50');
                $img = Fragment::find(Fragment::parse($html), 'img');
                if ($img !== null && trim((string) $img->getAttribute('alt')) !== '') {
                    $props['alt'] = mb_substr(trim((string) $img->getAttribute('alt')), 0, 1000);
                }
                return [ElementBlocks::node('core/media-text', $props, $children)];

            case 'core/social-links':
                $items = [];
                foreach ($block['innerBlocks'] as $link) {
                    $url = trim((string) ($link['attrs']['url'] ?? ''));
                    if (preg_match('#^(?:https?://|mailto:)#i', $url) !== 1 || !ElementBlocks::safeLink($url)) {
                        continue;   // empty, or a placeholder such as "#"
                    }
                    $service = (string) ($link['attrs']['service'] ?? '');
                    $label = trim((string) ($link['attrs']['label'] ?? '')) ?: (self::SERVICES[$service] ?? ucfirst($service)) ?: $url;
                    $items[] = '<li><a href="' . htmlspecialchars($url, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '">' . htmlspecialchars($label, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</a></li>';
                }
                if ($items === []) {
                    $notes->add('dropped:' . $name);
                    return [];
                }
                return $el->fromHtml('<ul>' . implode('', $items) . '</ul>');

            case 'core/details':
                $root = Fragment::parse($html);
                $summary = Fragment::find($root, 'summary');
                $summaryText = $summary === null ? '' : Fragment::text($summary);
                $summary?->remove();
                $details = Fragment::find($root, 'details');
                $body = $el->html($details === null ? Fragment::inner($root) : $details->innerHTML);
                if ($summaryText === '' || mb_strlen($summaryText) > 300 || mb_strlen($body) > 20_000) {
                    return $el->text($html);
                }
                return [ElementBlocks::node('core/details', $body === '' ? ['summary' => $summaryText] : ['summary' => $summaryText, 'text' => $body])];

            case 'core/block':
                $ref = (int) ($attrs['ref'] ?? 0);
                $usable = $ref > 0 && $depth < self::MAX_REUSABLE_DEPTH && !isset($this->reusableOpen[$ref]) && $this->reusableLeft > 0;
                $saved = $usable ? $this->links->reusable($ref) : null;
                if ($saved === null) {
                    $notes->add('dropped:core/block');
                    return [];
                }
                $this->reusableLeft--;
                $this->reusableOpen[$ref] = true;
                try {
                    return $this->blocks(BlockParser::parse($saved), $el, $depth + 1);
                } finally {
                    unset($this->reusableOpen[$ref]);
                }

            case 'core/missing':
                return $el->fromHtml((string) ($attrs['originalContent'] ?? $html));

            default:
                // a block Kestello does not have (plugins, newer WordPress): keep what it saved
                $nodes = $block['innerBlocks'] !== [] && trim(strip_tags(implode('', array_filter($block['innerContent'], 'is_string')))) === ''
                    ? $this->blocks($inner(), $el, $depth)      // a wrapper: its insides are ordinary blocks
                    : $el->fromHtml($html);
                $notes->add(($nodes === [] ? 'dropped:' : 'block:') . $name);
                return $nodes;
        }
    }

    /**
     * A block's full saved HTML, its inner blocks put back where they were.
     *
     * @param array<string, mixed> $block
     */
    private static function render(array $block): string
    {
        $html = '';
        $i = 0;
        foreach ($block['innerContent'] as $piece) {
            $html .= is_string($piece) ? $piece : self::render($block['innerBlocks'][$i++] ?? ['innerContent' => [], 'innerBlocks' => []]);
        }

        return $html;
    }

    // ----------------------------------------------------------- builders

    /**
     * @param list<array<string, mixed>> $columns
     * @return list<array<string, mixed>>
     */
    private function columns(array $columns, ElementBlocks $el, int $depth): array
    {
        $converted = [];
        $widths = [];
        foreach ($columns as $column) {
            $children = self::merge($this->blocks($column['innerBlocks'], $el, $depth));
            if ($children !== []) {
                $converted[] = $children;
                $widths[] = (float) ($column['attrs']['width'] ?? 0);
            }
        }
        if (count($converted) <= 1) {
            return $converted[0] ?? [];
        }
        // Kestello has two to four columns: wider rows are split evenly
        $per = (int) ceil(count($converted) / (int) ceil(count($converted) / 4));
        $rows = [];
        foreach (array_chunk($converted, $per) as $r => $chunk) {
            if (count($chunk) === 1) {
                array_push($rows, ...$chunk[0]);
                continue;
            }
            $layout = match (count($chunk)) {
                2 => self::twoColumnLayout(array_slice($widths, $r * $per, 2)),
                3 => '1-1-1',
                default => '1-1-1-1',
            };
            $rows[] = ElementBlocks::node('core/columns', ['layout' => $layout], array_map(static fn(array $c) => ElementBlocks::node('core/column', [], $c), $chunk));
        }

        return $rows;
    }

    /**
     * @param list<float> $widths
     */
    private static function twoColumnLayout(array $widths): string
    {
        [$a, $b] = [$widths[0] ?? 0.0, $widths[1] ?? 0.0];
        if ($a <= 0 && $b <= 0) {
            return '1-1';
        }
        $a = $a > 0 ? $a : 100 - $b;
        $b = $b > 0 ? $b : 100 - $a;
        $ratio = $b > 0 ? $a / $b : 1;

        return $ratio >= 1.5 ? '2-1' : ($ratio <= 1 / 1.5 ? '1-2' : '1-1');
    }

    /**
     * @param list<int> $ids
     * @param array<int, string> $urls
     * @return list<array<string, mixed>>
     */
    private function gallery(array $ids, array $urls, int $columns, ElementBlocks $el, string $html = ''): array
    {
        $media = [];
        $missing = 0;
        foreach (array_keys($ids + $urls) as $i) {
            $id = $this->links->media((int) ($ids[$i] ?? 0), (string) ($urls[$i] ?? ''));
            if ($id !== null) {
                $media[] = $id;
            } elseif ($this->links->isOld((string) ($urls[$i] ?? '')) || (int) ($ids[$i] ?? 0) > 0) {
                $missing++;
            }
        }
        if ($missing > 0) {
            $el->notes->add('media-missing', $missing);
        }
        if ($media === []) {
            return $html === '' ? [] : $el->text($html);
        }

        return [ElementBlocks::node('core/gallery', ['images' => array_values(array_unique($media)), 'columns' => (string) max(2, min(6, $columns ?: 3))])];
    }

    /**
     * @param array<string, mixed> $attrs
     * @return list<array<string, mixed>>
     */
    private function embedBlock(array $attrs, string $html, ElementBlocks $el): array
    {
        $url = (string) ($attrs['url'] ?? '');
        if ($url === '') {
            $wrapper = Fragment::find(Fragment::parse($html), 'div');
            $url = $wrapper === null ? '' : Fragment::text($wrapper);
        }
        $caption = Fragment::find(Fragment::parse($html), 'figcaption');

        return [...$el->embed($url), ...($caption === null ? [] : $el->text('<p>' . $caption->innerHTML . '</p>'))];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function video(int $attachment, string $src, ElementBlocks $el): array
    {
        if ($src !== '' && CoreBlocks::embed($src) !== null) {
            return $el->embed($src);
        }
        $media = $this->links->media($attachment, $src);
        if ($media !== null) {
            return [ElementBlocks::node('core/embed', ['file' => $media])];
        }

        return $this->fileLink($src, 'video', $el);
    }

    /**
     * A link to a file (audio, a download), pointing at the imported copy when there is one.
     *
     * @return list<array<string, mixed>>
     */
    private function fileLink(string $src, string $kind, ElementBlocks $el, string $label = ''): array
    {
        $src = trim($src);
        if ($src === '' || !ElementBlocks::safeLink($src)) {
            return [];
        }
        if ($this->links->media(0, $src) === null && $this->links->isOld($src)) {
            $el->notes->add('media-missing');
        }
        $href = $this->links->url($src);
        $text = $label !== '' ? $label : (rawurldecode(basename((string) parse_url($src, PHP_URL_PATH))) ?: $src);

        return $el->text('<p><a href="' . htmlspecialchars($href, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '">' . htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</a></p>');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function missing(string $html, string $src, int $attachment, ElementBlocks $el): array
    {
        if ($attachment > 0 || $this->links->isOld($src)) {
            $el->notes->add('media-missing');
        }

        return $el->text($html);
    }

    // -------------------------------------------------------------- tidy

    /**
     * Neighbouring text blocks with the same alignment become one, as an
     * editor would have written them; nested children too.
     *
     * @param list<array<string, mixed>> $nodes
     * @return list<array<string, mixed>>
     */
    public static function merge(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $node) {
            if ($node['children'] !== []) {
                $node['children'] = self::merge($node['children']);
            }
            $last = array_key_last($out);
            if ($last !== null && $node['type'] === 'core/text' && $out[$last]['type'] === 'core/text'
                && ($node['props']['align'] ?? 'start') === ($out[$last]['props']['align'] ?? 'start')
                && strlen($out[$last]['props']['text']) + strlen($node['props']['text']) + 1 <= ElementBlocks::TEXT_MAX) {
                $out[$last]['props']['text'] .= "\n" . $node['props']['text'];
                continue;
            }
            $out[] = $node;
        }

        return $out;
    }

    /**
     * Every text block's HTML in the tree.
     *
     * @param list<array<string, mixed>> $nodes
     * @return list<string>
     */
    private static function texts(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $node) {
            if ($node['type'] === 'core/text') {
                $out[] = (string) $node['props']['text'];
            }
            array_push($out, ...self::texts($node['children']));
        }

        return $out;
    }
}
