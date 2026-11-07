<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Head, usePage } from '@kestello/app';
import { ApiError, confirm, notify, useApi, useResource, __ } from '@kestello/sdk';
import { KAlert, KButton, KCard, KField, KInput, KPageHeader, KSelect, KTable } from '@kestello/ui';

defineOptions({ layout: 'Admin' });

const page = usePage();
const api = useApi();
const name = computed(() => String(page.props.params?.taxonomy ?? ''));
const taxonomies = useResource('/taxonomies');
const tax = computed(() => (taxonomies.data.value ?? []).find((t) => t.name === name.value) ?? null);
const terms = useResource(() => `/taxonomies/${name.value}/terms`);

const filter = ref('');
const rows = computed(() => {
    const q = filter.value.trim().toLowerCase();
    const list = terms.data.value ?? [];
    return q ? list.filter((t) => t.name.toLowerCase().includes(q) || t.slug.includes(q)).map((t) => ({ ...t, depth: 0 })) : list;
});

// ── the form: adds, or edits the picked term ─────────────────────────
const editing = ref(null);
const form = reactive({ name: '', slug: '', parent_id: null, description: '' });
const errors = ref({});
const saving = ref(false);

function reset() {
    editing.value = null;
    Object.assign(form, { name: '', slug: '', parent_id: null, description: '' });
    errors.value = {};
}
function edit(term) {
    editing.value = term;
    Object.assign(form, { name: term.name, slug: term.slug, parent_id: term.parent_id, description: term.description ?? '' });
    errors.value = {};
    document.getElementById('k-term-name')?.focus();
}
watch(name, reset);

// A term can't move under itself or its descendants.
const descendants = computed(() => {
    const out = new Set();
    if (!editing.value) return out;
    const list = terms.data.value ?? [];
    const walk = (id) => {
        for (const t of list)
            if (t.parent_id === id && !out.has(t.id)) {
                out.add(t.id);
                walk(t.id);
            }
    };
    out.add(editing.value.id);
    walk(editing.value.id);
    return out;
});
const parentOptions = computed(() => [
    { value: '', label: __('None (top level)') },
    ...(terms.data.value ?? []).filter((t) => !descendants.value.has(t.id)).map((t) => ({ value: String(t.id), label: `${' '.repeat(t.depth)}${t.name}` })),
]);
const parent = computed({
    get: () => (form.parent_id === null ? '' : String(form.parent_id)),
    set: (v) => {
        form.parent_id = v === '' ? null : Number(v);
    },
});

async function save() {
    saving.value = true;
    errors.value = {};
    const body = { name: form.name, slug: form.slug || undefined, description: form.description || null };
    if (tax.value.hierarchical) body.parent_id = form.parent_id;
    try {
        if (editing.value) {
            await api.patch(`/taxonomies/${name.value}/terms/${editing.value.id}`, body);
            notify.success(__('Saved “{name}”.', { name: form.name }));
        } else {
            await api.post(`/taxonomies/${name.value}/terms`, body);
            notify.success(__('Added “{name}”.', { name: form.name }));
        }
        reset();
        await terms.reload({ silent: true });
    } catch (e) {
        if (e instanceof ApiError && e.status === 422) errors.value = e.fieldErrors();
        else notify.error(e);
    } finally {
        saving.value = false;
    }
}

async function remove(term) {
    const ok = await confirm({
        title: __('Delete “{name}”?', { name: term.name }),
        message: term.count
            ? __('{n, plural, one {# published entry loses it.} other {# published entries lose it.}} Entries themselves are not deleted.', { n: term.count })
            : __('No published entries use it.') + (tax.value.hierarchical ? ' ' + __('Its sub-items move up a level.') : ''),
        confirmLabel: __('Delete'),
        danger: true,
    });
    if (!ok) return;
    try {
        await api.delete(`/taxonomies/${name.value}/terms/${term.id}`);
        if (editing.value?.id === term.id) reset();
        notify.success(__('Deleted “{name}”.', { name: term.name }));
        terms.reload({ silent: true });
    } catch (e) {
        notify.error(e);
    }
}

const columns = computed(() => [
    { key: 'name', label: __('Name') },
    { key: 'slug', label: __('Address') },
    { key: 'count', label: __('Published'), align: 'end', width: '110px' },
    { key: 'actions', label: '', align: 'end', width: '56px' },
]);
const singular = computed(() => (tax.value?.singular ?? '').toLowerCase());
</script>

