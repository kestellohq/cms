<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { ApiError, confirm, notify, useApi, __ } from '@kestello/sdk';
import { KAlert, KBadge, KButton, KField, KIcon, KInput, KModal, KPageHeader, KSelect, KSwitch } from '@kestello/ui';

defineOptions({ layout: 'Admin' });

/**
 * Appearance → Themes: switch themes, make a new one from the current
 * look, and move themes between sites as a zip. Themes made here hold no
 * code (tokens, CSS, templates, parts, patterns and fonts), so importing
 * one can't run anything on the server.
 */
const api = useApi();
const data = ref(null);
const loadError = ref(null);
const busy = ref('');

async function load() {
    try {
        data.value = (await api.get('/themes')).data;
    } catch (e) {
        loadError.value = e;
    }
}
load();

const names = computed(() => Object.fromEntries((data.value?.themes ?? []).map((t) => [t.slug, t.name])));
const pendingSwitch = computed(() => data.value && data.value.running && data.value.active !== data.value.running);

async function activate(theme) {
    busy.value = `activate:${theme.slug}`;
    try {
        data.value = (await api.post(`/themes/${theme.slug}/activate`)).data;
        notify.success(__('The site now uses {theme}.', { theme: theme.name }));
    } catch (e) {
        notify.error(e);
    } finally {
        busy.value = '';
    }
}

async function remove(theme) {
    if (
        !(await confirm({
            title: __('Delete {theme}?', { theme: theme.name }),
            message: __("Its files and this site's changes to it are deleted. Export it first to keep a copy."),
            confirmLabel: __('Delete theme'),
            danger: true,
        }))
    )
        return;
    busy.value = `delete:${theme.slug}`;
    try {
        data.value = (await api.delete(`/themes/${theme.slug}`)).data;
        notify.success(__('{theme} was deleted.', { theme: theme.name }));
    } catch (e) {
        notify.error(e);
    } finally {
        busy.value = '';
    }
}

async function exportTheme(theme) {
    busy.value = `export:${theme.slug}`;
    try {
        const response = await fetch(`/api/v1/themes/${theme.slug}/export`, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        if (!response.ok) {
            const problem = await response.json().catch(() => ({ title: response.statusText }));
            throw new ApiError(response.status, problem);
        }
        const href = URL.createObjectURL(await response.blob());
        Object.assign(document.createElement('a'), { href, download: `${theme.slug}-${new Date().toISOString().slice(0, 10)}.zip` }).click();
        setTimeout(() => URL.revokeObjectURL(href), 1000);
    } catch (e) {
        notify.error(e);
    } finally {
        busy.value = '';
    }
}

// ── new theme ────────────────────────────────────────────────────────
const creating = ref(false);
const form = reactive({ name: '', slug: '', description: '', parent: '', variation: '', from_site: true, activate: false });
const formErrors = ref({});
const saving = ref(false);
const slugify = (s) =>
    s
        .toLowerCase()
        .normalize('NFKD')
        .replace(/[̀-ͯ]/g, '')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 64);
let slugTouched = false;
watch(
    () => form.name,
    (n) => {
        if (!slugTouched) form.slug = slugify(n);
    },
);
const parentOptions = computed(() =>
    (data.value?.themes ?? []).map((t) => ({ value: t.slug, label: t.name + (t.slug === data.value.running ? ` (${__('running')})` : '') })),
);
const fromRunning = computed(() => form.parent === data.value?.running);
const variationOptions = computed(() => [
    { value: '', label: __('As designed') },
    ...(data.value?.variations ?? []).map((v) => ({ value: v.name, label: v.label })),
]);

function openNew() {
    Object.assign(form, {
        name: '',
        slug: '',
        description: '',
        parent: data.value.running ?? data.value.themes[0]?.slug ?? '',
        variation: '',
        from_site: data.value.site_changes > 0,
        activate: false,
    });
    slugTouched = false;
    formErrors.value = {};
    creating.value = true;
}

async function create() {
    saving.value = true;
    formErrors.value = {};
    const body = { name: form.name, slug: form.slug, description: form.description, parent: form.parent, activate: form.activate };
    if (fromRunning.value) Object.assign(body, { from_site: form.from_site, variation: form.variation || null });
    try {
        const result = (await api.post('/themes', body)).data;
        data.value = result;
        creating.value = false;
        notify.success(
            form.activate ? __('{theme} was created and the site now uses it.', { theme: form.name }) : __('{theme} was created.', { theme: form.name }),
        );
    } catch (e) {
        if (e instanceof ApiError && e.status === 422) formErrors.value = e.fieldErrors();
        else notify.error(e);
    } finally {
        saving.value = false;
    }
}

