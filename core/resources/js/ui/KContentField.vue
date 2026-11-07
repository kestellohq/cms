<script setup>
import { computed, useId } from 'vue';
import KExtension from './KExtension.vue';

/**
 * One content field: label, help, error and the field type's input
 * component (loaded by name, so plugin field types work the same way).
 * Group and repeater components nest more of these.
 *
 *   <KContentField v-for="f in fields" :key="f.name" v-model="data[f.name]" :field="f" :errors="errors" path="data" />
 *
 * errors are keyed by dotted path, as the API returns them (data.speakers.0.name).
 */
const model = defineModel({ default: null });
const props = defineProps({
    field: { type: Object, required: true },
    errors: { type: Object, default: () => ({}) },
    path: { type: String, default: 'data' },
    disabled: Boolean,
});
const uid = useId();
const id = computed(() => `k-cf-${uid}`);
const key = computed(() => `${props.path}.${props.field.name}`);
const error = computed(() => {
    const e = props.errors[key.value];
    return Array.isArray(e) ? e[0] : (e ?? '');
});
const set = computed(() => ['group', 'repeater', 'multi_select', 'boolean', 'blocks'].includes(props.field.type) || props.field.options?.fieldset);
// Block trees list every problem inside the editor itself.
const ownErrors = computed(() => props.field.type === 'blocks');
const describedBy = computed(
    () => [props.field.help ? `${id.value}-help` : '', error.value && !ownErrors.value ? `${id.value}-error` : ''].filter(Boolean).join(' ') || undefined,
);
</script>

<template>
    <component
        :is="set ? 'fieldset' : 'div'"
        class="k-field k-content-field"
        :class="[{ 'has-error': error }, `k-content-field--${field.width === 'half' ? 'half' : 'full'}`]"
    >
        <legend v-if="set" class="k-field__label">{{ field.label }}<span v-if="field.required" class="k-field__required" aria-hidden="true"> *</span></legend>
        <label v-else class="k-field__label" :for="id"
            >{{ field.label }}<span v-if="field.required" class="k-field__required" aria-hidden="true"> *</span></label
        >
        <KExtension
            v-model="model"
            :name="field.component"
            :id="id"
            :field="field"
            :errors="errors"
            :path="key"
            :invalid="!!error"
            :disabled="disabled"
            :aria-describedby="describedBy"
        />
        <p v-if="field.help" :id="`${id}-help`" class="k-field__help">{{ field.help }}</p>
        <p v-if="error && !ownErrors" :id="`${id}-error`" class="k-field__error">{{ error }}</p>
    </component>
</template>
