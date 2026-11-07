<script setup>
import { computed, ref } from 'vue';
import { Head, Link, usePage, useRouter } from '@kestello/app';
import { ApiError, confirm, notify, useApi, useLeaveGuard, __ } from '@kestello/sdk';
import { KAlert, KBadge, KBuilder, KButton, KField, KIcon, KInput, KSelect, KSwitch, KTime } from '@kestello/ui';

defineOptions({ layout: 'Admin' });

/**
 * One template, part or pattern: its blocks and settings. Save keeps a draft
 * that only people who design can preview (?design=draft); Publish puts it
 * live. Every published version stays in the history.
 */
const api = useApi();
const page = usePage();
const router = useRouter();
const kind = computed(() => String(page.props.params?.kind ?? ''));
const key = computed(() => String(page.props.params?.key ?? ''));

const KIND = {
    template: { one: __('Template'), back: __('Templates') },
    part: { one: __('Part'), back: __('Parts') },
    pattern: { one: __('Pattern'), back: __('Patterns') },
};

const doc = ref(null);
const lists = ref(null); // content types and areas, from the list endpoint
const loadError = ref(null);
const form = ref(null);
const errors = ref({});
const busy = ref('');

const FIELDS = ['title', 'description', 'blocks', 'layout', 'for', 'area', 'category', 'synced'];
const pick = (d) => Object.fromEntries(FIELDS.filter((f) => d && f in d).map((f) => [f, JSON.parse(JSON.stringify(d[f] ?? null))]));
const working = computed(() => doc.value?.draft ?? doc.value?.published ?? null);

function adopt(d) {
    doc.value = d;
    form.value = { blocks: [], ...pick(d.draft ?? d.published) };
    errors.value = {};
}
async function load() {
    try {
        const [one, all] = await Promise.all([api.get(`/design/templates/${kind.value}/${key.value}`), lists.value ? null : api.get('/design/templates')]);
        if (all) lists.value = all.data;
        adopt(one.data);
    } catch (e) {
        loadError.value = e;
    }
}
load();

const dirty = computed(() => !!form.value && JSON.stringify(form.value) !== JSON.stringify({ blocks: [], ...pick(working.value) }));
const isLive = computed(() => !!doc.value?.published);

// ── what it is for ───────────────────────────────────────────────────
const reserved = computed(() => key.value === 'entry' || key.value.startsWith('entry-'));
const typeNames = computed(() => Object.fromEntries((lists.value?.types ?? []).map((t) => [t.name, t.plural])));
const purpose = computed(() => {
    if (kind.value === 'template') {
        if (key.value === 'entry') return __('Lays out every entry that has no template of its own.');
        if (key.value.startsWith('entry-')) return __('Lays out every entry of {type}.', { type: typeNames.value[key.value.slice(6)] ?? key.value.slice(6) });
        return __('Entries choose it under Details → Template.');
    }
    if (kind.value === 'part') return __('Place it in templates with the Template part block.');
    return form.value?.synced
        ? __('Synced: pages that use it show these blocks and change with it.')
        : __('Offered in the block inserter. Each page gets its own copy.');
});
const layoutOptions = [
    { value: 'site', label: __("Inside the theme's header and footer") },
    { value: 'bare', label: __('On its own (use parts for header and footer)') },
];
const areaOptions = computed(() => Object.entries(lists.value?.areas ?? {}).map(([value, label]) => ({ value, label })));
function toggleType(name, on) {
    const now = new Set(form.value.for ?? []);
    on ? now.add(name) : now.delete(name);
    form.value.for = now.size ? [...now] : null;
}

// a fresh undo history whenever another version is loaded (saved, published, restored, discarded)
const historyKey = computed(() => `${doc.value?.info?.revision ?? 0}:${doc.value?.draft_info?.revision ?? 0}:${doc.value?.draft ? 'd' : 'p'}`);

// ── saving ───────────────────────────────────────────────────────────
function payload(publish) {
    const out = { ...form.value, publish };
    if (kind.value !== 'template') {
        delete out.layout;
        delete out.for;
    }
    if (kind.value !== 'part') delete out.area;
    if (kind.value !== 'pattern') {
        delete out.category;
        delete out.synced;
    }
    return out;
}
async function run(name, request, done) {
    busy.value = name;
    errors.value = {};
    try {
        const body = (await request()).data;
        if (body.deleted) {
            router.visit(`/admin/appearance/templates?kind=${kind.value}`, { discardUnsaved: true });
            return;
        }
        adopt(body);
        if (done) notify.success(done);
    } catch (e) {
        if (e instanceof ApiError && e.status === 422) {
            errors.value = e.fieldErrors();
            notify.error(__('Some blocks need attention before this can be saved.'));
        } else notify.error(e);
    } finally {
        busy.value = '';
    }
}
const save = () =>
    run('save', () => api.put(`/design/templates/${kind.value}/${key.value}`, payload(false)), __('Draft saved. Visitors still see the published version.'));
