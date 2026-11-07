<script setup>
import { computed } from 'vue';
import { __ } from '@kestello/sdk';
import { KButton, KField, KInput, KSelect, KSwitch } from '@kestello/ui';
import ConditionsEditor from './ConditionsEditor.vue';
import FieldList from './FieldList.vue';
import { adjustForType, followLabel, INDEXABLE, NESTING, NO_DEFAULT, setChoiceLabel, setMultiple } from './fields.js';
import { fieldTypeLabel } from './field-labels.js';
import { DEFAULT_MAX_FILES, DEFAULT_MAX_MB, MOST_FILES } from '../../shared/file-hint.js';

/**
 * One field's settings: label, name, type, width, help, the type's own options, whether
 * it is required, and when it shows. Used inside FieldList's rows and beside the form
 * builder's canvas. Edits the field in place.
 */
const props = defineProps({
    field: { type: Object, required: true },
    index: { type: Number, required: true }, // its place, for error keys and the fields before it
    siblings: { type: Array, required: true }, // the list it is in
    meta: { type: Object, required: true },
    errors: { type: Object, default: () => ({}) },
    path: { type: String, default: 'fields' },
    depth: { type: Number, default: 1 },
    mode: { type: String, default: 'content' }, // content | form
});
const f = computed(() => props.field);
const isForm = computed(() => props.mode === 'form');
const KINDS = [
    ['image', __('Images')],
    ['document', __('Documents')],
    ['audio', __('Audio')],
    ['video', __('Video')],
    ['archive', __('Archives')],
];

const typeOptions = computed(() =>
    Object.keys(props.meta.options)
        .filter((t) => props.depth < 3 || !NESTING.includes(t))
        .map((t) => ({ value: t, label: fieldTypeLabel(t) })),
);
const widthOptions = computed(() => [
    { value: 'full', label: __('Full width') },
    { value: 'half', label: __('Half width') },
    ...(props.depth === 1 && !isForm.value ? [{ value: 'sidebar', label: __('In the sidebar') }] : []),
]);
const err = (key = '') => props.errors[`${props.path}.${props.index}${key ? '.' + key : ''}`] ?? '';
const has = (key) => key in (props.meta.options[f.value.type] ?? {});
const optionList = (key) => (Array.isArray(f.value.options[key]) ? f.value.options[key] : (f.value.options[key] = []));
function toggleIn(list, value) {
    const i = list.indexOf(value);
    i === -1 ? list.push(value) : list.splice(i, 1);
}
const typeChanged = () => adjustForType(f.value, props.meta.options[f.value.type] ?? {}, { taxonomies: props.meta.taxonomies ?? [], depth: props.depth });
</script>

