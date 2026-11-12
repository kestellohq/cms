<?php

declare(strict_types=1);

namespace Kestello\Cms\Blocks;

use Kestello\Cms\Cms;
use Kestello\Cms\Content\Entry;
use Kestello\Cms\Content\Field;
use Kestello\Cms\Media\MediaPresenter;
use Kestello\Support\Html;

/**
 * The core blocks: group, columns/column, heading, text, image, button,
 * list, quote, embed, spacer, menu, query and entry field; sections
 * (CoreSectionBlocks) and lists (CoreListBlocks) are registered from here too. Their Vue halves
 * are resources/js/pages/blocks/*.vue ("@core/blocks/…"); a theme restyles
 * one by shipping a component with the same name.
 *
 * @internal BlocksModule
 */
final class CoreBlocks
{
    public const string QUERY_ITEM = '@core/blocks/QueryItem';

    public static function register(Cms $cms): void
    {
        $b = $cms->blocks;
        $align = static fn() => Field::select('align', ['start' => 'Left', 'center' => 'Centre', 'end' => 'Right'])->label('Alignment')->default('start')->width('half');
        $width = static fn() => Field::select('width', ['content' => 'Text width', 'wide' => 'Wide', 'full' => 'Full width'])->label('Width')->default('content');

        $b->register(BlockDefinition::make('core/group')->supports(['color', 'spacing', 'border'])->label('Group')->category('layout')->icon('bounding-box')
            ->description('A section that holds other blocks.')
            ->fields([
                Field::select('tag', ['div' => 'Plain', 'section' => 'Section', 'aside' => 'Aside', 'header' => 'Header', 'footer' => 'Footer'])->label('Role on the page')->help('Tells screen readers and search engines what this part of the page is. It does not change the look.')->default('div'),
                $width(),
                Field::select('tone', ['plain' => 'Plain', 'muted' => 'Muted', 'inverse' => 'Inverse'])->label('Background')->default('plain'),
            ])
            ->children());

        $b->register(BlockDefinition::make('core/columns')->supports(['color', 'spacing', 'border'])->label('Columns')->category('layout')->icon('columns')
            ->description('Blocks side by side. They stack on small screens.')
            ->fields([
                Field::select('layout', ['1-1' => 'Two equal', '2-1' => 'Wide, narrow', '1-2' => 'Narrow, wide', '1-1-1' => 'Three equal', '1-1-1-1' => 'Four equal'])->label('Layout')->default('1-1')->option('summary', true),
                Field::select('gap', ['sm' => 'Small', 'md' => 'Medium', 'lg' => 'Large'])->label('Gap')->default('md'),
                $width(),
            ])
            ->children(['core/column'])
            ->example(['layout' => '1-1']));

        $b->register(BlockDefinition::make('core/column')->supports(['color', 'spacing', 'border'])->label('Column')->category('layout')->icon('columns')
            ->fields([Field::select('align', ['start' => 'Top', 'center' => 'Middle', 'end' => 'Bottom'])->label('Vertical alignment')->default('start')])
            ->parents(['core/columns'])->children());

        $b->register(BlockDefinition::make('core/heading')->supports(['color', 'spacing', 'type', 'border'])->label('Heading')->category('text')->icon('text-h')
            ->iconBy('level', ['1' => 'text-h-one', '2' => 'text-h-two', '3' => 'text-h-three', '4' => 'text-h-four', '5' => 'text-h-five', '6' => 'text-h-six'])
            ->fields([
                Field::text('text')->label('Text')->required()->max(300),
                Field::select('level', ['2' => 'Heading 2', '3' => 'Heading 3', '4' => 'Heading 4', '5' => 'Heading 5', '6' => 'Heading 6', '1' => 'Heading 1 (page title)'])->label('Level')->default('2')->width('half'),
                $align(),
            ])
            ->example(['text' => 'A clear heading', 'level' => '2']));

        $b->register(BlockDefinition::make('core/text')->supports(['color', 'spacing', 'type', 'border'])->label('Text')->category('text')->icon('text-align-left')
            ->description('Paragraphs with bold, italic and links.')
            ->fields([Field::richText('text')->label('Text')->required()->max(100000), $align()])
            ->example(['text' => '<p>Some words worth reading.</p>']));

        $b->register(BlockDefinition::make('core/image')->supports(['spacing'])->label('Image')->category('media')->icon('image')
            ->fields([
                Field::image('image')->label('Image')->required(),
                Field::text('alt')->label('Alt text')->max(1000)->help('Leave empty to use the alt text from the media library.'),
                Field::text('caption')->label('Caption')->max(500),
                Field::number('scale')->label('Size (%)')->min(10)->max(100)->default(100)->width('half')->help('Or drag the image\'s corner on the page.'),
                Field::select('align', ['center' => 'Centre', 'start' => 'Left', 'end' => 'Right', 'wrap-start' => 'Left, text flows around', 'wrap-end' => 'Right, text flows around'])->label('Position')->default('center')->width('half'),
                Field::select('ratio', ['auto' => 'Original', '1/1' => 'Square', '4/3' => '4:3', '3/2' => '3:2', '16/9' => '16:9', '3/4' => '3:4 (tall)', '2/3' => '2:3 (tall)'])->label('Shape')->default('auto')->width('half'),
                Field::select('corners', ['theme' => 'Theme default', 'square' => 'Square', 'rounded' => 'Rounded', 'round' => 'Round'])->label('Corners')->default('theme')->width('half'),
                Field::link('link')->label('Link'),
                Field::boolean('lightbox')->label('Open larger when clicked')->help('Only when the image has no link.'),
                $width(),
            ]));

        $b->register(BlockDefinition::make('core/button')->supports(['color', 'spacing', 'type', 'border'])->label('Button')->category('text')->icon('hand-pointing')
            ->fields([
                Field::text('text')->label('Text')->required()->max(80),
                Field::link('link')->label('Link')->required(),
                Field::select('variant', ['primary' => 'Primary', 'secondary' => 'Secondary', 'plain' => 'Plain link'])->label('Style')->default('primary'),
                Field::boolean('new_tab')->label('Open in a new tab'),
            ])
            ->example(['text' => 'Get in touch', 'link' => '/contact']));

        $b->register(BlockDefinition::make('core/list')->supports(['color', 'spacing', 'type', 'border'])->label('List')->category('text')->icon('list-bullets')
            ->fields([
                Field::boolean('ordered')->label('Numbered'),
                Field::repeater('items', [Field::text('text')->label('Item')->required()->max(500)])->label('Items')->required()->min(1)->max(100),
            ])
            ->example(['items' => [['text' => 'First'], ['text' => 'Second']]]));

        $b->register(BlockDefinition::make('core/quote')->supports(['color', 'spacing', 'type', 'border'])->label('Quote')->category('text')->icon('quotes')
            ->fields([
                Field::textarea('text')->label('Quote')->required()->max(2000),
                Field::text('cite')->label('Who said it')->max(200),
                $align(),
            ]));

        $b->register(BlockDefinition::make('core/separator')->supports(['color', 'spacing'])->label('Separator')->category('layout')->icon('minus')
            ->description('A line between two parts of the page.')
            ->fields([Field::select('look', ['line' => 'Line', 'wide' => 'Wide line', 'dots' => 'Dots'])->label('Style')->default('line')]));

        $b->register(BlockDefinition::make('core/code')->supports(['color', 'spacing', 'type', 'border'])->label('Code')->category('text')->icon('code-block')
            ->description('Code or other text shown exactly as typed, in a fixed-width font.')
            ->fields([
                Field::textarea('code')->label('Code')->required()->max(50000)->option('bindable', false),
                Field::text('language')->label('Language')->max(40)->help('Shown above the code, such as PHP or HTML.')->option('bindable', false)->option('findable', false),
            ]));

        $b->register(BlockDefinition::make('core/details')->supports(['color', 'spacing', 'type', 'border'])->label('Details')->category('text')->icon('caret-circle-down')
            ->description('A summary visitors open to read more. Good for questions and answers.')
            ->fields([
                Field::text('summary')->label('Summary')->required()->max(300),
                Field::richText('text')->label('Content')->max(20000),
                Field::boolean('open')->label('Open when the page loads'),
            ]));

        $b->register(BlockDefinition::make('core/embed')->supports(['spacing'])->label('Video')->category('media')->icon('play-circle')
            ->description('A video from the media library, or from YouTube or Vimeo. Those load only when someone presses play, so visitors are not tracked before that.')
            ->fields([
                Field::media('file', ['video'])->label('Video file')->help('Upload one, or use a YouTube or Vimeo address below.'),
                Field::url('url')->label('Or a YouTube or Vimeo address')->max(500),
                Field::text('title')->label('Title')->max(200)->help('Read out by screen readers.'),
                Field::image('poster')->label('Cover image')->help('Shown before it plays.'),
                Field::select('ratio', ['16/9' => '16:9', '4/3' => '4:3', '1/1' => 'Square', '9/16' => '9:16 (vertical)', '21/9' => '21:9 (cinema)'])->label('Shape')->default('16/9')->width('half'),
                Field::number('start')->label('Start at (seconds)')->min(0)->max(86400)->width('half'),
                Field::boolean('autoplay')->label('Play by itself, without sound')->help('Video files only. It loops, and visitors who prefer less motion get a play button instead.'),
                Field::boolean('loop')->label('Start again at the end')->help('Video files only.'),
                Field::boolean('controls')->label('Show the player controls')->default(true)->help('Video files only.'),
                $width(),
            ])
            ->prepare(static function (array $node): array {
                $node['props']['embed'] = self::embed((string) ($node['props']['url'] ?? ''), (int) ($node['props']['start'] ?? 0));

                return $node;
            }));

        $b->register(BlockDefinition::make('core/spacer')->label('Space')->category('layout')->icon('arrows-vertical')
            ->fields([Field::select('size', ['sm' => 'Small', 'md' => 'Medium', 'lg' => 'Large', 'xl' => 'Extra large'])->label('Size')->default('md')]));

        $b->register(BlockDefinition::make('core/menu')->supports(['spacing'])->label('Menu')->category('widgets')->icon('tree-view')
            ->description('The menu assigned to one of the theme\'s locations.')
            ->fields(static fn() => [
                Field::select('location', array_map(static fn(array $l) => $l['label'], $cms->menus->locations()) ?: ['primary' => 'Primary'])->label('Location')->required(),
                Field::select('orientation', ['horizontal' => 'Across', 'vertical' => 'Down'])->label('Direction')->default('horizontal'),
            ]));

        $b->register(BlockDefinition::make('core/search-form')->supports(['spacing'])->label('Search box')->category('widgets')->icon('magnifying-glass')
            ->description('A box that searches the site and opens the results page.')
            ->fields(static function () use ($cms): array {
                $types = ['' => 'Everything'];
                foreach ($cms->content->types() as $type) {
                    if ($type->isSearchable()) {
                        $types[$type->name] = $type->plural();
                    }
                }

                return [
                    Field::text('label')->label('Label')->max(80)->default('Search')->option('bindable', false)->searchable(false),
                    Field::text('button')->label('Button text')->max(40)->default('Search')->option('bindable', false)->searchable(false),
                    Field::select('type', $types)->label('Search in')->default(''),
                ];
            }));

        $b->register(BlockDefinition::make('core/query')->supports(['spacing'])->label('Entry list')->category('data')->icon('cards')
            ->description('Lists entries and repeats the blocks inside it for each one. Bind their props to "entry" data.')
            ->fields(static function () use ($cms): array {
                $types = [];
                foreach ($cms->content->types() as $type) {
                    if ($type->getPath() !== null) {
                        $types[$type->name] = $type->plural();
                    }
                }

                return [
                    Field::select('type', $types ?: ['post' => 'Posts'])->label('Show')->required(),
                    Field::select('order', ['newest' => 'Newest first', 'oldest' => 'Oldest first', 'title' => 'By title'])->label('Order')->default('newest'),
                    Field::number('limit')->label('How many')->min(1)->max(24)->default(6)->option('bindable', false),
                    Field::select('related', ['' => 'All of them', 'links_here' => 'Those that link to the entry being viewed', 'linked' => 'Those the entry being viewed links to'])->label('Which ones')->default('')->option('bindable', false),
                    Field::boolean('exclude_current')->label('Leave out the entry being viewed')->default(true),
                    Field::boolean('pagination')->label('Show page links'),
                    Field::text('empty')->label('When there is nothing to show')->max(200)->option('bindable', false),
                    Field::select('width', ['content' => 'Text width', 'wide' => 'Wide', 'full' => 'Full width'])->label('Width')->default('wide'),
                ];
            })
            ->children()
            ->prepare(static fn(array $node, BlockContext $ctx, BlockRegistry $blocks) => self::query($cms, $node, $ctx, $blocks)));

        $b->register(BlockDefinition::make('core/entry-field')->supports(['color', 'spacing', 'type'])->label('Entry field')->category('data')->icon('article')
            ->description('The title, date, image or another field of the entry being viewed, or of each entry in a list.')
            ->fields([
                Field::select('field', ['title' => 'Title', 'date' => 'Publish date', 'author' => 'Author', 'excerpt' => 'Excerpt', 'image' => 'Featured image', 'custom' => 'Another field'])->label('Show')->required()->default('title'),
                Field::text('custom')->label('Field name')->max(64)->option('bindable', false)->option('findable', false)->help('For "Another field": the field\'s name, such as price.'),
                Field::select('tag', ['p' => 'Paragraph', 'h2' => 'Heading 2', 'h3' => 'Heading 3', 'h4' => 'Heading 4', 'span' => 'Inline', 'div' => 'Plain'])->label('Shown as')->default('p'),
                Field::boolean('link')->label('Link to the entry'),
            ])
            ->prepare(static fn(array $node, BlockContext $ctx) => self::entryField($node, $ctx)));

        CoreSectionBlocks::register($cms);
        CoreListBlocks::register($cms);
    }

