<script setup>
import { computed, ref } from 'vue';
import { Head, Link, usePage, useRouter } from '@kestello/app';
import { ApiError, clone, confirm, notify, useApi, __ } from '@kestello/sdk';
import { KAlert, KButton, KCard, KField, KIcon, KInput, KModal, KSelect, KSwitch } from '@kestello/ui';
import FieldList from '../../../components/content-types/FieldList.vue';
import ChecklistEditor from '../../../components/content-types/ChecklistEditor.vue';

defineOptions({ layout: 'Admin' });

/**
 * One content type or taxonomy built in the admin. Saving answers with
 * the changes made; changes that affect stored values are listed for
 * review first.
 */
const api = useApi();
const page = usePage();
const router = useRouter();
const id = computed(() => String(page.props.params?.id ?? ''));

const model = ref(null);
const def = ref(null);
const meta = ref(null);
const loadError = ref(null);
const errors = ref({});
const saving = ref(false);

const PERMALINKS = {
    '{slug}': '/events/first-one',
    '{year}/{slug}': '/events/2026/first-one',
    '{year}/{month}/{slug}': '/events/2026/03/first-one',
    '{year}/{month}/{day}/{slug}': '/events/2026/03/14/first-one',
};
const ICONS = [
    'file-text',
    'calendar-dots',
    'briefcase',
    'users',
    'identification-badge',
    'map-pin',
    'storefront',
    'package',
    'book',
    'graduation-cap',
    'camera',
    'film-strip',
    'music-notes',
    'megaphone',
    'quotes',
    'star',
    'trophy',
    'house',
    'wrench',
    'coffee',
    'heart',
    'lightbulb',
    'question',
    'notebook',
];

// choices come back as {value: label}; the form edits them as rows
const toRows = (fields) =>
    fields.map((f) => ({
        ...f,
        options: {
            ...f.options,
            ...(f.options?.choices && !Array.isArray(f.options.choices)
                ? { choices: Object.entries(f.options.choices).map(([value, label]) => ({ value, label })) }
                : {}),
        },
        children: toRows(f.children ?? []),
    }));
const clean = (fields) =>
    fields.map(({ _new, _namedByHand, ...f }) => ({
        ...f,
        options: { ...f.options, ...(Array.isArray(f.options?.choices) ? { choices: f.options.choices.map(({ value, label }) => ({ value, label })) } : {}) },
        children: clean(f.children ?? []),
    }));

async function load() {
    try {
        const [one, all] = await Promise.all([api.get(`/content-models/${id.value}`), api.get('/content-models')]);
        model.value = one.data;
        meta.value = all.meta;
        const d = clone(one.data.definition);
        if (d.fields) d.fields = toRows(d.fields);
        def.value = d;
    } catch (e) {
        loadError.value = e;
    }
}
load();

const isType = computed(() => model.value?.kind === 'type');
const permalinkOptions = computed(() =>
    (meta.value?.permalinks ?? ['{slug}']).map((p) => ({ value: p, label: (PERMALINKS[p] ?? p).replace('/events', def.value?.path || '/events') })),
);
const payload = () => ({ ...def.value, ...(def.value.fields ? { fields: clean(def.value.fields) } : {}) });
const err = (key) => errors.value[key] ?? '';

// ── saving ───────────────────────────────────────────────────────────
const review = ref(null); // { changes } while a save waits for confirmation
const purge = ref(false);