// ── import ───────────────────────────────────────────────────────────
const importing = ref(false);
const importFile = ref(null);
const importReplace = ref(false);
const importErrors = ref({});
const uploading = ref(false);

function openImport() {
    importFile.value = null;
    importReplace.value = false;
    importErrors.value = {};
    importing.value = true;
}

async function upload() {
    if (!importFile.value) return;
    uploading.value = true;
    importErrors.value = {};
    const body = new FormData();
    body.append('file', importFile.value);
    if (importReplace.value) body.append('replace', '1');
    try {
        const result = (await api.upload('/themes/import', body)).data;
        data.value = result;
        importing.value = false;
        notify.success(
            result.replaced
                ? __('{theme} was updated.', { theme: names.value[result.imported] ?? result.imported })
                : __('{theme} was installed. Switch to it when you are ready.', { theme: names.value[result.imported] ?? result.imported }),
        );
    } catch (e) {
        if (e instanceof ApiError && e.status === 422) importErrors.value = e.fieldErrors();
        else notify.error(e);
    } finally {
        uploading.value = false;
    }
}
</script>

<template>
    <KPageHeader
        :title="__('Themes')"
        :description="__('A theme sets how the whole site looks. Make your own from the current look, or bring one from another Kestello site.')"
    >
        <template v-if="data" #actions>
            <KButton icon="upload" @click="openImport">{{ __('Import') }}</KButton>
            <KButton variant="primary" icon="plus" @click="openNew">{{ __('New theme') }}</KButton>
        </template>
    </KPageHeader>
    <KAlert v-if="loadError" tone="danger">{{ loadError.message }}</KAlert>
    <KAlert v-if="pendingSwitch" tone="info">{{
        __('The site switches to {theme} with the next page load.', { theme: names[data.active] ?? data.active })
    }}</KAlert>

    <ul v-if="data" class="k-themes">
        <li v-for="t in data.themes" :key="t.slug" class="k-theme" :class="{ 'is-active': t.slug === data.active }">
            <div class="k-theme__shot">
                <img v-if="t.screenshot" :src="`/system/themes/${t.slug}/screenshot`" :alt="''" loading="lazy" />
                <span v-else class="k-theme__noshot" aria-hidden="true"><KIcon name="columns" /></span>
            </div>
            <div class="k-theme__body">
                <div class="k-theme__title">
                    <h2>{{ t.name }}</h2>
                    <KBadge v-if="t.slug === data.active" tone="success">{{ __('Active') }}</KBadge>
                </div>
                <p class="k-muted k-small k-theme__meta">
                    <span class="k-num">{{ t.version }}</span>
                    <template v-if="t.parent"> · {{ __('Builds on {theme}', { theme: names[t.parent] ?? t.parent }) }}</template>
                    <template v-if="t.author"> · {{ t.author }}</template>
                </p>
                <p v-if="t.description" class="k-theme__desc">{{ t.description }}</p>
                <p class="k-muted k-small k-theme__kind">
                    <KIcon :name="t.data_only ? 'sliders' : 'code'" />
                    {{ t.data_only ? __('Made in the admin, no code') : __('Developer theme with code') }}
                </p>
            </div>
            <div class="k-theme__actions">
                <KButton v-if="t.slug !== data.active" size="sm" variant="primary" :loading="busy === `activate:${t.slug}`" @click="activate(t)">{{
                    __('Use this theme')
                }}</KButton>
                <KButton v-else size="sm" href="/admin/appearance/styles" icon="palette">{{ __('Change its styles') }}</KButton>
                <KButton v-if="t.data_only" size="sm" variant="ghost" icon="download" :loading="busy === `export:${t.slug}`" @click="exportTheme(t)">{{
                    __('Export')
                }}</KButton>
                <KButton
                    v-if="t.data_only && t.slug !== data.active && t.slug !== data.running"
                    size="sm"
                    variant="ghost"
                    icon="trash"
                    :loading="busy === `delete:${t.slug}`"
                    :aria-label="__('Delete {theme}', { theme: t.name })"
                    @click="remove(t)"
                />
            </div>
        </li>
    </ul>

    <KModal v-model:open="creating" :title="__('New theme')">
        <form id="k-theme-new" class="k-form" @submit.prevent="create">
            <KField v-slot="{ id }" :label="__('Name')" :error="formErrors.name ?? ''">
                <KInput :id="id" v-model="form.name" required maxlength="60" />
            </KField>
            <KField
                v-slot="{ id }"
                :label="__('Folder name')"
                :help="__('Lowercase letters, digits and hyphens. Other sites see the theme under this name.')"
                :error="formErrors.slug ?? ''"
            >
                <KInput :id="id" v-model="form.slug" required maxlength="64" pattern="[a-z0-9]+(-[a-z0-9]+)*" class="k-mono" @input="slugTouched = true" />
            </KField>
            <KField
                v-slot="{ id }"
                :label="__('Start from')"
                :help="__('The new theme builds on this one: its pages, blocks and styles, with your changes on top.')"
                :error="formErrors.parent ?? ''"
            >
                <KSelect :id="id" v-model="form.parent" :options="parentOptions" />
            </KField>
            <template v-if="fromRunning">
                <KField v-if="data.variations.length" v-slot="{ id }" :label="__('Starting look')" :error="formErrors.variation ?? ''">
                    <KSelect :id="id" v-model="form.variation" :options="variationOptions" />
                </KField>
                <KSwitch v-model="form.from_site" :label="__('Include this site\'s changes')" :disabled="!data.site_changes" />
                <p class="k-muted k-small k-theme-note">
                    {{
                        data.site_changes
                            ? __('Styles, templates, parts and patterns changed on this site become part of the theme ({n} changed).', { n: data.site_changes })
                            : __('Nothing has been changed on this site yet.')
                    }}
                </p>
            </template>
            <KField v-slot="{ id }" :label="__('Description')" :error="formErrors.description ?? ''">
                <KInput :id="id" v-model="form.description" maxlength="300" />
            </KField>
            <KSwitch v-model="form.activate" :label="__('Switch the site to it now')" />
        </form>
        <template #footer>
            <KButton @click="creating = false">{{ __('Cancel') }}</KButton>
            <KButton type="submit" form="k-theme-new" variant="primary" icon="check" :loading="saving">{{ __('Create theme') }}</KButton>
        </template>
    </KModal>

    <KModal v-model:open="importing" :title="__('Import a theme')">
        <form id="k-theme-import" class="k-form" @submit.prevent="upload">
            <p class="k-muted k-small k-theme-note">
                {{
                    __(
                        'Choose a zip exported from Appearance → Themes on any Kestello site. Only themes without code can be imported here; the theme it builds on must be installed.',
                    )
                }}
            </p>
            <KField v-slot="{ id }" :label="__('Theme zip')" :error="importErrors.file ?? ''">
                <input :id="id" type="file" accept=".zip,application/zip" class="k-input" required @change="importFile = $event.target.files?.[0] ?? null" />
            </KField>
            <KSwitch v-model="importReplace" :label="__('Replace an installed theme with the same name')" />
            <KAlert v-if="importErrors.replace" tone="warning">{{ importErrors.replace }}</KAlert>
        </form>
        <template #footer>
            <KButton @click="importing = false">{{ __('Cancel') }}</KButton>
            <KButton type="submit" form="k-theme-import" variant="primary" icon="upload" :loading="uploading" :disabled="!importFile">{{
                __('Import')
            }}</KButton>
        </template>
    </KModal>
