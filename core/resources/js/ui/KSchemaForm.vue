<script setup>
import { computed } from 'vue';
import { __ } from '@kestello/sdk';
import KField from './KField.vue';
import KInput from './KInput.vue';
import KSelect from './KSelect.vue';
import KSwitch from './KSwitch.vue';

/**
 * Renders fields for an OpenAPI object schema (what the API's Schema
 * builder emits): strings, numbers, booleans and enums.
 *
 *   <KSchemaForm v-model="options" :schema="driver.schema" :errors="errors"
 *                :secrets="['password']" :secrets-set="{ password: true }" :locked="{ host: 'environment' }" />
 *
 * Secrets render as password fields; leaving one blank keeps the stored value.
 */
const model = defineModel({ type: Object, default: () => ({}) });
const props = defineProps({
    schema: { type: Object, required: true },
    errors: { type: Object, default: () => ({}) },
    secrets: { type: Array, default: () => [] },
    secretsSet: { type: Object, default: () => ({}) },
    locked: { type: Object, default: () => ({}) }, // key => source label
    labels: { type: Object, default: () => ({}) },
});

const fields = computed(() =>
    Object.entries(props.schema.properties ?? {}).map(([key, def]) => ({
        key,
        def,
        label: props.labels[key] ?? def.title ?? key.replace(/[_.-]/g, ' ').replace(/^\w/, (c) => c.toUpperCase()),
        required: (props.schema.required ?? []).includes(key) && def.default === undefined,
        secret: props.secrets.includes(key),
        kind: Array.isArray(def.enum) ? 'enum' : def.type === 'boolean' ? 'bool' : def.type === 'integer' || def.type === 'number' ? 'number' : 'string',
    })),
);

function set(key, value) {
    model.value = { ...model.value, [key]: value };
}

function help(field) {
    if (props.locked[field.key]) return __('Set by {source}; it cannot be changed here.', { source: props.locked[field.key] });
    if (field.secret && props.secretsSet[field.key]) return __('Stored. Leave blank to keep it.');
    const parts = [];
    if (field.def.description) parts.push(field.def.description);
    if (
        field.def.default !== undefined &&
        field.def.default !== null &&
        field.def.default !== '' &&
        !field.secret &&
        field.kind !== 'bool' &&
        field.def.format !== 'multiline'
    )
        parts.push(__('Default: {value}', { value: String(field.def.default) }));
    return parts.join(' ');
}
</script>

<template>
    <div class="k-schema-form">
        <KField
            v-for="field in fields"
            :key="field.key"
            v-slot="{ id, describedBy, invalid }"
            :label="field.label"
            :required="field.required"
            :help="help(field)"
            :error="errors[field.key] ?? ''"
        >
            <KSwitch
                v-if="field.kind === 'bool'"
                :id="id"
                :model-value="!!(model[field.key] ?? field.def.default)"
                :label="field.label"
                hide-label
                :disabled="!!locked[field.key]"
                @update:model-value="set(field.key, $event)"
            />
            <KSelect
                v-else-if="field.kind === 'enum'"
                :id="id"
                :model-value="model[field.key] ?? field.def.default ?? ''"
                :options="field.def.enum.map((v) => ({ value: v, label: field.def['x-enum-labels']?.[v] ?? String(v) }))"
                :disabled="!!locked[field.key]"
                :invalid="invalid"
                :aria-describedby="describedBy"
                @update:model-value="set(field.key, $event)"
            />
            <KInput
                v-else-if="field.kind === 'number'"
                :id="id"
                type="number"
                :model-value="model[field.key] ?? ''"
                :placeholder="field.def.default !== undefined ? String(field.def.default) : ''"
                :min="field.def.minimum"
                :max="field.def.maximum"
                :disabled="!!locked[field.key]"
                :invalid="invalid"
                :aria-describedby="describedBy"
                @update:model-value="set(field.key, $event === '' ? undefined : Number($event))"
            />
            <textarea
                v-else-if="field.def.format === 'multiline'"
                :id="id"
                class="k-input k-schema-form__text"
                rows="8"
                spellcheck="false"
                :value="model[field.key] ?? ''"
                :maxlength="field.def.maxLength"
                :disabled="!!locked[field.key]"
                :aria-invalid="invalid ? 'true' : undefined"
                :aria-describedby="describedBy"
                @input="set(field.key, $event.target.value)"
            ></textarea>
            <KInput
                v-else
                :id="id"
                :type="field.secret ? 'password' : 'text'"
                :model-value="model[field.key] ?? ''"
                :placeholder="
                    field.secret && secretsSet[field.key]
                        ? '••••••••'
                        : field.def.default !== undefined && field.def.default !== null
                          ? String(field.def.default)
                          : ''
                "
                :autocomplete="field.secret ? 'new-password' : 'off'"
                :maxlength="field.def.maxLength"
                :disabled="!!locked[field.key]"
                :invalid="invalid"
                :aria-describedby="describedBy"
                @update:model-value="set(field.key, $event)"
            />
        </KField>
        <p v-if="!fields.length" class="k-muted">{{ __('This driver has no settings.') }}</p>
    </div>
</template>