async function save(confirmed = false) {
    saving.value = true;
    errors.value = {};
    try {
        const { data } = await api.patch(`/content-models/${id.value}`, { definition: payload(), confirm: confirmed, purge: confirmed && purge.value });
        review.value = null;
        const moved = data.changes.filter((c) => c.change === 'renamed').length;
        notify.success(moved ? __('Saved. Stored values followed {n} renamed fields.', { n: moved }) : __('Saved.'));
        await load(); // the menu and the entry editor follow from the next page load
    } catch (e) {
        if (e instanceof ApiError && e.status === 409 && !confirmed) {
            try {
                const { data } = await api.post(`/content-models/${id.value}/preview`, { definition: payload() });
                purge.value = false;
                review.value = data;
            } catch (previewError) {
                notify.error(previewError);
            }
        } else if (e instanceof ApiError && e.status === 422) {
            errors.value = Object.fromEntries(Object.entries(e.fieldErrors()).map(([k, v]) => [k.replace(/^definition\./, ''), v]));
            notify.error(__('Some fields need attention.'));
        } else {
            notify.error(e);
        }
    } finally {
        saving.value = false;
    }
}
const changeText = (c) =>
    ({
        removed: c.entries
            ? __('{field} is removed. {n} entries have a value in it.', { field: c.from, n: c.entries })
            : __('{field} is removed. No entry uses it.', { field: c.from }),
        retyped: __('{field} changes from {from} to {to}. {n} entries have a value that may no longer fit.', {
            field: c.field,
            from: c.from,
            to: c.to,
            n: c.entries,
        }),
        renamed: __('{from} becomes {to}. Values move along.', { from: c.from, to: c.to }),
        path:
            c.from && c.to ? __('The address changes from {from} to {to}. Old links are redirected.', { from: c.from, to: c.to }) : __('The address changes.'),
    })[c.change] ?? c.change;

async function remove() {
    if (
        !(await confirm({
            title: __('Delete {name}?', { name: def.value.plural }),
            message: __("Only possible when nothing uses it any more. This can't be undone."),
            confirmLabel: __('Delete'),
            danger: true,
        }))
    )
        return;
    try {
        await api.delete(`/content-models/${id.value}`);
        notify.success(__('{name} deleted.', { name: def.value.plural }));
        router.visit('/admin/content-types');
    } catch (e) {
        notify.error(e);
    }
}

// ── as PHP ───────────────────────────────────────────────────────────
const showPhp = ref(false);
async function copyPhp() {
    try {
        await navigator.clipboard.writeText(model.value.php);
        notify.success(__('Copied.'));
    } catch (e) {
        notify.error(e);
    }
}
</script>

