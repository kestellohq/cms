<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, Link, useRouter } from '@kestello/app';
import { ApiError, notify, useResource, useApi, __ } from '@kestello/sdk';
import { KAlert, KBadge, KButton, KCard, KField, KInput, KModal, KPageHeader, KSwitch, KTable } from '@kestello/ui';

defineOptions({ layout: 'Admin' });

/**
 * System → Content types: the types and taxonomies built here, and the
 * ones themes and plugins declare in code (read-only).
 */
const api = useApi();
const router = useRouter();
const list = useResource('/content-models');

const built = computed(() => list.data.value ?? []);
const code = computed(() => list.meta.value?.code ?? []);
const types = computed(() => list.meta.value?.types ?? []);

const builtColumns = [
    { key: 'label', label: __('Name') },
    { key: 'kind', label: __('Kind') },
    { key: 'path', label: __('Address') },
    { key: 'fields', label: __('Fields'), align: 'end' },
];
const codeColumns = [
    { key: 'label', label: __('Name') },
    { key: 'kind', label: __('Kind') },
    { key: 'path', label: __('Address') },
    { key: 'owner', label: __('Defined by') },
];
const owner = (o) =>
    o === 'core'
        ? __('Kestello')
        : o.startsWith('theme:')
          ? __('Theme {name}', { name: o.slice(6) })
          : o.startsWith('plugin:')
            ? __('Plugin {name}', { name: o.slice(7) })
            : o;
const count = (fields) => {
    let n = 0;
    const walk = (list) =>
        list.forEach((f) => {
            n++;
            walk(f.children ?? []);
        });
    walk(fields ?? []);
    return n;
};

// ── create ───────────────────────────────────────────────────────────
const creating = ref(null); // null, 'type' or 'taxonomy'
const form = reactive({ singular: '', plural: '', name: '', public: true, path: '', types: [] });
const touched = reactive({ plural: false, name: false, path: false });
const errors = ref({});
const saving = ref(false);
const slug = (s) =>
    s
        .toLowerCase()
        .normalize('NFKD')
        .replace(/[̀-ͯ]/g, '')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');

function start(kind) {
    Object.assign(form, { singular: '', plural: '', name: '', public: true, path: '', types: [] });
    Object.assign(touched, { plural: false, name: false, path: false });
    errors.value = {};
    creating.value = kind;
}
function singularChanged() {
    if (!touched.plural) form.plural = form.singular ? `${form.singular}s` : '';
    if (!touched.name)
        form.name = slug(form.singular)
            .replace(/^[^a-z]+/, '')
            .slice(0, 32);
    pluralChanged();
}
function pluralChanged() {
    if (!touched.path) form.path = form.plural ? `/${slug(form.plural)}` : '';
}
async function create() {
    saving.value = true;
    errors.value = {};
    const definition = { singular: form.singular.trim(), plural: form.plural.trim(), path: form.public ? form.path.trim() : null };
    if (creating.value === 'type') Object.assign(definition, { archive: form.public, fields: [] });
    else definition.types = form.types;
    try {
        const { data } = await api.post('/content-models', { kind: creating.value, name: form.name.trim(), definition });
        notify.success(
            creating.value === 'type'
                ? __('{name} created. Now add its fields.', { name: data.definition.plural })
                : __('{name} created.', { name: data.definition.plural }),
        );
        creating.value = null;
        router.visit(`/admin/content-types/${data.id}`);
    } catch (e) {
        if (e instanceof ApiError && e.status === 422) errors.value = e.fieldErrors();
        else notify.error(e);
    } finally {
        saving.value = false;
    }
}
const err = (key) => errors.value[key] ?? errors.value[`definition.${key}`] ?? '';
</script>