const publish = () =>
    dirty.value
        ? run('publish', () => api.put(`/design/templates/${kind.value}/${key.value}`, payload(true)), __('Published.'))
        : run('publish', () => api.post(`/design/templates/${kind.value}/${key.value}/publish`), __('Published.'));
async function discard() {
    const gone = !isLive.value;
    if (
        !(await confirm({
            title: gone ? __('Delete this draft?') : __('Discard the draft?'),
            message: gone ? __('It was never published, so nothing will be left.') : __('The published version stays as it is.'),
            confirmLabel: gone ? __('Delete') : __('Discard'),
            danger: true,
        }))
    )
        return;
    run('discard', () => api.delete(`/design/templates/${kind.value}/${key.value}/draft`), gone ? null : __('Draft discarded.'));
}
async function reset() {
    const file = doc.value.file;
    if (
        !(await confirm({
            title: file ? __("Go back to the theme's version?") : __('Delete this {kind}?', { kind: KIND[kind.value].one.toLowerCase() }),
            message: file
                ? __("The site's changes and any draft go. The current version stays in the history.")
                : __('The last version stays in the history and can be restored from another one of its versions.'),
            confirmLabel: file ? __("Use the theme's version") : __('Delete'),
            danger: true,
        }))
    )
        return;
    run('reset', () => api.delete(`/design/templates/${kind.value}/${key.value}`), file ? __("Back to the theme's version.") : null);
}
async function restore(rev) {
    if (dirty.value && !(await confirm({ title: __('Restore this version?'), message: __('Your unsaved changes will be lost.'), confirmLabel: __('Restore') })))
        return;
    run('restore', () => api.post(`/design/templates/revisions/${rev.id}/restore`), __('Restored and published.'));
}
// ── a theme update to a version the site changed ─────────────────
function flat(nodes, out = new Map(), depth = 0) {
    for (const n of nodes ?? []) {
        const { children, ...own } = n;
        out.set(n.id, { node: n, own: JSON.stringify(own), depth });
        flat(children, out, depth + 1);
    }
    return out;
}
const blockName = (n) => {
    const p = n.props ?? {};
    const text = typeof p.text === 'string' ? p.text.replace(/<[^>]+>/g, '').trim() : typeof p.part === 'string' ? p.part : '';
    const type = n.type.split('/').pop().replace(/-/g, ' ');
    return text ? `${type}: ${text.length > 48 ? text.slice(0, 47) + '…' : text}` : type;
};
const themeDiff = computed(() => {
    if (!doc.value?.theme_update || !doc.value.theme_version) return null;
    const mine = flat(doc.value.published?.blocks);
    const theirs = flat(doc.value.theme_version.blocks);
    const rows = [];
    for (const [id, t] of theirs) {
        const m = mine.get(id);
        if (!m) rows.push({ id, change: 'added', label: blockName(t.node) });
        else if (m.own !== t.own) rows.push({ id, change: 'changed', label: blockName(t.node) });
    }
    for (const [id, m] of mine) if (!theirs.has(id)) rows.push({ id, change: 'removed', label: blockName(m.node) });
    const settings = ['title', 'layout', 'area', 'category', 'synced', 'for'].filter(
        (f) => JSON.stringify(doc.value.published?.[f] ?? null) !== JSON.stringify(doc.value.theme_version[f] ?? null),
    );
    return { rows, settings };
});
const CHANGE = { added: __('New in the theme'), changed: __('Changed by the theme'), removed: __('Only in yours') };
const showDiff = ref(false);
async function resolveUpdate(action) {
    if (
        dirty.value &&
        !(await confirm({ title: __('Leave your unsaved changes?'), message: __('They will be lost.'), confirmLabel: __('Continue'), danger: true }))
    )
        return;
    if (
        action === 'theirs' &&
        !(await confirm({
            title: __("Use the theme's new version?"),
            message: __("This site's changes are replaced. They stay in the history."),
            confirmLabel: __("Use the theme's version"),
            danger: true,
        }))
    )
        return;
    const done = {
        keep: __('Kept your version.'),
        theirs: __("Now using the theme's version."),
        draft: __("The theme's version is open as a draft. Yours stays live until you publish."),
    }[action];
    run(`update:${action}`, () => api.post(`/design/templates/${kind.value}/${key.value}/theme-update`, { action }), done);
}