<template>
    <div class="k-fs">
        <p v-if="err()" class="k-field__error" role="alert">{{ err() }}</p>
        <div class="k-fs__grid">
            <KField v-slot="{ id, describedBy, invalid }" :label="__('Label')" :error="err('label')">
                <KInput :id="id" v-model="f.label" maxlength="100" :invalid="invalid" :aria-describedby="describedBy" @update:model-value="followLabel(f)" />
            </KField>
            <KField
                v-slot="{ id, describedBy, invalid }"
                :label="__('Name')"
                :help="
                    isForm
                        ? __('The key each answer is stored under. Starts with a letter; lowercase letters, digits and underscores.')
                        : f._new
                          ? __('Used in templates and the API. Lowercase letters, digits and underscores.')
                          : __('Renaming keeps the stored values.')
                "
                :error="err('name')"
                required
            >
                <KInput
                    :id="id"
                    v-model="f.name"
                    class="k-mono-input"
                    maxlength="64"
                    spellcheck="false"
                    autocomplete="off"
                    :invalid="invalid"
                    :aria-describedby="describedBy"
                    @update:model-value="f._namedByHand = true"
                />
            </KField>
            <KField v-slot="{ id, describedBy, invalid }" :label="__('Type')" :error="err('type')">
                <KSelect
                    :id="id"
                    v-model="f.type"
                    :options="typeOptions"
                    :invalid="invalid"
                    :aria-describedby="describedBy"
                    @update:model-value="typeChanged"
                />
            </KField>
            <KField v-if="f.type !== 'group' && f.type !== 'repeater'" v-slot="{ id }" :label="isForm ? __('Width') : __('Width in the editor')">
                <KSelect :id="id" v-model="f.width" :options="widthOptions" />
            </KField>
            <KField
                v-slot="{ id }"
                class="k-fs__wide"
                :label="__('Help text')"
                :help="isForm ? __('Shown under the field.') : __('Shown under the field in the editor.')"
            >
                <KInput :id="id" v-model="f.help" maxlength="300" />
            </KField>
        </div>

        <div v-if="has('choices')" class="k-fs__choices">
            <div class="k-fs__subhead">
                <h4>{{ __('Choices') }}</h4>
                <span class="k-muted k-small">{{
                    isForm ? __('The value is stored; the label is what visitors see.') : __('The value is stored; the label is what editors see.')
                }}</span>
            </div>
            <p v-if="err('options.choices')" class="k-field__error" role="alert">{{ err('options.choices') }}</p>
            <div v-for="(c, ci) in optionList('choices')" :key="ci" class="k-fs__choice">
                <KInput v-model="c.value" class="k-mono-input" :aria-label="__('Value of choice {n}', { n: ci + 1 })" maxlength="100" spellcheck="false" />
                <KInput
                    :model-value="c.label"
                    :aria-label="__('Label of choice {n}', { n: ci + 1 })"
                    maxlength="200"
                    @update:model-value="(v) => setChoiceLabel(c, v)"
                />
                <KButton size="sm" variant="ghost" icon="x" :aria-label="__('Remove choice {n}', { n: ci + 1 })" @click="f.options.choices.splice(ci, 1)" />
            </div>
            <KButton size="sm" icon="plus" @click="optionList('choices').push({ value: '', label: '' })">{{ __('Add a choice') }}</KButton>
        </div>

        <div v-if="has('kinds')" class="k-fs__set">
            <span class="k-fs__set-label">{{ __('Accepts') }}</span>
            <label v-for="[k, l] in KINDS" :key="k" class="k-fs__check"
                ><input type="checkbox" :checked="optionList('kinds').includes(k)" @change="toggleIn(f.options.kinds, k)" /> {{ l }}</label
            >
            <span class="k-muted k-small">{{ __('Nothing ticked: any file.') }}</span>
        </div>
        <div v-if="has('types')" class="k-fs__set">
            <span class="k-fs__set-label">{{ __('Links to') }}</span>
            <label v-for="t in meta.types" :key="t.name" class="k-fs__check"
                ><input type="checkbox" :checked="optionList('types').includes(t.name)" @change="toggleIn(f.options.types, t.name)" /> {{ t.label }}</label
            >
            <span class="k-muted k-small">{{ __('Nothing ticked: any type.') }}</span>
        </div>

        <div class="k-fs__grid">
            <KField v-if="has('taxonomy')" v-slot="{ id, invalid }" :label="__('Taxonomy')" :error="err('options.taxonomy')" required>
                <KSelect :id="id" v-model="f.options.taxonomy" :options="meta.taxonomies.map((t) => ({ value: t.name, label: t.label }))" :invalid="invalid" />
            </KField>
            <KField v-if="has('display')" v-slot="{ id }" :label="__('Shown as')">
                <KSelect
                    :id="id"
                    v-model="f.options.display"
                    :options="[
                        { value: 'dropdown', label: __('A dropdown') },
                        { value: 'radios', label: __('Radio buttons') },
                    ]"
                />
            </KField>
            <KField
                v-if="has('max_size')"
                v-slot="{ id, invalid }"
                :label="__('Largest file (MB)')"
                :help="__('Empty: {size} MB. At most {max} MB.', { size: DEFAULT_MAX_MB, max: meta.max_upload_mb ?? 20 })"
                :error="err('options.max_size')"
            >
                <KInput :id="id" v-model="f.options.max_size" type="number" min="1" :max="meta.max_upload_mb ?? 20" :invalid="invalid" />
            </KField>
            <KField
                v-if="has('placeholder')"
                v-slot="{ id }"
                :label="__('Example shown inside the empty field')"
                :help="__('Optional. Help text under the field is easier to read.')"
            >
                <KInput :id="id" v-model="f.options.placeholder" maxlength="200" />
            </KField>
            <KField
                v-if="has('min')"
                v-slot="{ id, invalid }"
                :label="f.type === 'number' ? __('Smallest') : f.type === 'repeater' ? __('Fewest items') : __('Fewest characters')"
                :error="err('options.min')"
            >
                <KInput :id="id" v-model="f.options.min" type="number" :invalid="invalid" />
            </KField>
            <KField
                v-if="has('max') && (f.type !== 'file' || f.options.multiple)"
                v-slot="{ id, invalid }"
                :help="f.type === 'file' ? __('Empty: {count}. At most {max}.', { count: DEFAULT_MAX_FILES, max: MOST_FILES }) : ''"
                :label="
                    f.type === 'number'
                        ? __('Largest')
                        : f.type === 'file'
                          ? __('Most files')
                          : ['repeater', 'media', 'entry', 'term', 'user'].includes(f.type)
                            ? __('Most items')
                            : __('Most characters')
                "
                :error="err('options.max')"
            >
                <KInput :id="id" v-model="f.options.max" type="number" :invalid="invalid" />
            </KField>
            <KField v-if="has('step')" v-slot="{ id }" :label="__('Step')">
                <KInput :id="id" v-model="f.options.step" type="number" step="any" />
            </KField>
            <KField v-if="has('rows')" v-slot="{ id }" :label="__('Rows')">
                <KInput :id="id" v-model="f.options.rows" type="number" min="2" max="40" />
            </KField>
            <KField
                v-if="!NO_DEFAULT.includes(f.type) && f.type !== 'boolean'"
                v-slot="{ id }"
                :label="__('Default value')"
                :help="has('choices') ? __('One of the values above.') : ''"
            >
                <KInput :id="id" v-model="f.default" maxlength="1000" />
            </KField>
        </div>

        <div class="k-fs__switches">
            <KSwitch v-model="f.required" :label="isForm ? __('Required') : __('Required to publish')" />
            <KSwitch
                v-if="has('multiple')"
                :model-value="!!f.options.multiple"
                :label="f.type === 'file' ? __('Allow several files') : __('Allow several')"
                @update:model-value="(on) => setMultiple(f, on)"
            />
            <KSwitch v-if="has('integer')" v-model="f.options.integer" :label="__('Whole numbers only')" />
            <KSwitch
                v-if="f.type === 'boolean'"
                :model-value="f.default === true"
                :label="__('On by default')"
                @update:model-value="(v) => (f.default = v ? true : null)"
            />
            <KSwitch
                v-if="!isForm && depth === 1 && INDEXABLE.includes(f.type) && !f.options?.multiple"
                v-model="f.indexed"
                :label="__('Use for sorting and filtering')"
            />
            <KSwitch v-if="!isForm" v-model="f.internal" :label="__('Editors only (never shown publicly)')" />
        </div>

        <ConditionsEditor v-if="depth === 1" :field="f" :earlier="siblings.slice(0, index)" :error="err('conditions')" />

        <div v-if="f.type === 'group' || f.type === 'repeater'" class="k-fs__children">
            <div class="k-fs__subhead">
                <h4>{{ f.type === 'repeater' ? __('Fields in each item') : __('Fields in the group') }}</h4>
            </div>
            <p v-if="err('children')" class="k-field__error" role="alert">{{ err('children') }}</p>
            <FieldList :fields="f.children" :meta="meta" :errors="errors" :path="`${path}.${index}.children`" :depth="depth + 1" :mode="mode" />
        </div>
    </div>