<template>
    <Head :title="__('Content types')" />
    <KPageHeader
        :title="__('Content types')"
        :description="
            __(
                'The kinds of content this site has and the fields each one holds. Types you build here work like the built-in ones: the editor, the API, search and public pages all follow them.',
            )
        "
    >
        <template #actions>
            <KButton icon="tag" @click="start('taxonomy')">{{ __('New taxonomy') }}</KButton>
            <KButton variant="primary" icon="plus" @click="start('type')">{{ __('New content type') }}</KButton>
        </template>
    </KPageHeader>

    <KAlert v-if="list.error.value" tone="danger">{{ list.error.value.message }}</KAlert>
    <KAlert v-for="m in built.filter((b) => b.skipped)" :key="m.id" tone="warning">
        {{ __('{name} is switched off: {reason}', { name: m.definition.plural, reason: m.skipped }) }}
    </KAlert>

    <KCard coord="T1" :title="__('Built here')" flush>
        <KTable
            :columns="builtColumns"
            :rows="built"
            :loading="list.loading.value && !list.data.value"
            :empty="__('Nothing built yet')"
            :empty-text="__('Create a content type for things like events, products or team members, with exactly the fields they need.')"
        >
            <template #cell-label="{ row }">
                <Link :href="`/admin/content-types/${row.id}`" class="k-link k-ct__name">{{ row.definition.plural }}</Link>
                <code class="k-ct__code">{{ row.name }}</code>
            </template>
            <template #cell-kind="{ row }"
                ><KBadge>{{ row.kind === 'type' ? __('Content type') : __('Taxonomy') }}</KBadge></template
            >
            <template #cell-path="{ row }">
                <code v-if="row.definition.path" class="k-ct__code">{{ row.definition.path }}</code>
                <span v-else class="k-muted">{{ __('Not public') }}</span>
            </template>
            <template #cell-fields="{ row }">
                <span v-if="row.kind === 'type'" class="k-num">{{ count(row.definition.fields) }}</span>
                <span v-else class="k-muted k-small">{{ row.definition.types.map((t) => types.find((x) => x.name === t)?.label ?? t).join(', ') }}</span>
            </template>
        </KTable>
    </KCard>

    <KCard
        coord="T2"
        :title="__('From the theme and plugins')"
        :description="__('Declared in code. Change them there, or copy one into a type built here.')"
        flush
    >
        <KTable :columns="codeColumns" :rows="code" :empty="__('None.')">
            <template #cell-label="{ row }"
                ><span class="k-ct__name">{{ row.label }}</span> <code class="k-ct__code">{{ row.name }}</code></template
            >
            <template #cell-kind="{ row }"
                ><KBadge>{{ row.kind === 'type' ? __('Content type') : __('Taxonomy') }}</KBadge></template
            >
            <template #cell-path="{ row }">
                <code v-if="row.path !== null" class="k-ct__code">{{ row.path === '' ? '/' : row.path }}</code>
                <span v-else class="k-muted">{{ __('Not public') }}</span>
            </template>
            <template #cell-owner="{ row }"
                ><span class="k-muted">{{ owner(row.owner) }}</span></template
            >
        </KTable>
    </KCard>

    <KModal :open="creating !== null" :title="creating === 'type' ? __('New content type') : __('New taxonomy')" @close="creating = null">
        <form id="k-ct-create" class="k-form" @submit.prevent="create">
            <div class="k-form__row">
                <KField
                    v-slot="{ id, describedBy, invalid }"
                    :label="__('One')"
                    :help="creating === 'type' ? __('Such as Event') : __('Such as Topic')"
                    :error="err('singular')"
                    required
                >
                    <KInput
                        :id="id"
                        v-model="form.singular"
                        maxlength="60"
                        :invalid="invalid"
                        :aria-describedby="describedBy"
                        @update:model-value="singularChanged"
                    />
                </KField>
                <KField
                    v-slot="{ id, describedBy, invalid }"
                    :label="__('Several')"
                    :help="creating === 'type' ? __('Such as Events') : __('Such as Topics')"
                    :error="err('plural')"
                    required
                >
                    <KInput
                        :id="id"
                        v-model="form.plural"
                        maxlength="60"
                        :invalid="invalid"
                        :aria-describedby="describedBy"
                        @update:model-value="
                            touched.plural = true;
                            pluralChanged();
                        "
                    />
                </KField>
            </div>
            <KField
                v-slot="{ id, describedBy, invalid }"
                :label="__('Name')"
                :help="__('Used in the API and templates, and can\'t change later. Lowercase letters, digits and dashes.')"
                :error="err('name')"
                required
            >
                <KInput
                    :id="id"
                    v-model="form.name"
                    class="k-mono-input"
                    maxlength="32"
                    spellcheck="false"
                    autocomplete="off"
                    :invalid="invalid"
                    :aria-describedby="describedBy"
                    @update:model-value="touched.name = true"
                />
            </KField>
            <fieldset v-if="creating === 'taxonomy'" class="k-ct__types">
                <legend>{{ __('For') }}</legend>
                <label v-for="t in types" :key="t.name" class="k-ct__check"><input v-model="form.types" type="checkbox" :value="t.name" /> {{ t.label }}</label>
                <p v-if="err('types')" class="k-field__error" role="alert">{{ err('types') }}</p>
            </fieldset>
            <KSwitch v-model="form.public" :label="creating === 'type' ? __('Public pages and a list of all of them') : __('A public page for each term')" />
            <KField
                v-if="form.public"
                v-slot="{ id, describedBy, invalid }"
                :label="__('Address')"
                :help="__('Pages live below it, such as {path}/first-one.', { path: form.path || '/events' })"
                :error="err('path')"
            >
                <KInput
                    :id="id"
                    v-model="form.path"
                    class="k-mono-input"
                    maxlength="100"
                    spellcheck="false"
                    autocomplete="off"
                    :invalid="invalid"
                    :aria-describedby="describedBy"
                    @update:model-value="touched.path = true"
                />
            </KField>
        </form>
        <template #footer>
            <KButton @click="creating = null">{{ __('Cancel') }}</KButton>
            <KButton variant="primary" type="submit" form="k-ct-create" :loading="saving">{{ __('Create') }}</KButton>
        </template>
    </KModal>
</template>

<style scoped>
.k-ct__name {
    font-weight: 600;
}
.k-ct__code {
    font-family: var(--k-mono);
    font-size: 12px;
    color: var(--k-ink-3);
    margin-left: 8px;
}
td .k-ct__code:first-child {
    margin-left: 0;
}
.k-ct__types {
    border: 0;
    margin: 0 0 14px;
    padding: 0;
    display: flex;
    flex-wrap: wrap;
    gap: 6px 18px;
}
.k-ct__types legend {
    font-size: 13px;
    font-weight: 600;
    margin-bottom: 6px;
    padding: 0;
}
.k-ct__check {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    cursor: pointer;
}
.k-ct__check input {
    accent-color: var(--k-mark);
}
.k-num {
    font-variant-numeric: tabular-nums;
}
</style>