function revert() {
    form.value = { blocks: [], ...pick(working.value) };
    errors.value = {};
}

// ── preview ──────────────────────────────────────────────────────────
const previewPath = ref('/');
const previewUrl = computed(() => {
    const p = previewPath.value.startsWith('/') && !previewPath.value.startsWith('//') ? previewPath.value : '/';
    return `${p}${p.includes('?') ? '&' : '?'}design=draft`;
});

// ── leaving with unsaved changes ─────────────────────────────────────
useLeaveGuard(dirty);

const errorList = computed(() =>
    Object.entries(errors.value)
        .filter(([k]) => !k.startsWith('blocks'))
        .flatMap(([, v]) => (Array.isArray(v) ? v : [v])),
);
const fieldError = (name) => {
    const e = errors.value[name];
    return Array.isArray(e) ? e[0] : (e ?? '');
};
</script>

<template>
    <Head :title="doc ? `${form.title || key} · ${KIND[kind]?.one}` : __('Template')" />
    <KAlert v-if="loadError" tone="danger">{{ loadError.message }}</KAlert>

    <div v-if="doc && form" class="k-tpl-ed">
        <header class="k-tpl-ed__head">
            <div>
                <Link :href="`/admin/appearance/templates?kind=${kind}`" class="k-tpl-ed__back k-small"><KIcon name="arrow-left" />{{ KIND[kind].back }}</Link>
                <h1 class="k-tpl-ed__title">{{ form.title || key }}</h1>
                <p class="k-muted k-small k-tpl-ed__sub">
                    <span class="k-mono">{{ key }}</span> · {{ purpose }}
                </p>
            </div>
            <div class="k-tpl-ed__actions">
                <KBadge v-if="dirty" tone="warning">{{ __('Unsaved') }}</KBadge>
                <KBadge v-else-if="doc.draft" tone="info">{{ isLive ? __('Draft saved, not published') : __('Draft, never published') }}</KBadge>
                <KButton v-if="dirty" variant="ghost" @click="revert">{{ __('Undo changes') }}</KButton>
                <KButton icon="floppy-disk" :loading="busy === 'save'" :disabled="!dirty || !!busy" @click="save">{{ __('Save draft') }}</KButton>
                <KButton
                    variant="primary"
                    icon="paper-plane-tilt"
                    :loading="busy === 'publish'"
                    :disabled="(!dirty && !doc.draft) || !!busy"
                    @click="publish"
                    >{{ __('Publish') }}</KButton
                >
            </div>
        </header>

        <KAlert v-if="themeDiff" tone="info" :title="__('The theme has a new version of this {kind}', { kind: KIND[kind].one.toLowerCase() })">
            <p class="k-tpl-ed__update">
                {{
                    __(
                        "This site changed it before the theme was updated. Keep yours, take the theme's, or open the theme's as a draft and bring your changes over.",
                    )
                }}
            </p>
            <div class="k-tpl-ed__update-actions">
                <KButton size="sm" :loading="busy === 'update:keep'" :disabled="!!busy" @click="resolveUpdate('keep')">{{ __('Keep mine') }}</KButton>
                <KButton size="sm" :loading="busy === 'update:draft'" :disabled="!!busy" @click="resolveUpdate('draft')">{{
                    __('Open theirs as a draft')
                }}</KButton>
                <KButton size="sm" variant="ghost" :loading="busy === 'update:theirs'" :disabled="!!busy" @click="resolveUpdate('theirs')">{{
                    __("Use the theme's")
                }}</KButton>
                <button type="button" class="k-tpl-ed__diff-toggle" :aria-expanded="String(showDiff)" @click="showDiff = !showDiff">
                    <KIcon :name="showDiff ? 'caret-up' : 'caret-down'" />{{
                        __('What changed ({n})', { n: themeDiff.rows.length + themeDiff.settings.length })
                    }}
                </button>
            </div>
            <ul v-if="showDiff" class="k-tpl-ed__diff">
                <li v-for="f in themeDiff.settings" :key="f">
                    <span class="k-tpl-ed__diff-kind">{{ __('Setting') }}</span
                    >{{ f }}
                </li>
                <li v-for="row in themeDiff.rows" :key="row.id + row.change">
                    <span class="k-tpl-ed__diff-kind" :class="`is-${row.change}`">{{ CHANGE[row.change] }}</span
                    >{{ row.label }}
                </li>
                <li v-if="!themeDiff.rows.length && !themeDiff.settings.length" class="k-muted">{{ __('Only formatting changed.') }}</li>
            </ul>
        </KAlert>

        <KAlert v-if="errorList.length" tone="danger">
            <ul class="k-tpl-ed__errors">
                <li v-for="(m, i) in errorList" :key="i">{{ m }}</li>
            </ul>
        </KAlert>

        <KBuilder v-model="form.blocks" :kind="kind" :doc-key="key" :reset-key="historyKey" :disabled="!!busy" :errors="errors">
            <template #document>
                <p v-if="kind === 'template' && !JSON.stringify(form.blocks).includes('core/entry-content')" class="k-muted k-small k-tpl-ed__hint">
                    {{ __("Add an Entry content block where the entry's own text should go. Without one, only the blocks above show.") }}
                </p>
                <section class="k-tpl-ed__section">
                    <h2>{{ __('Settings') }}</h2>
                    <div class="k-form">
                        <KField v-slot="{ id }" :label="__('Name')" :error="fieldError('title')">
                            <KInput :id="id" v-model="form.title" maxlength="120" />
                        </KField>
                        <KField v-slot="{ id }" :label="__('Description')" :error="fieldError('description')">
                            <KInput :id="id" v-model="form.description" maxlength="300" />
                        </KField>
                        <template v-if="kind === 'template'">
                            <KField v-slot="{ id }" :label="__('Page frame')">
                                <KSelect :id="id" v-model="form.layout" :options="layoutOptions" />
                            </KField>
                            <fieldset v-if="!reserved" class="k-tpl-ed__types">
                                <legend class="k-small">{{ __('Entries that may choose it') }}</legend>
                                <KSwitch
                                    v-for="t in lists?.types ?? []"
                                    :key="t.name"
                                    :model-value="!form.for || form.for.includes(t.name)"
                                    :label="t.plural"
                                    @update:model-value="
                                        (on) => (form.for ? toggleType(t.name, on) : (form.for = lists.types.map((x) => x.name).filter((n) => n !== t.name)))
                                    "
                                />
                            </fieldset>
                        </template>
                        <KField
                            v-if="kind === 'part'"
                            v-slot="{ id }"
                            :label="__('Area')"
                            :help="__('Headers render as <header>, footers as <footer>, sidebars as <aside>.')"
                        >
                            <KSelect :id="id" v-model="form.area" :options="areaOptions" />
                        </KField>
                        <template v-if="kind === 'pattern'">
                            <KField v-slot="{ id }" :label="__('Category')">
                                <KInput :id="id" v-model="form.category" maxlength="40" />
                            </KField>
                            <KSwitch v-model="form.synced" :label="__('Synced')" />
                        </template>
                    </div>
                </section>

                <section v-if="kind !== 'pattern' || form.synced" class="k-tpl-ed__section">
                    <h2>{{ __('Preview') }}</h2>
                    <p class="k-muted k-small">{{ __('Opens a page of the site with saved drafts in place. Only people who may design see them.') }}</p>
                    <form class="k-tpl-ed__preview" @submit.prevent>
                        <KInput v-model="previewPath" :aria-label="__('Page address')" class="k-mono" />
                        <a :href="previewUrl" target="_blank" rel="noopener" class="k-btn k-btn--secondary k-btn--sm"
                            ><KIcon name="arrow-square-out" />{{ __('Open') }}</a
                        >
                    </form>
                    <p v-if="dirty" class="k-muted k-small">{{ __('Save the draft first to see these changes.') }}</p>
                </section>

                <section v-if="doc.used_by.length" class="k-tpl-ed__section">
                    <h2>{{ __('Used in') }}</h2>
                    <ul class="k-tpl-ed__used">
                        <li v-for="u in doc.used_by" :key="`${u.kind}/${u.key}`">
                            <Link :href="`/admin/appearance/templates/${u.kind}/${u.key}`">{{ u.title }}</Link>
                        </li>
                    </ul>
                </section>

                <section class="k-tpl-ed__section">
                    <h2>{{ __('History') }}</h2>
                    <p v-if="!doc.revisions.length" class="k-muted k-small">
                        {{ doc.file ? __("Not changed on this site: the theme's version is live.") : __('Not published yet.') }}
                    </p>
                    <ol v-else class="k-tpl-ed__history">
                        <li v-for="(r, i) in doc.revisions" :key="r.id">
                            <span>
                                <KTime :value="r.created_at" />
                                <span class="k-muted k-small">
                                    · {{ r.reset ? (doc.file ? __("Theme's version") : __('Deleted')) : (r.author_name ?? __('Someone')) }}</span
                                >
                            </span>
                            <KBadge v-if="i === 0 && (isLive || r.reset)" tone="success">{{ __('Live') }}</KBadge>
                            <KButton v-else size="sm" variant="ghost" :disabled="!!busy" @click="restore(r)">{{ __('Restore') }}</KButton>
                        </li>
                    </ol>
                    <div class="k-tpl-ed__danger">
                        <KButton v-if="doc.draft" size="sm" variant="ghost" icon="x" :disabled="!!busy" @click="discard">{{
                            isLive ? __('Discard draft') : __('Delete draft')
                        }}</KButton>
                        <KButton
                            v-if="doc.source === 'customized'"
                            size="sm"
                            variant="ghost"
                            icon="arrow-counter-clockwise"
                            :disabled="!!busy"
                            @click="reset"
                            >{{ __('Back to the original version') }}</KButton
                        >
                        <KButton
                            v-else-if="doc.source === 'site' && isLive"
                            size="sm"
                            variant="ghost"
                            icon="trash"
                            :disabled="!!busy || doc.used_by.length > 0"
                            @click="reset"
                            >{{ __('Delete') }}</KButton
                        >
                    </div>
                    <p v-if="doc.source === 'site' && isLive && doc.used_by.length" class="k-muted k-small">
                        {{ __('Take it out of the templates above before deleting it.') }}
                    </p>
                </section>
            </template>
        </KBuilder>
    </div>