    /**
     * Privacy-friendly embed address for a YouTube or Vimeo link.
     *
     * @return array{provider: string, src: string, id: string}|null
     */
    public static function embed(string $url, int $start = 0): ?array
    {
        $start = max(0, min(86400, $start));
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        parse_str((string) ($parts['query'] ?? ''), $query);
        if (!in_array($parts['scheme'] ?? '', ['http', 'https'], true)) {
            return null;
        }
        if (in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com', 'youtu.be'], true)) {
            $id = $host === 'youtu.be' ? ltrim($path, '/')
                : (is_string($query['v'] ?? null) ? $query['v'] : (preg_match('#^/(?:embed|shorts|live)/([^/]+)#', $path, $m) === 1 ? $m[1] : ''));
            if (preg_match('/^[A-Za-z0-9_-]{11}$/', $id) === 1) {
                return ['provider' => 'youtube', 'id' => $id, 'src' => "https://www.youtube-nocookie.com/embed/{$id}?autoplay=1&rel=0" . ($start > 0 ? "&start={$start}" : '')];
            }
        }
        if (in_array($host, ['vimeo.com', 'www.vimeo.com', 'player.vimeo.com'], true) && preg_match('#^/(?:video/)?(\d{1,12})(?:/|$)#', $path, $m) === 1) {
            return ['provider' => 'vimeo', 'id' => $m[1], 'src' => "https://player.vimeo.com/video/{$m[1]}?autoplay=1&dnt=1" . ($start > 0 ? "#t={$start}s" : '')];
        }

        return null;
    }