</template>

<style>
.k-fs > * + * {
    margin-top: 14px;
}
.k-fs__grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 220px), 1fr));
    align-items: start;
    gap: 14px 16px;
}
.k-fs__grid > .k-field + .k-field {
    margin-top: 0; /* the grid's gap spaces them, side by side or stacked */
}
.k-fs__wide {
    grid-column: 1 / -1;
}
.k-fs__switches {
    display: flex;
    flex-wrap: wrap;
    gap: 10px 22px;
    margin: 10px 0 4px;
}
.k-fs__subhead {
    display: flex;
    align-items: baseline;
    gap: 12px;
    flex-wrap: wrap;
    margin: 14px 0 8px;
}
.k-fs__subhead h4 {
    margin: 0;
    font-size: 13px;
    font-weight: 600;
}
.k-fs__choices {
    margin-bottom: 8px;
}
.k-fs__choice {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1.4fr) auto;
    gap: 8px;
    align-items: center;
    margin-bottom: 6px;
    max-width: 40rem;
}
.k-fs__set {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px 16px;
    margin: 10px 0;
}
.k-fs__set-label {
    font-size: 13px;
    font-weight: 600;
    margin-right: 4px;
}
.k-fs__check {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 14px;
    cursor: pointer;
}
.k-fs__check input {
    accent-color: var(--k-mark);
}
.k-fs__children {
    margin-top: 12px;
    padding: 2px 0 2px 16px;
    border-left: 1px solid var(--k-rule);
}
@media (max-width: 640px) {
    .k-fs__choice {
        grid-template-columns: 1fr auto;
    }
    .k-fs__choice > :first-child {
        grid-column: 1 / -1;
    }
}
</style>