<template>
    <Head :title="tax?.plural ?? __('Terms')" />
    <KPageHeader :title="tax?.plural ?? (taxonomies.data.value ? __('Taxonomy not found') : '…')" :description="tax?.description ?? ''" />

    <KAlert v-if="taxonomies.data.value && !tax" tone="danger">{{ __('This taxonomy does not exist, or you cannot manage it.') }}</KAlert>
    <KAlert v-else-if="tax && !tax.can.manage" tone="info">{{
        __('You can pick {plural} on entries, but only editors can change the list.', { plural: tax.plural.toLowerCase() })
    }}</KAlert>

    <div v-if="tax" class="k-cells k-terms" style="--k-cols: 3">
        <KCard v-if="tax.can.manage" coord="A1" :title="editing ? __('Edit “{name}”', { name: editing.name }) : __('Add a {item}', { item: singular })">
            <form id="k-term-form" class="k-form" @submit.prevent="save">
                <KField v-slot="{ invalid }" id="k-term-name" :label="__('Name')" :error="errors.name ?? ''" required>
                    <KInput id="k-term-name" v-model="form.name" maxlength="191" autocomplete="off" :invalid="invalid" />
                </KField>
                <KField
                    v-slot="{ id, describedBy, invalid }"
                    :label="__('Address')"
                    :help="
                        tax.path
                            ? __('Shown at {path}/{slug}. Leave empty to make one from the name.', { path: tax.path, slug: form.slug || '…' })
                            : __('Leave empty to make one from the name.')
                    "
                    :error="errors.slug ?? ''"
                >
                    <KInput
                        :id="id"
                        v-model="form.slug"
                        maxlength="191"
                        spellcheck="false"
                        autocomplete="off"
                        data-lpignore="true"
                        data-1p-ignore
                        class="k-mono-input"
                        :invalid="invalid"
                        :aria-describedby="describedBy"
                    />
                </KField>
                <KField v-if="tax.hierarchical" v-slot="{ id, invalid }" :label="__('Parent')" :error="errors.parent_id ?? ''">
                    <KSelect :id="id" v-model="parent" :options="parentOptions" :invalid="invalid" />
                </KField>
                <KField
                    v-slot="{ id, describedBy }"
                    :label="__('Description')"
                    :help="__('Some themes show it on the {item} page.', { item: singular })"
                    :error="errors.description ?? ''"
                >
                    <textarea
                        :id="id"
                        v-model="form.description"
                        class="k-input k-textarea"
                        rows="3"
                        maxlength="2000"
                        :aria-describedby="describedBy"
                    ></textarea>
                </KField>
            </form>
            <template #footer>
                <div class="k-form__actions">
                    <KButton v-if="editing" variant="ghost" @click="reset">{{ __('Cancel') }}</KButton>
                    <KButton type="submit" form="k-term-form" variant="primary" :loading="saving" :disabled="!form.name.trim()">{{
                        editing ? __('Save') : __('Add {item}', { item: singular })
                    }}</KButton>
                </div>
            </template>
        </KCard>

        <KCard coord="B1" :class="tax.can.manage ? 'k-span-2' : 'k-span-all'" :title="tax.plural" flush>
            <template #actions>
                <label class="k-inline-field"
                    ><span>{{ __('Filter') }}</span
                    ><input
                        v-model="filter"
                        type="search"
                        class="k-input k-terms__filter"
                        :aria-label="__('Filter {plural}', { plural: tax.plural.toLowerCase() })"
                /></label>
            </template>
            <KTable
                :columns="columns"
                :rows="rows"
                :loading="terms.loading.value && !terms.data.value"
                :caption="tax.plural"
                :empty="filter ? __('Nothing matches “{q}”.', { q: filter }) : __('No {plural} yet', { plural: tax.plural.toLowerCase() })"
                :empty-text="filter ? '' : __('Add the first one on the left.')"
            >
                <template #cell-name="{ row }">
                    <span class="k-term-name" :style="{ '--k-depth': row.depth }">
                        <button
                            v-if="tax.can.manage"
                            type="button"
                            class="k-link-btn"
                            :aria-current="editing?.id === row.id ? 'true' : undefined"
                            @click="edit(row)"
                        >
                            {{ row.name }}
                        </button>
                        <span v-else>{{ row.name }}</span>
                    </span>
                </template>
                <template #cell-slug="{ row }">
                    <a v-if="row.path && row.count" :href="row.path" class="k-term-slug" target="_blank" rel="noopener">{{ row.path }}</a>
                    <span v-else class="k-term-slug">{{ row.path ?? row.slug }}</span>
                </template>
                <template #cell-count="{ row }"
                    ><span class="k-num">{{ row.count }}</span></template
                >
                <template #cell-actions="{ row }">
                    <KButton
                        v-if="tax.can.manage"
                        size="sm"
                        variant="ghost"
                        icon="trash"
                        :aria-label="__('Delete “{name}”', { name: row.name })"
                        @click="remove(row)"
                    />
                </template>
            </KTable>
        </KCard>
    </div>
</template>