<template>
    <Head :title="def ? def.plural : __('Content type')" />
    <KAlert v-if="loadError" tone="danger">{{ loadError.message }}</KAlert>

    <form v-if="def" @submit.prevent="save()">
        <div class="k-editor-head">
            <Link href="/admin/content-types" class="k-link k-editor-head__back"><KIcon name="arrow-left" /> {{ __('Content types') }}</Link>
            <span class="k-editor-head__state">
                <h1 class="k-ct-title">{{ def.plural }}</h1>
                <code class="k-ct-code">{{ model.name }}</code>
            </span>
            <KButton icon="code" @click="showPhp = true">{{ __('View as PHP') }}</KButton>
            <KButton v-if="isType" :href="`/admin/content/${model.name}`" icon="list-bullets">{{ __('Open entries') }}</KButton>
            <KButton variant="primary" type="submit" icon="check" :loading="saving">{{ __('Save') }}</KButton>
        </div>

        <KAlert v-if="model.skipped ?? false" tone="warning">{{ model.skipped }}</KAlert>

        <div class="k-editor">
            <div class="k-editor__main">
                <template v-if="isType">
                    <h2 class="k-ct-section">{{ __('Fields') }}</h2>
                    <p class="k-muted k-ct-lead">
                        {{
                            __('Every entry has a title, an address, an author and a publishing date. These fields come on top, in this order, in the editor.')
                        }}
                    </p>
                    <FieldList :fields="def.fields" :meta="meta" :errors="errors" />
                    <p v-if="err('fields')" class="k-field__error" role="alert">{{ err('fields') }}</p>
                </template>
                <template v-else>
                    <h2 class="k-ct-section">{{ __('Used for') }}</h2>
                    <div class="k-ct-checks">
                        <label v-for="t in meta.types" :key="t.name" class="k-ct-check"
                            ><input v-model="def.types" type="checkbox" :value="t.name" /> {{ t.label }}</label
                        >
                    </div>
                    <p v-if="err('types')" class="k-field__error" role="alert">{{ err('types') }}</p>
                </template>
            </div>

            <aside class="k-editor__side" :aria-label="__('Settings')">
                <KCard :title="__('Names')">
                    <KField v-slot="{ id: fid, invalid }" :label="__('One')" :error="err('singular')" required>
                        <KInput :id="fid" v-model="def.singular" maxlength="60" :invalid="invalid" />
                    </KField>
                    <KField v-slot="{ id: fid, invalid }" :label="__('Several')" :error="err('plural')" required>
                        <KInput :id="fid" v-model="def.plural" maxlength="60" :invalid="invalid" />
                    </KField>
                    <KField v-slot="{ id: fid }" :label="__('Description')" :help="__('Shown on its list page and to search engines.')">
                        <textarea :id="fid" v-model="def.description" class="k-input k-ct-textarea" rows="3" maxlength="300"></textarea>
                    </KField>
                    <div v-if="isType" class="k-ct-icons" role="radiogroup" :aria-label="__('Menu icon')">
                        <span class="k-ct-icons__label">{{ __('Menu icon') }}</span>
                        <button
                            v-for="icon in ICONS"
                            :key="icon"
                            type="button"
                            role="radio"
                            class="k-ct-icon"
                            :class="{ 'is-on': def.icon === icon }"
                            :aria-checked="def.icon === icon ? 'true' : 'false'"
                            :aria-label="icon.replace(/-/g, ' ')"
                            @click="def.icon = icon"
                        >
                            <KIcon :name="icon" />
                        </button>
                    </div>
                </KCard>

                <KCard :title="__('Public pages')">
                    <KField
                        v-slot="{ id: fid, invalid }"
                        :label="__('Address')"
                        :help="def.path ? __('Leave empty to keep them off the public site.') : __('Such as /events. Empty: editors only, no public pages.')"
                        :error="err('path')"
                    >
                        <KInput
                            :id="fid"
                            :model-value="def.path ?? ''"
                            class="k-mono-input"
                            maxlength="100"
                            spellcheck="false"
                            :invalid="invalid"
                            @update:model-value="(v) => (def.path = v.trim() === '' ? null : v)"
                        />
                    </KField>
                    <template v-if="isType && def.path">
                        <KSwitch v-model="def.archive" :label="__('A page listing all of them')" />
                        <KField v-if="!def.hierarchical" v-slot="{ id: fid }" :label="__('Page addresses')">
                            <KSelect :id="fid" v-model="def.permalink" :options="permalinkOptions" />
                        </KField>
                        <KSwitch v-model="def.searchable" :label="__('Include in site search')" />
                    </template>
                </KCard>

                <KCard :title="__('Behavior')">
                    <div class="k-ct-switches">
                        <KSwitch v-model="def.hierarchical" :label="isType ? __('Entries can have a parent') : __('Terms can have a parent')" />
                        <template v-if="isType">
                            <KSwitch v-model="def.excerpt" :label="__('A short summary field')" />
                        </template>
                    </div>
                    <div v-if="isType" class="k-form__row">
                        <KField v-slot="{ id: fid }" :label="__('Keep revisions')" :help="__('Per entry. 0 keeps none.')">
                            <KInput :id="fid" v-model.number="def.revisions" type="number" min="0" max="500" />
                        </KField>
                        <KField v-slot="{ id: fid }" :label="__('Menu position')" :help="__('Lower comes first.')">
                            <KInput :id="fid" v-model.number="def.menu_order" type="number" min="0" max="1000" />
                        </KField>
                    </div>
                </KCard>

                <KCard v-if="isType" :title="__('Before publishing')">
                    <ChecklistEditor v-model="def.checklist" :checks="meta?.checks ?? []" :taxonomies="meta?.taxonomies ?? []" :errors="errors" />
                </KCard>

                <div class="k-editor__trash">
                    <KButton variant="ghost" icon="trash" @click="remove">{{ isType ? __('Delete this content type') : __('Delete this taxonomy') }}</KButton>
                </div>
            </aside>
        </div>
    </form>

    <KModal :open="review !== null" :title="__('Review the changes')" @close="review = null">
        <template v-if="review">
            <p>{{ __('Some changes affect values already stored in entries.') }}</p>
            <ul class="k-ct-changes">
                <li v-for="(c, i) in review.changes" :key="i" :class="{ 'is-destructive': c.destructive }">
                    <KIcon :name="c.destructive ? 'warning' : 'check'" />
                    <span
                        >{{ changeText(c) }}<template v-if="c.reason"> ({{ c.reason }})</template></span
                    >
                </li>
            </ul>
            <KSwitch
                v-if="review.changes.some((c) => c.change === 'removed' && c.destructive)"
                v-model="purge"
                :label="__('Also delete the removed fields\' values')"
            />
            <p class="k-muted k-small">
                {{
                    purge
                        ? __("The values are deleted from every entry. This can't be undone.")
                        : __('The values stay stored but hidden. Adding the field back with the same name shows them again.')
                }}
            </p>
        </template>
        <template #footer>
            <KButton @click="review = null">{{ __('Go back') }}</KButton>
            <KButton :variant="purge ? 'danger' : 'primary'" :loading="saving" @click="save(true)">{{
                purge ? __('Save and delete the values') : __('Save')
            }}</KButton>
        </template>
    </KModal>

    <KModal :open="showPhp" :title="__('As PHP')" size="lg" @close="showPhp = false">
        <p class="k-muted">
            {{
                __(
                    "The same structure for a theme or plugin's boot() method. Declared in code, it takes over from the one built here, so delete this one once the code is live.",
                )
            }}
        </p>
        <pre class="k-ct-php"><code>{{ model?.php }}</code></pre>
        <template #footer>
            <KButton @click="showPhp = false">{{ __('Close') }}</KButton>
            <KButton variant="primary" icon="clipboard" @click="copyPhp">{{ __('Copy') }}</KButton>
        </template>
    </KModal>
