---
name: admin-page
description: Adding a screen to the Kestello admin (registration, menu entry, capability, the Vue page built on @kestello/ui and @kestello/sdk, and the design rules it must follow). Load it when adding or changing an admin page, dashboard widget, column or panel.
---

# Adding an admin page

Admin pages are Vue single-file components rendered inside the admin layout. They never render server forms: every read and write goes through `/api/v1`, so the endpoint comes first (`../rest-endpoint/SKILL.md`) and the screen is a client of it.

## 1. Register the page

In a provider's `boot()` (core pages: `core/src/Cms/Admin/AdminModule.php` and each area's module):

```php
// AdminRegistry::page(string $path, string $component, string $title, string $capability = 'admin.access', ?array $menu = null): string
$cms->admin->page('', '@hello/admin/Greetings', 'Greetings', 'hello.manage', ['label' => 'Greetings', 'icon' => 'smiley', 'group' => 'plugins']);
$cms->admin->page('greetings/{id}', '@hello/admin/Greeting', 'Greeting', 'hello.manage');   // no menu entry
```

- Core pages are named `@core/…` and live at `/admin/<path>`. A plugin's are named `@<slug>/…` and live at `/admin/p/<slug>/<path>`. Path parameters (`{id}`) arrive in the page's `params` prop; pages with parameters can't be menu entries.
- The capability hides the page and its menu entry. It is cosmetic for safety: the API enforces every check again.
- `menu` keys: `label`, `icon` (a Phosphor icon name), `group` (`main`, `content`, `people`, `appearance`, `plugins`, `system`, or one made with `$cms->admin->group()`), `order`.
- The title and labels are plain English. They are translated in the owner's domain when sent to the admin.

Smaller extension points on existing screens (`AdminRegistry::POINTS`):

```php
$cms->admin->widget('dashboard', '@hello/admin/GreetingsWidget', title: 'Greetings', size: 'quarter', capability: 'hello.manage');
$cms->admin->column('users.list', 'hello_greetings', label: 'Greeted', capability: 'hello.manage', align: 'end');
$cms->admin->slot('users.edit.panels', '@hello/admin/SayHello', capability: 'hello.manage');
$cms->admin->replace('editor.richtext', '@acme/RichEditor');   // one of AdminRegistry::REPLACEABLE
```

## 2. Write the Vue page

The component name maps to a file: `@hello/admin/Greetings` is `resources/js/pages/admin/Greetings.vue` in the plugin, `@core/admin/system/Queue` is `core/resources/js/pages/admin/system/Queue.vue`. Adapted from `resources/js/pages/admin/Greetings.vue` in the [hello example](https://github.com/kestellohq/examples/tree/main/plugins/hello):

```vue
<script setup>
import { ref } from 'vue';
import { Head } from '@kestello/app';
import { ApiError, notify, useApi, useResource, useI18n } from '@kestello/sdk';
import { KButton, KCard, KField, KInput, KPageHeader, KTable, KTime } from '@kestello/ui';

const { __ } = useI18n('hello');          // core pages import __ from '@kestello/sdk' instead
defineOptions({ layout: 'Admin' });

const api = useApi();
const greetings = useResource('/hello/greetings', { query: { limit: 25 } });
const name = ref('');
const error = ref('');

async function greet() {
    try {
        const { data } = await api.post('/hello/greetings', { name: name.value.trim() });
        notify.success(data.phrase);
        await greetings.reload({ silent: true });
    } catch (e) {
        if (e instanceof ApiError && e.status === 422) error.value = e.fieldErrors().name ?? e.message;
        else notify.error(e);
    }
}
</script>

<template>
    <Head :title="__('Greetings')" />
    <KPageHeader :title="__('Greetings')" :description="__('Everyone the hello plugin has greeted, newest first.')" />
    <KCard :title="__('Recent greetings')" flush>
        <KTable
            :columns="[{ key: 'phrase', label: __('Greeting') }, { key: 'created_at', label: __('When'), align: 'end' }]"
            :rows="greetings.data.value ?? []"
            :loading="greetings.loading.value"
            :empty="__('Nobody has been greeted yet.')"
        >
            <template #cell-created_at="{ row }"><KTime :value="row.created_at" /></template>
        </KTable>
    </KCard>
</template>
```

What to reach for:

| Need | Use |
| --- | --- |
| API calls with the session and CSRF header | `useApi()` (`get`, `post`, `put`, `patch`, `delete`); errors are `ApiError` (RFC 9457) |
| A list or record that loads and reloads | `useResource(path, { query, poll })` |
| Feedback | `notify.success()`, `notify.error(e)` |
| Ask before something destructive | `await confirm({ title, message, confirmLabel, danger: true })` |
| Hide what the user can't do | `useCan()('capability')` (cosmetic) |
| Components | `@kestello/ui`: `KPageHeader`, `KCard`, `KTable`, `KField`, `KInput`, `KSelect`, `KSwitch`, `KButton`, `KModal`, `KTabs`, `KEmpty`, `KSchemaForm`, … (`core/resources/js/ui/index.js`) |
| Translation | `__()` from `@kestello/sdk`, or `useI18n('<slug>')` in plugins; plural messages in a computed property |

Rebuild: `npm run build:core` for core, `node core/build/build.mjs <package dir>` for a package. A package commits its `assets/build/`; core's build is not committed.

## 3. Design rules

The admin follows [admin-ui-design](../admin-ui-design/SKILL.md). The rules reviewers check first:

- No eyebrow labels (small capitalised text above a heading).
- No emoji, anywhere.
- No placeholder text in inputs: put guidance in the field's help.
- No coloured `border-left` wider than 1px.
- Hierarchy comes from spacing and soft rules (`var(--k-rule-soft)`), not thick or high-contrast separators.
- Use the `k-*` classes and `--k-*` custom properties from `core/resources/assets/css/admin-ui.css`; no inline styles for things a class covers, no CDN fonts or scripts.
- Every control has a visible label; check light and dark themes and a narrow window.
- Field labels and button text use the exact wording of the rest of the admin.

## 4. Tests

- The endpoint behind the page has PHP tests (`../rest-endpoint/SKILL.md`).
- Logic worth testing goes in a pure JS module next to the page and gets `tests/js/<name>.test.mjs` (see `tests/js/content-timetable.test.mjs`).
- Registration rules (wrong component prefix, duplicate path) are covered in `tests/Admin/AdminRegistryTest.php`; extend it when you change `AdminRegistry`.

```sh
npm test
npm run lint
npm run test:php
npm run i18n          # core: refresh core/resources/lang/kestello.pot
```

## Done when

- [ ] The page is registered with the right capability, and its data comes from `/api/v1`.
- [ ] Every string goes through `__()`; the template is current.
- [ ] It follows the design rules in light and dark themes.
- [ ] Built assets are committed; lint and tests pass.

Related: `../admin-ui-design/SKILL.md`, `../rest-endpoint/SKILL.md`, `../i18n/SKILL.md`, `../write-plugin/SKILL.md`.