    /** @param array<string, mixed> $node */
    private static function query(Cms $cms, array $node, BlockContext $ctx, BlockRegistry $blocks): array
    {
        $p = $node['props'];
        $type = $cms->content->get((string) ($p['type'] ?? ''));
        $template = $node['children'];
        $node['children'] = [];
        $node['prepared'] = true;
        $node['props']['count'] = 0;
        $node['props']['pagination'] = null;
        $related = (string) ($p['related'] ?? '');
        if ($related !== '' && $ctx->entry === null) {
            return $node;     // "linked" lists follow the entry being viewed; without one there is nothing to show
        }
        if ($type === null || $type->getPath() === null || $ctx->loopDepth >= 2 || !$ctx->takeQuery()) {
            return $node;     // nested lists stop at two levels; a page runs at most BlockContext::MAX_QUERIES
        }
        $limit = max(1, min(24, (int) ($p['limit'] ?? 6)));
        \Kestello\Cms\Cache\PageTags::add('list:' . $type->name);
        // in the language of the entry being viewed, else of the page's address
        $q = Entry::query()->ofType($type->name)->inLanguage($ctx->entry?->language())->published()->with('author');
        match ($p['order'] ?? 'newest') {
            'oldest' => $q->orderBy('published_at')->orderBy('id'),
            'title' => $q->orderBy('title')->orderBy('id'),
            default => $q->orderByDesc('published_at')->orderByDesc('id'),
        };
        if ($related !== '') {
            $current = (int) $ctx->entry?->getKey();
            $q->whereExists(static fn(\Kestello\Framework\Database\QueryBuilder $s) => $related === 'links_here'
                ? $s->from(\Kestello\Cms\Content\EntryRelations::TABLE)->whereColumn('source_id', '=', 'entries.id')->where('target_id', $current)
                : $s->from(\Kestello\Cms\Content\EntryRelations::TABLE)->whereColumn('target_id', '=', 'entries.id')->where('source_id', $current));
        }
        if (($p['exclude_current'] ?? true) && $ctx->entry !== null) {
            $q->where('id', '!=', $ctx->entry->getKey());
        }
        if (!empty($p['pagination']) && $ctx->loopDepth === 0) {
            $result = $q->paginate($limit, $ctx->page());
            $items = $result['data']->all();
            $node['props']['pagination'] = ['page' => $result['current_page'], 'last' => max(1, $result['last_page'])];
        } else {
            $items = $q->limit($limit)->get()->all();
        }
        foreach ($items as $entry) {
            $node['children'][] = [
                'type' => 'core/query-item', 'id' => $node['id'] . 'e' . $entry->getKey(), 'component' => self::QUERY_ITEM,
                'props' => (object) ['url' => $entry->url($type)],
                'children' => $blocks->prepareNodes($template, $ctx->withEntry($entry, $type)),
            ];
        }
        $node['props']['count'] = count($items);

        return $node;
    }

