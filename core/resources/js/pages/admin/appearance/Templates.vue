<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Link, useRouter } from '@kestello/app';
import { ApiError, notify, useApi, __ } from '@kestello/sdk';
import { KAlert, KBadge, KButton, KCard, KEmpty, KField, KIcon, KInput, KModal, KPageHeader, KSelect, KSwitch, KTabs, KTime } from '@kestello/ui';

defineOptions({ layout: 'Admin' });

/**
 * Appearance → Templates: the block layouts of the running theme. Templates
 * lay out entries, parts are shared pieces (header, footer) and patterns are
 * ready-made groups of blocks for the editor.
 */
const api = useApi();
const router = useRouter();
const data = ref(null);
const loadError = ref(null);

async function load() {
    try {
        data.value = (await api.get('/design/templates')).data;
    } catch (e) {
        loadError.value = e;
    }
}
load();

const KINDS = {
    template: {
        label: __('Templates'),
        one: __('template'),
        icon: 'squares-four',
        empty: __('This theme lays out entries with its own pages. Add a template to lay them out with blocks instead.'),
    },
    part: { label: __('Parts'), one: __('part'), icon: 'layout', empty: __('No parts yet. A header or footer made here can be placed in every template.') },
    pattern: {
        label: __('Patterns'),
        one: __('pattern'),
        icon: 'star',
        empty: __('No patterns yet. Patterns appear in the block inserter, ready to drop into any page.'),
    },
};
const params = new URLSearchParams(typeof location === 'undefined' ? '' : location.search);
const tab = ref(KINDS[params.get('kind')] ? params.get('kind') : 'template');
watch(tab, (k) => {
    if (typeof history !== 'undefined') history.replaceState(history.state, '', `?kind=${k}`);
});
const tabs = computed(() => Object.entries(KINDS).map(([id, k]) => ({ id, label: k.label, count: data.value?.[id]?.length ?? 0 })));
const rows = computed(() => data.value?.[tab.value] ?? []);

const SOURCE = { core: __('Comes with Kestello'), theme: __('From the theme'), customized: __('Changed on this site'), site: __('Made on this site') };
const typeNames = computed(() => Object.fromEntries((data.value?.types ?? []).map((t) => [t.name, t.plural])));
function usedFor(row) {
    if (tab.value === 'template') {
        if (row.key === 'entry') return __('Every entry without its own template');
        if (row.key.startsWith('entry-')) return __('Every entry of {type}', { type: typeNames.value[row.key.slice(6)] ?? row.key.slice(6) });
        return row.for ? __('Chosen per entry: {types}', { types: row.for.map((t) => typeNames.value[t] ?? t).join(', ') }) : __('Chosen per entry');
    }
    if (tab.value === 'part') return data.value.areas[row.area] ?? row.area;
    return row.synced ? __('{category} · synced', { category: row.category }) : row.category;
}

// ── new ──────────────────────────────────────────────────────────────
const creating = ref(false);
const form = reactive({ title: '', key: '', applies: 'custom', type: '', area: 'header', category: '', synced: false });
const formErrors = ref({});
const saving = ref(false);
const slug = (s) =>
    s
        .toLowerCase()
        .normalize('NFKD')
        .replace(/[̀-ͯ]/g, '')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 60);
let keyTouched = false;
watch(
    () => form.title,
    (t) => {
        if (!keyTouched) form.key = slug(t);
    },
);

const typeOptions = computed(() => (data.value?.types ?? []).map((t) => ({ value: t.name, label: t.plural })));
const areaOptions = computed(() => Object.entries(data.value?.areas ?? {}).map(([value, label]) => ({ value, label })));
const appliesOptions = [
    { value: 'custom', label: __('Entries choose it') },
    { value: 'type', label: __('Every entry of one type') },
    { value: 'all', label: __('Every entry without a template of its own') },
];
const finalKey = computed(() => {
    if (tab.value !== 'template' || form.applies === 'custom') return form.key;
    return form.applies === 'all' ? 'entry' : `entry-${form.type}`;
});

function openNew() {
    Object.assign(form, { title: '', key: '', applies: 'custom', type: data.value?.types?.[0]?.name ?? '', area: 'header', category: '', synced: false });
    keyTouched = false;
    formErrors.value = {};
    creating.value = true;
}

async function create() {
    saving.value = true;
    formErrors.value = {};
    const body = { key: finalKey.value, title: form.title, blocks: [] };
    if (tab.value === 'part') body.area = form.area;
    if (tab.value === 'pattern') Object.assign(body, { category: form.category, synced: form.synced });
    try {
        await api.post(`/design/templates/${tab.value}`, body);
        creating.value = false;
        router.visit(`/admin/appearance/templates/${tab.value}/${finalKey.value}`);
    } catch (e) {
        if (e instanceof ApiError && e.status === 422) formErrors.value = e.fieldErrors();
        else notify.error(e);
    } finally {
        saving.value = false;
    }
}
const keyError = computed(() => {
    const e = formErrors.value.key;
    return Array.isArray(e) ? e[0] : (e ?? '');
});
</script>

