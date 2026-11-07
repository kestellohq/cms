<?php

declare(strict_types=1);

namespace Kestello\Cms\Blocks;

use Kestello\Cms\Cms;
use Kestello\Cms\Content\Field;

/**
 * Core blocks that show several things in order: gallery, table, icon list
 * and social links. Vue halves: resources/js/pages/blocks.
 *
 * @internal CoreBlocks
 */
final class CoreListBlocks
{
    /** A new table: a heading row and two empty rows of three columns. */
    public const array BLANK_TABLE = ['head' => true, 'rows' => [['', '', ''], ['', '', ''], ['', '', '']]];

    public static function register(Cms $cms): void
    {
        $b = $cms->blocks;
        $width = static fn(string $default) => Field::select('width', ['content' => 'Text width', 'wide' => 'Wide', 'full' => 'Full width'])->label('Width')->default($default);

        $b->register(BlockDefinition::make('core/gallery')->supports(['spacing'])->label('Gallery')->category('media')->icon('squares-four')
            ->description('Images in a grid. Visitors can open them larger and go through them one by one.')
            ->fields([
                Field::image('images', true)->label('Images')->required(),
                Field::select('columns', ['2' => '2', '3' => '3', '4' => '4', '5' => '5', '6' => '6'])->label('Columns')->default('3')->width('half')->help('Phones show two.'),
                Field::select('ratio', ['1/1' => 'Square', '4/3' => '4:3', '3/2' => '3:2', '16/9' => '16:9', '3/4' => '3:4 (tall)', 'auto' => 'Original'])->label('Shape')->default('1/1')->width('half'),
                Field::select('gap', ['sm' => 'Small', 'md' => 'Medium', 'lg' => 'Large', 'none' => 'None'])->label('Space between')->default('md')->width('half'),
                Field::boolean('captions')->label('Show captions')->help('The captions from the media library.')->width('half'),
                Field::boolean('lightbox')->label('Open larger when clicked')->default(true),
                $width('wide'),
            ]));

        $b->register(BlockDefinition::make('core/table')->supports(['color', 'spacing', 'type', 'border'])->label('Table')->category('text')->icon('table')
            ->description('Rows and columns, such as prices or opening hours. Paste from a spreadsheet to fill it.')
            ->fields([
                Field::table('table')->label('Table')->required()->default(self::BLANK_TABLE)->option('bindable', false),
                Field::text('caption')->label('Caption')->max(300)->help('Says what the table shows. Screen readers read it first.'),
                Field::boolean('striped')->label('Shade every other row')->width('half'),
                Field::boolean('side')->label('First column is headings')->width('half'),
                $width('content'),
            ]));

        $b->register(BlockDefinition::make('core/icon-list')->supports(['color', 'spacing', 'type', 'border'])->label('Icon list')->category('text')->icon('list-checks')
            ->description('Short points, each with an icon, such as what is included.')
            ->fields([
                Field::repeater('items', [
                    Field::select('icon', Icons::CONTENT)->label('Icon')->default('check')->width('half'),
                    Field::text('text')->label('Text')->required()->max(300),
                ])->label('Items')->required()->min(1)->max(60)->option('singular', 'item'),
                Field::select('columns', ['1' => 'One', '2' => 'Two', '3' => 'Three'])->label('Columns')->default('1')->width('half'),
                Field::select('tone', ['accent' => 'Accent colour', 'text' => 'Same as the text'])->label('Icon colour')->default('accent')->width('half'),
            ])
            ->example(['items' => [['icon' => 'check', 'text' => 'Free delivery'], ['icon' => 'check', 'text' => 'Returns within 30 days']]]));

        $b->register(BlockDefinition::make('core/social')->supports(['color', 'spacing'])->label('Social links')->category('widgets')->icon('share-network')
            ->description('Links to your profiles, shown as their icons.')
            ->fields([
                Field::repeater('items', [
                    Field::select('network', Icons::SOCIAL)->label('Where')->required()->default('instagram')->width('half'),
                    Field::link('url')->label('Address')->required()->help('Your profile\'s address. For email: mailto:you@example.com'),
                ])->label('Links')->required()->min(1)->max(30)->option('singular', 'link'),
                Field::select('size', ['sm' => 'Small', 'md' => 'Medium', 'lg' => 'Large'])->label('Size')->default('md')->width('half'),
                Field::select('look', ['icons' => 'Icons', 'labels' => 'Icons and names'])->label('Show')->default('icons')->width('half'),
                Field::select('align', ['start' => 'Left', 'center' => 'Centre', 'end' => 'Right'])->label('Alignment')->default('start')->width('half'),
            ])
            ->prepare(static fn(array $node) => self::social($node)));
    }

    /**
     * Each link learns its network's name (what screen readers say for an icon,
     * and the text shown with "Icons and names"); links without an address are left out.
     *
     * @param array<string, mixed> $node
     * @return array<string, mixed>|null
     */
    public static function social(array $node): ?array
    {
        $items = [];
        foreach ((array) ($node['props']['items'] ?? []) as $item) {
            $item = (array) $item;
            $network = (string) ($item['network'] ?? '');
            if (!isset(Icons::SOCIAL[$network]) || trim((string) ($item['url'] ?? '')) === '') {
                continue;
            }
            $items[] = ['network' => $network, 'url' => (string) $item['url'], 'label' => Icons::SOCIAL[$network]];
        }
        if ($items === []) {
            return null;
        }
        $node['props']['items'] = $items;

        return $node;
    }
}
