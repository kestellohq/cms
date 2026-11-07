---
name: write-theme
description: Building a Kestello theme or child theme (manifest, Vue pages and layout, styles.json tokens, templates, parts and patterns, assets). Load it when creating or changing a theme, including content/themes/base.
---

# Writing a theme

A theme decides how the public site looks. It ships Vue pages and a layout, design tokens the site owner can change in **Appearance**, and block templates for content. `content/themes/base` is the theme every site starts with; [`base-child`](https://github.com/kestellohq/examples/tree/main/themes/base-child) in kestellohq/examples is a child theme that overrides two pages.

## 1. Scaffold

```sh
php bin/cms make:theme acme                  # a full theme in content/themes/acme/
php bin/cms make:theme acme --parent=base    # a child theme
php bin/cms theme:activate acme
```

## 2. The manifest

`theme.json` (from `content/themes/base/theme.json`):

```json
{
  "slug": "base",
  "name": "Base",
  "version": "1.1.0",
  "description": "One sentence about the look.",
  "requires": { "core": "^0.1" },
  "namespace": "Themes\\Base\\",
  "provider": "Themes\\Base\\Theme",
  "stylesheets": ["css/theme.css"]
}
```

`stylesheets` are paths under `assets/` loaded on every page the theme renders. A child theme adds `"parent": "base"` and may leave out the namespace and provider when it has no PHP (see `theme.json` in the `base-child` example).

## 3. The provider (optional)

A `ThemeProvider` registers theme routes and menu locations (`content/themes/base/src/Theme.php`):

```php
final class Theme extends ThemeProvider
{
    public function boot(Cms $cms): void
    {
        $cms->routes->get('/', [PageController::class, 'home'])->name('home');
        $cms->menus->location('primary', 'Main navigation', 'Across the top of every page.');
    }
}
```

Controllers return `Page::render('Home', ['title' => 'Home'])`. Theme components have no prefix: `Home` is `resources/js/pages/Home.vue`.

## 4. Pages and the layout

```vue
<script>
export default { layout: 'Site' };            // resources/js/layouts/Site.vue
</script>

<script setup>
import { Head, Link, usePage } from '@kestello/app';
const page = usePage();                         // page.props.site, page.props.menus.primary, …
</script>

<template>
    <Head :title="page.props.title" />
    <main>…</main>
</template>
```

- Menus built in the admin arrive as `page.props.menus.<location>`.
- Content pages (entries) are rendered by core with the theme's templates (below); the layout wraps them.
- Fonts are self-hosted under `assets/fonts/` (no CDN fonts). Images live under `assets/img/`.
- Text the theme prints itself uses `__()` from `@kestello/sdk`, with the theme's slug as the domain (`useI18n('acme')`). Its PHP strings use `__('…', domain: 'acme')`. The theme's packs go in `resources/lang/`, and `php bin/cms i18n:extract acme` writes its template.

**Child themes** override any file by path: `resources/js/pages/About.vue` replaces the parent's About page, and `resources/js/pages/@hello/Greeting.vue` replaces the hello plugin's `@hello/Greeting` page. Everything else is inherited.

## 5. Design tokens: styles.json

Every token becomes a custom property (`--plate`, `--radius`, `--font`) that the owner can change in **Appearance > Styles** without a build. Write the theme's CSS against these properties. Format (`core/src/Cms/Design/ThemeStyles.php`):

```json
{
  "groups": { "colors": "Colours", "shape": "Shape" },
  "tokens": {
    "plate": { "type": "color", "label": "Enamel", "group": "colors", "value": "#1e3a8a", "help": "The colour of every plate." },
    "radius": { "type": "size", "label": "Corners", "group": "shape", "value": 18, "unit": "px", "min": 0, "max": 40 },
    "font": { "type": "font", "label": "Text", "group": "type", "value": "archivo" }
  },
  "fonts": { "archivo": { "label": "Archivo", "stack": "'Archivo', Arial, sans-serif", "preload": "fonts/archivo-latin.woff2" } },
  "contrast": [{ "fg": "wall-ink", "bg": "wall", "label": "Text on the page" }],
  "variations": { "night": { "label": "Night", "values": { "plate": "#0f766e" } } },
  "presets": { "color": { "plate": { "label": "Enamel", "value": "var(--plate)" } }, "space": { "sm": { "label": "S", "value": "1rem" } } }
}
```

Token types: `color`, `size`, `number`, `font`. `presets` are what editors pick from when styling a block (colours, space, font sizes, radius). A child theme's `styles.json` adds to and overrides its parent's. Values typed in the admin are validated by type before they reach CSS.

## 6. Templates, parts and patterns

Block trees as JSON files (`core/src/Cms/Design/ThemeTemplates.php`):

| File | Used for |
| --- | --- |
| `templates/entry-post.json` | Every post |
| `templates/entry.json` | Any entry without a template of its own |
| `templates/wide.json` | A custom template entries can choose (`"for": ["page"]`) |
| `parts/header.json` | Pieces templates include through the `core/template-part` block |
| `patterns/hero.json` | Offered in the block library; `"layout": true` makes it a whole-page layout |

A pattern file (from `content/themes/base/patterns/call-to-action.json`):

```json
{
  "title": "Call to action",
  "description": "A dark panel with a heading, a line of text and a button.",
  "category": "Calls to action",
  "blocks": [{ "type": "core/heading", "id": "cta2", "props": { "text": "Order for the weekend", "level": "2" }, "children": [] }]
}
```

A child theme's file replaces its parent's with the same name, and the site's published version (edited in the admin) replaces the file.

## 7. Build and check

```sh
node core/build/build.mjs content/themes/acme     # writes assets/build/, commit it
npm run lint
npm run test:php                                   # tests/Content/ThemesTest.php, TemplatesTest.php, StylesTest.php cover the machinery
```

Theme PHP changes get a test like any other change (`../testing/SKILL.md`). Check pages at phone width, and in both the theme's light and dark variations when it has them.

## Done when

- [ ] `theme.json` validates; a child theme names its parent.
- [ ] CSS reads the tokens, so **Appearance > Styles** changes the look without a build.
- [ ] No CDN fonts or scripts; fonts are in `assets/fonts/` with their license.
- [ ] Text the theme prints goes through `__()`, and the `.pot` is current.
- [ ] `assets/build/` is rebuilt and committed; tests and lint pass.

Related: `../write-plugin/SKILL.md`, `../add-block/SKILL.md`, `../i18n/SKILL.md`, `../architecture/SKILL.md`.