<template>
    <KPageHeader
        :title="__('Templates')"
        :description="
            data ? __('Block layouts of {theme}. Changes are saved as drafts and reach visitors when you publish them.', { theme: data.theme.name }) : ''
        "
    >
        <template v-if="data" #actions>
            <KButton variant="primary" icon="plus" @click="openNew">{{ __('New {kind}', { kind: KINDS[tab].one }) }}</KButton>
        </template>
    </KPageHeader>
    <KAlert v-if="loadError" tone="danger">{{ loadError.message }}</KAlert>

    <template v-if="data">
        <KTabs v-model="tab" :tabs="tabs" :label="__('Kinds')" />
        <KCard flush class="k-tpl-card">
            <KEmpty v-if="!rows.length" :icon="KINDS[tab].icon" :title="__('Nothing here yet')">
                <p>{{ KINDS[tab].empty }}</p>
                <KButton icon="plus" @click="openNew">{{ __('New {kind}', { kind: KINDS[tab].one }) }}</KButton>
            </KEmpty>
            <ul v-else class="k-tpl-list">
                <li v-for="row in rows" :key="row.key">
                    <Link :href="`/admin/appearance/templates/${tab}/${row.key}`" class="k-tpl-row">
                        <KIcon :name="KINDS[tab].icon" class="k-tpl-row__icon" />
                        <span class="k-tpl-row__main">
                            <span class="k-tpl-row__title">{{ row.title }}</span>
                            <span class="k-tpl-row__meta k-muted k-small">
                                <span class="k-mono">{{ row.key }}</span> · {{ usedFor(row) }}
                            </span>
                        </span>
                        <span class="k-tpl-row__state">
                            <KBadge v-if="row.draft" tone="warning">{{ row.published ? __('Unpublished changes') : __('Draft') }}</KBadge>
                            <span class="k-muted k-small">{{ SOURCE[row.source] }}</span>
                            <span v-if="row.info" class="k-muted k-small"><KTime :value="row.info.updated_at" /></span>
                        </span>
                        <KIcon name="caret-right" class="k-tpl-row__go" />
                    </Link>
                </li>
            </ul>
        </KCard>
    </template>

    <KModal v-model:open="creating" :title="__('New {kind}', { kind: KINDS[tab].one })">
        <form id="k-tpl-new" class="k-form" @submit.prevent="create">
            <KField v-slot="{ id }" :label="__('Name')" :error="formErrors.title ?? ''">
                <KInput :id="id" v-model="form.title" required maxlength="120" />
            </KField>
            <template v-if="tab === 'template'">
                <KField v-slot="{ id }" :label="__('Used for')">
                    <KSelect :id="id" v-model="form.applies" :options="appliesOptions" />
                </KField>
                <KField v-if="form.applies === 'type'" v-slot="{ id }" :label="__('Content type')">
                    <KSelect :id="id" v-model="form.type" :options="typeOptions" />
                </KField>
            </template>
            <KField
                v-if="tab !== 'template' || form.applies === 'custom'"
                v-slot="{ id }"
                :label="__('Name in code')"
                :help="__('Lowercase letters, digits and hyphens. It can\'t be changed later.')"
                :error="keyError"
            >
                <KInput :id="id" v-model="form.key" required maxlength="61" pattern="[a-z0-9][a-z0-9-]*" class="k-mono" @input="keyTouched = true" />
            </KField>
            <KAlert v-else-if="keyError" tone="danger">{{ keyError }}</KAlert>
            <KField v-if="tab === 'part'" v-slot="{ id }" :label="__('Area')" :error="formErrors.area ?? ''">
                <KSelect :id="id" v-model="form.area" :options="areaOptions" />
            </KField>
            <template v-if="tab === 'pattern'">
                <KField
                    v-slot="{ id }"
                    :label="__('Category')"
                    :error="formErrors.category ?? ''"
                    :help="__('Groups patterns in the inserter, such as Heroes or Calls to action. Empty: General.')"
                >
                    <KInput :id="id" v-model="form.category" maxlength="40" />
                </KField>
                <KSwitch v-model="form.synced" :label="__('Synced')" />
                <p class="k-muted k-small k-tpl-note">
                    {{
                        form.synced
                            ? __('Every page that uses it shows the same blocks; changing the pattern changes them all.')
                            : __('Each page gets its own copy to change freely.')
                    }}
                </p>
            </template>
        </form>
        <template #footer>
            <KButton @click="creating = false">{{ __('Cancel') }}</KButton>
            <KButton type="submit" form="k-tpl-new" variant="primary" icon="arrow-right" :loading="saving">{{ __('Create and edit') }}</KButton>
        </template>
    </KModal>
</template>

<style scoped>
.k-tpl-card {
    margin-top: 14px;
}
.k-tpl-list {
    list-style: none;
    margin: 0;
    padding: 0;
}
.k-tpl-list li + li {
    border-top: 1px solid var(--k-rule-soft);
}
.k-tpl-row {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr) auto auto;
    align-items: center;
    gap: 14px;
    padding: 12px 16px;
    color: inherit;
    text-decoration: none;
}
.k-tpl-row:hover {
    background: var(--k-sheet-2);
}
.k-tpl-row:focus-visible {
    outline: 2px solid var(--k-focus);
    outline-offset: -2px;
}
.k-tpl-row__icon {
    font-size: 18px;
    color: var(--k-ink-2);
}
.k-tpl-row__main {
    display: grid;
    gap: 2px;
    min-width: 0;
}
.k-tpl-row__title {
    font-weight: 600;
}
.k-tpl-row__meta {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.k-tpl-row__state {
    display: flex;
    align-items: center;
    gap: 10px;
    justify-content: flex-end;
    flex-wrap: wrap;
}
.k-tpl-row__go {
    color: var(--k-ink-3);
}
.k-tpl-note {
    margin: -6px 0 0;
}
@media (max-width: 640px) {
    .k-tpl-row {
        grid-template-columns: auto minmax(0, 1fr) auto;
    }
    .k-tpl-row__state {
        grid-column: 2 / -1;
        justify-content: flex-start;
    }
    .k-tpl-row__go {
        display: none;
    }
}
</style>