</template>

<style scoped>
.k-tpl-ed {
    display: grid;
    gap: 14px;
}
.k-tpl-ed__head {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    gap: 12px 24px;
    flex-wrap: wrap;
}
.k-tpl-ed__back {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    color: var(--k-ink-2);
    text-decoration: none;
}
.k-tpl-ed__back:hover {
    color: var(--k-ink);
    text-decoration: underline;
}
.k-tpl-ed__title {
    margin: 2px 0;
    font-size: 24px;
    font-weight: 650;
    letter-spacing: -0.01em;
}
.k-tpl-ed__sub {
    margin: 0;
    max-width: 70ch;
}
.k-tpl-ed__actions {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.k-tpl-ed__errors {
    margin: 0;
    padding-left: 18px;
}
.k-tpl-ed__hint {
    margin: 0 0 4px;
}
.k-tpl-ed__section:first-of-type {
    padding-top: 4px;
}
.k-tpl-ed__section {
    padding: 16px 0;
    border-bottom: 1px solid var(--k-rule-soft);
}
.k-tpl-ed__section:last-child {
    border-bottom: 0;
}
.k-tpl-ed__section h2 {
    margin: 0 0 10px;
    font-size: 13px;
    font-weight: 650;
}
.k-tpl-ed__section > p {
    margin: 0 0 10px;
}
.k-tpl-ed__types {
    border: 0;
    margin: 0;
    padding: 0;
    display: grid;
    gap: 6px;
}
.k-tpl-ed__types legend {
    margin-bottom: 6px;
    padding: 0;
}
.k-tpl-ed__preview {
    display: flex;
    gap: 6px;
    margin-bottom: 6px;
}
.k-tpl-ed__preview :deep(input) {
    flex: 1;
    min-width: 0;
}
.k-tpl-ed__used {
    margin: 0;
    padding-left: 18px;
    display: grid;
    gap: 4px;
    font-size: 13px;
}
.k-tpl-ed__history {
    list-style: none;
    margin: 0 0 10px;
    padding: 0;
    display: grid;
    gap: 2px;
}
.k-tpl-ed__history li {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    padding: 4px 0;
    font-size: 13px;
}
.k-tpl-ed__danger {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}
.k-tpl-ed__update {
    margin: 0 0 8px;
}
.k-tpl-ed__update-actions {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}
.k-tpl-ed__diff-toggle {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    margin-left: 6px;
    padding: 2px 0;
    border: 0;
    background: none;
    color: inherit;
    font: inherit;
    font-size: 13px;
    cursor: pointer;
    text-decoration: underline;
    text-underline-offset: 2px;
}
.k-tpl-ed__diff {
    list-style: none;
    margin: 10px 0 0;
    padding: 0;
    display: grid;
    gap: 4px;
    font-size: 13px;
}
.k-tpl-ed__diff li {
    display: flex;
    gap: 10px;
    align-items: baseline;
}
.k-tpl-ed__diff-kind {
    flex: 0 0 10.5rem;
    color: var(--k-ink-2);
    font-size: 12px;
}
.k-tpl-ed__diff-kind.is-added,
.k-tpl-ed__diff-kind.is-changed {
    color: var(--k-ink);
    font-weight: 600;
}
</style>