    /** @param array<string, mixed> $node */
    private static function entryField(array $node, BlockContext $ctx): ?array
    {
        $entry = $ctx->entry;
        if (!$entry instanceof Entry) {
            return null;
        }
        $p = $node['props'];
        $which = (string) ($p['field'] ?? 'title');
        $kind = 'text';
        $value = match ($which) {
            'title' => $entry->title,
            'date' => \Kestello\Framework\Database\Orm\Caster::present($entry->published_at),
            'author' => $entry->author?->username,
            'excerpt' => $entry->excerpt,
            default => null,
        };
        if ($which === 'date') {
            $kind = 'date';
        }
        $name = $which === 'image' ? 'image' : ($which === 'custom' ? (string) ($p['custom'] ?? '') : null);
        if ($name !== null) {
            $field = $ctx->type?->getField($name);
            if ($field === null || $field->isInternal()) {
                return null;
            }
            $raw = $entry->field($name);
            if ($field->type === 'media') {
                $kind = 'media';
                $value = MediaPresenter::value($raw, false, MediaPresenter::many(array_map('intval', (array) $raw)));
            } elseif ($field->type === 'rich_text') {
                $kind = 'html';            // sanitized when the entry was saved, and again here: never trusted as HTML
                $value = is_string($raw) ? Html::sanitize($raw, 'rich-text') : null;
            } else {
                $value = is_scalar($raw) ? (string) $raw : null;
            }
        }
        if ($value === null || $value === '') {
            return null;
        }
        $node['props']['kind'] = $kind;
        $node['props']['value'] = $value;
        $node['props']['url'] = !empty($p['link']) && $ctx->type !== null ? $entry->url($ctx->type) : null;

        return $node;
    }
}
