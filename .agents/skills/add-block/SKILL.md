---
name: add-block
description: Registering a block for the visual editor (server definition, Vue component, tests), in core or in a plugin or theme. Load it when adding or changing a block.
---

# Adding a block

A block is a Vue component plus a server definition. The definition says what fields (props) it has, where it may go, which design settings it takes, and how to prepare it before rendering. Props are content fields, so they validate, sanitize and get editor inputs like entry fields do.

## 1. Define it

Register in a provider's `boot()` (core blocks live in `core/src/Cms/Blocks/CoreBlocks.php`, `CoreListBlocks.php` and `CoreSectionBlocks.php`). Adapted from `src/CatalogProvider.php` in the [catalog example](https://github.com/kestellohq/examples/tree/main/plugins/catalog):

```php
use Kestello\Cms\Blocks\BlockContext;
use Kestello\Cms\Blocks\BlockDefinition;
use Kestello\Cms\Content\Field;

$cms->blocks->register(BlockDefinition::make('catalog/product-grid')
    ->label('Product grid')->category('data')->icon('grid-four')
    ->description('Products as cards with image, name and price.')
    ->fields(static fn(): array => [
        Field::select('sort', ['newest' => 'Newest first', 'price' => 'Price'])->label('Order')->default('newest'),
        Field::number('limit')->label('How many')->option('integer', true)->min(1)->max(24)->default(8),
        Field::boolean('price')->label('Show prices')->default(true),
    ])
    ->example(['limit' => 4, 'price' => true])
    ->prepare(static function (array $node, BlockContext $ctx): ?array {
        $node['props'] = (array) $node['props'] + ['items' => []];
        return $node; // or null to hide the block
    }));
```

Reference (`core/src/Cms/Blocks/BlockDefinition.php`):

| Method | Meaning |
| --- | --- |
| `make('<owner>/<name>')` | The name. A plugin or theme must use its own slug (`BlockRegistry::register()` throws otherwise); core uses `core/`. |
| `label()`, `description()` | Shown in the library. Write plain English: they are translated in the owner's domain when sent to the editor, and `bin/cms i18n:extract` picks them up. |
| `category()` | One of `BlockDefinition::CATEGORIES`: `text`, `media`, `layout`, `data`, `widgets`. |
| `icon()`, `iconBy($field, $icons)` | An icon name, or one per value of a field (`Icons` lists the names). |
| `fields()` | A list of `Field`, or a closure when choices are only known after boot. |
| `children(true \| [types])`, `parents([types])` | What it may hold, and where it may go. |
| `starter([...])` | Blocks a new one starts with. Call `children()` first. |
| `supports([...])` | Design settings from `BlockDesign::GROUPS`: `color`, `spacing`, `type`, `border`. |
| `example([...])` | Props for the library preview. |
| `prepare(fn($node, BlockContext $ctx, BlockRegistry $blocks): ?array)` | Runs on the server before rendering: add data, or return null to hide. |
| `component()` | Only when the default name doesn't fit. |

Keep `prepare()` cheap: list blocks call `$ctx->takeQuery()` so a page runs a bounded number of queries, and they add cache tags with `PageTags::add()`.

## 2. Write the component

The default component of `acme/pricing-table` is `@acme/blocks/PricingTable`, which is `resources/js/pages/blocks/PricingTable.vue` in the package (core: `core/resources/js/pages/blocks/`). It receives the props as Vue props:

```vue
<script setup>
defineProps({ items: { type: Array, default: () => [] }, price: { type: Boolean, default: true } });
</script>

<template>
    <ul class="catalog-grid"><li v-for="p in items" :key="p.id">{{ p.title }}</li></ul>
</template>
```

- Text the block prints itself (not typed by an editor) uses `__()` from `@kestello/sdk`, or `useI18n('<slug>')` in a plugin.
- Editable text fields carry `data-k-field="<prop>"` so the canvas edits them in place (see `core/resources/js/pages/blocks/Heading.vue`; `useCanvas()` from `@kestello/app` tells you the block is in the editor).
- Scoped styles, no global selectors. Blocks render on the public site: follow the theme's custom properties.

Rebuild: `node core/build/build.mjs <package dir>` (or `npm run build:core` for core). Commit the changed `assets/build/`.

## 3. Test it

PHP, in the style of `tests/Content/SectionBlocksTest.php` and `tests/Content/BlocksTest.php`:

```php
[$clean, $errors] = $this->cms->blocks->validate([['type' => 'acme/pricing-table', 'props' => ['limit' => 99], 'children' => []]]);
$this->assertNotSame([], $errors, 'limit is capped at 24');

$prepared = $this->cms->blocks->prepare($tree, new BlockContext());
```

Cover: valid and invalid props, where it may and may not go (`parents`, `children`), what `prepare()` adds or hides, and for plugin blocks that registration under another owner's prefix is refused (`ExecutionContext::run('plugin:acme', fn() => ...)`). Editor-side logic in pure JS modules gets a `tests/js/*.test.mjs` test.

```sh
npm run test:php                                   # or: bin/cms test tests/Content --filter=Pricing
npm test
npm run lint
npm run i18n                                       # core blocks: refresh the template
```

## Done when

- [ ] The name uses the owner's prefix and a valid category.
- [ ] Labels and help are plain English, and the component's own text goes through `__()`.
- [ ] Validation, placement and `prepare()` have PHP tests.
- [ ] `assets/build/` is rebuilt and committed.
- [ ] `npm run lint`, `npm test` and the PHP tests pass.
- [ ] A changelog entry under `## [Unreleased]` if people will notice it.

Related: `../add-field-type/SKILL.md`, `../write-plugin/SKILL.md`, `../testing/SKILL.md`, `../i18n/SKILL.md`.