</template>

<style scoped>
.k-themes {
    list-style: none;
    margin: 14px 0 0;
    padding: 0;
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(18rem, 1fr));
    gap: 14px;
}
.k-theme {
    display: flex;
    flex-direction: column;
    border: 1px solid var(--k-rule);
    background: var(--k-sheet);
}
.k-theme.is-active {
    border-color: var(--k-ink);
    box-shadow: inset 0 0 0 1px var(--k-ink);
}
.k-theme__shot {
    aspect-ratio: 16 / 10;
    border-bottom: 1px solid var(--k-rule);
    background: var(--k-sheet-2);
    overflow: hidden;
}
.k-theme__shot img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    object-position: top;
    display: block;
}
.k-theme__noshot {
    display: grid;
    place-items: center;
    height: 100%;
    font-size: 28px;
    color: var(--k-ink-3);
}
.k-theme__body {
    flex: 1;
    padding: 12px 14px 4px;
    display: grid;
    gap: 4px;
    align-content: start;
}
.k-theme__title {
    display: flex;
    align-items: center;
    gap: 8px;
}
.k-theme__title h2 {
    margin: 0;
    font-size: 15px;
    font-weight: 650;
}
.k-theme__meta,
.k-theme__desc,
.k-theme__kind {
    margin: 0;
}
.k-theme__desc {
    font-size: 13px;
    color: var(--k-ink-2);
}
.k-theme__kind {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-top: 4px;
}
.k-theme__actions {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 10px 14px 12px;
    flex-wrap: wrap;
}
.k-theme__actions > :last-child:not(:first-child) {
    margin-left: auto;
}
.k-theme-note {
    margin: -4px 0 0;
}
</style>
