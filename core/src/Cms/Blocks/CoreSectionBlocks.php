<?php

declare(strict_types=1);

namespace Kestello\Cms\Blocks;

use Kestello\Cms\Cms;
use Kestello\Cms\Content\Field;

/**
 * Core blocks that shape a section and hold other blocks: cover, media and
 * text, tabs (tab) and a row of buttons. Vue halves: resources/js/pages/blocks.
 *
 * @internal CoreBlocks
 */
final class CoreSectionBlocks
{
    public static function register(Cms $cms): void
    {
        $b = $cms->blocks;
        $width = static fn(string $default) => Field::select('width', ['content' => 'Text width', 'wide' => 'Wide', 'full' => 'Full width'])->label('Width')->default($default);
        $place = static fn(string $name, string $label, array $choices, string $default) => Field::select($name, $choices)->label($label)->default($default)->width('half');

        $b->register(BlockDefinition::make('core/cover')->supports(['color', 'spacing', 'border'])->label('Cover')->category('layout')->icon('image-square')
            ->description('A banner: blocks over an image or a colour, such as a heading and a button at the top of a page.')
            ->fields([
                Field::image('image')->label('Background image')
                    ->help('Cropped to fit, keeping the focal point chosen in the media library. Or leave it empty and pick a background colour under Style.'),
                $place('shade', 'Shade', ['dark' => 'Dark', 'light' => 'Light', 'none' => 'None'], 'dark')->help('Over the image, so text stays readable. Has no effect until an image is chosen.'),
                Field::number('strength')->label('Shade strength (%)')->min(0)->max(90)->default(45)->width('half'),
                $place('height', 'Height', ['auto' => 'Fit the content', 'short' => 'Short', 'medium' => 'Medium', 'tall' => 'Tall', 'screen' => 'The whole screen'], 'medium'),
                $place('valign', 'Content, up and down', ['start' => 'Top', 'center' => 'Middle', 'end' => 'Bottom'], 'center'),
                $place('align', 'Content, across', ['start' => 'Left', 'center' => 'Centre', 'end' => 'Right'], 'center'),
                Field::select('tag', ['section' => 'Section', 'header' => 'Header', 'div' => 'Plain'])->label('Role on the page')->help('Tells screen readers and search engines what this part of the page is. It does not change the look.')->default('section')->width('half'),
                $width('full'),
            ])
            ->children()
            ->starter(['core/heading']));

        $b->register(BlockDefinition::make('core/media-text')->supports(['color', 'spacing', 'border'])->label('Media and text')->category('layout')->icon('square-split-horizontal')
            ->description('An image beside text. They stack on phones, the image first.')
            ->fields([
                Field::image('image')->label('Image')->required(),
                Field::text('alt')->label('Alt text')->max(1000)->help('Leave empty to use the alt text from the media library.'),
                $place('side', 'Image on the', ['start' => 'Left', 'end' => 'Right'], 'start'),
                $place('share', 'Image width', ['50' => 'Half', '40' => 'Less than half', '60' => 'More than half'], '50'),
                $place('valign', 'Text, up and down', ['center' => 'Middle', 'start' => 'Top', 'end' => 'Bottom'], 'center'),
                Field::boolean('fill')->label('Image fills its side')->help('Cropped to the height of the text instead of shown whole.')->width('half'),
                $width('wide'),
            ])
            ->children()
            ->starter(['core/heading', 'core/text']));

        $b->register(BlockDefinition::make('core/tabs')->supports(['color', 'spacing', 'border'])->label('Tabs')->category('layout')->icon('tabs')
            ->description('Content in panels, one shown at a time. Visitors switch with the tabs or the arrow keys.')
            ->fields([
                Field::select('look', ['line' => 'Underlined', 'pills' => 'Buttons'])->label('Style')->default('line')->width('half'),
                Field::select('align', ['start' => 'Left', 'center' => 'Centre'])->label('Tabs, across')->default('start')->width('half'),
                $width('content'),
            ])
            ->children(['core/tab'])
            ->prepare(static fn(array $node, BlockContext $ctx, BlockRegistry $blocks) => self::tabs($node, $ctx, $blocks)));

        $b->register(BlockDefinition::make('core/tab')->label('Tab')->category('layout')->icon('rectangle')
            ->fields([Field::text('label')->label('Tab name')->required()->max(80)])
            ->parents(['core/tabs'])->children()
            ->example(['label' => 'Details']));

        $b->register(BlockDefinition::make('core/buttons')->supports(['spacing'])->label('Buttons')->category('text')->icon('rows')
            ->description('Buttons side by side. They wrap onto the next line when there is no room.')
            ->fields([Field::select('align', ['start' => 'Left', 'center' => 'Centre', 'end' => 'Right', 'between' => 'Spread out'])->label('Alignment')->default('start')])
            ->children(['core/button'])
            ->starter(['core/button']));
    }

    /**
     * Tabs need their panels' names before they are drawn: the panels are prepared
     * here (bindings resolved, hidden ones left out), then each learns its place.
     *
     * @param array<string, mixed> $node
     * @return array<string, mixed>|null
     */
    private static function tabs(array $node, BlockContext $ctx, BlockRegistry $blocks): ?array
    {
        $panels = $blocks->prepareNodes($node['children'], $ctx);
        if ($panels === []) {
            return null;
        }
        $labels = [];
        foreach ($panels as $i => $panel) {
            $panel['props']->index = $i;
            $panel['props']->group = $node['id'];
            $labels[] = trim((string) ($panel['props']->label ?? '')) ?: 'Tab ' . ($i + 1);
            $panels[$i] = $panel;
        }
        $node['children'] = $panels;
        $node['prepared'] = true;
        $node['props']['tabs'] = $labels;
        $node['props']['group'] = $node['id'];

        return $node;
    }
}
