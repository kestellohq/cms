<?php

declare(strict_types=1);

namespace Kestello\Cms\Design;

use Kestello\Cms\Blocks\BlockContext;
use Kestello\Cms\Blocks\BlockDefinition;
use Kestello\Cms\Blocks\BlockRegistry;
use Kestello\Cms\Cms;
use Kestello\Cms\Content\Field;

/**
 * Blocks that make templates work: a template part, a synced
 * pattern, and the content of the entry being viewed.
 */
final class DesignBlocks
{
    public static function register(Cms $cms): void
    {
        $templates = static fn(): ThemeTemplates => $cms->container->make(ThemeTemplates::class);

        $cms->blocks->register(BlockDefinition::make('core/template-part')->label('Template part')->category('layout')->icon('layout')
            ->description('A shared header, footer or other part from Appearance → Templates.')
            ->fields(static function () use ($templates): array {
                $choices = [];
                try {
                    foreach ($templates()->all('part') as $p) {
                        $choices[] = ['value' => $p['key'], 'label' => $p['title']];
                    }
                } catch (\Throwable) {
                }

                // a name rather than a fixed choice: parts come and go; the server checks the name when a template is saved
                return [Field::text('part')->label('Part')->required()->max(61)->option('bindable', false)->option('suggest', $choices)];
            })
            ->prepare(static fn(array $node, BlockContext $ctx, BlockRegistry $blocks) => self::include($templates(), 'part', (string) ($node['props']['part'] ?? ''), $node, $ctx, $blocks)));

        $cms->blocks->register(BlockDefinition::make('core/pattern')->label('Synced pattern')->category('layout')->icon('star')
            ->description('A saved pattern that stays the same everywhere it is used: change it once in Appearance → Templates.')
            ->fields(static function () use ($templates): array {
                $choices = [];
                try {
                    foreach ($templates()->all('pattern') as $p) {
                        if (!empty($p['data']['synced'])) {
                            $choices[] = ['value' => $p['key'], 'label' => $p['title']];
                        }
                    }
                } catch (\Throwable) {
                }

                return [Field::text('pattern')->label('Pattern')->required()->max(61)->option('bindable', false)->option('suggest', $choices)];
            })
            ->prepare(static fn(array $node, BlockContext $ctx, BlockRegistry $blocks) => self::include($templates(), 'pattern', (string) ($node['props']['pattern'] ?? ''), $node, $ctx, $blocks)));

        $cms->blocks->register(BlockDefinition::make('core/entry-content')->label('Entry content')->category('data')->icon('article')
            ->description('In a template: the body of the entry being viewed.')
            ->fields([Field::text('field')->label('Field name')->max(64)->option('bindable', false)->help('Empty: the entry\'s first blocks or rich-text field.')])
            ->prepare(static fn(array $node, BlockContext $ctx, BlockRegistry $blocks) => self::entryContent($node, $ctx, $blocks)));
    }

    /** @param array<string, mixed> $node */
    private static function include(ThemeTemplates $templates, string $kind, string $key, array $node, BlockContext $ctx, BlockRegistry $blocks): ?array
    {
        $doc = $key === '' ? null : $templates->get($kind, $key, $ctx->drafts);
        $inner = $ctx->included();
        if ($doc === null || $inner === null || ($kind === 'pattern' && empty($doc['synced']))) {
            return null;
        }
        $node['children'] = $blocks->prepare($doc['blocks'] ?? [], $inner);
        $node['prepared'] = true;
        if ($kind === 'part') {
            $node['props']['area'] = $doc['area'] ?? 'general';
        }

        return $node['children'] === [] ? null : $node;
    }

    /** @param array<string, mixed> $node */
    private static function entryContent(array $node, BlockContext $ctx, BlockRegistry $blocks): ?array
    {
        $entry = $ctx->entry;
        $type = $ctx->type;
        if (!$ctx->template || $entry === null || $type === null) {
            return null;      // only templates show an entry's body, and only once
        }
        $wanted = trim((string) ($node['props']['field'] ?? ''));
        $field = null;
        foreach ($type->getFields() as $f) {
            if (!$f->isInternal() && in_array($f->type, ['blocks', 'rich_text'], true) && ($wanted === '' || $f->name === $wanted)) {
                $field = $f;
                break;
            }
        }
        if ($field === null) {
            return null;
        }
        $node['prepared'] = true;
        if ($field->type === 'blocks') {
            $node['children'] = $blocks->prepare($entry->field($field->name) ?? [], $ctx->forBody(), is_array($field->getOption('allowed')) ? $field->getOption('allowed') : null);
            $node['props'] = ['kind' => 'blocks', 'field' => $field->name];     // the field, so the editor's canvas can swap in unsaved changes

            return $node;
        }
        $html = $entry->field($field->name);
        $node['children'] = [];
        $node['props'] = ['kind' => 'html', 'field' => $field->name, 'html' => is_string($html) ? $html : ''];     // sanitized when saved

        return $html === null || $html === '' ? null : $node;
    }
}