</template>

<style scoped>
.k-ct-title {
    margin: 0;
    font-size: 20px;
    font-weight: 650;
    letter-spacing: -0.01em;
}
.k-ct-code {
    font-family: var(--k-mono);
    font-size: 12px;
    color: var(--k-ink-3);
}
.k-ct-section {
    margin: 0 0 4px;
    font-size: 15px;
    font-weight: 650;
}
.k-ct-lead {
    margin: 0 0 16px;
    max-width: 64ch;
    font-size: 14px;
}
.k-ct-checks {
    display: flex;
    flex-wrap: wrap;
    gap: 8px 20px;
    margin-top: 10px;
}
.k-ct-check {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    cursor: pointer;
}
.k-ct-check input {
    accent-color: var(--k-mark);
}
.k-ct-textarea {
    width: 100%;
    resize: vertical;
    padding: 7px 10px;
    font: inherit;
}
.k-ct-switches {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin-bottom: 12px;
}
.k-ct-icons {
    display: grid;
    grid-template-columns: repeat(8, 1fr);
    gap: 2px;
}
.k-ct-icons__label {
    grid-column: 1 / -1;
    font-size: 13px;
    font-weight: 600;
    margin-bottom: 4px;
}
.k-ct-icon {
    aspect-ratio: 1;
    display: grid;
    place-items: center;
    border: 1px solid transparent;
    border-radius: var(--k-radius);
    background: none;
    color: var(--k-ink-2);
    cursor: pointer;
    font-size: 15px;
}
.k-ct-icon:hover {
    background: var(--k-sheet-2);
    color: var(--k-ink);
}
.k-ct-icon.is-on {
    border-color: var(--k-ink);
    color: var(--k-ink);
    background: var(--k-sheet-2);
}
.k-ct-icon:focus-visible {
    outline: 2px solid var(--k-focus);
    outline-offset: 1px;
}
.k-ct-changes {
    list-style: none;
    margin: 0 0 14px;
    padding: 0;
    border-top: 1px solid var(--k-rule-soft);
}
.k-ct-changes li {
    display: flex;
    gap: 10px;
    align-items: baseline;
    padding: 9px 0;
    border-bottom: 1px solid var(--k-rule-soft);
    font-size: 14px;
}
.k-ct-changes li.is-destructive {
    color: var(--k-danger);
}
.k-ct-changes li :deep(.bi) {
    flex-shrink: 0;
}
.k-ct-php {
    margin: 0;
    max-height: 60vh;
    overflow: auto;
    padding: 14px 16px;
    background: var(--k-sheet-2);
    border: 1px solid var(--k-rule);
    font-family: var(--k-mono);
    font-size: 12.5px;
    line-height: 1.55;
    white-space: pre;
}
@media (max-width: 1100px) {
    .k-editor {
        grid-template-columns: 1fr;
    }
}
</style>
