<script setup>
import { computed } from 'vue';
import { __ } from '@kestello/sdk';
const model = defineModel({ default: null });
defineProps({
    field: { type: Object, required: true },
    id: { type: String, default: '' },
    invalid: Boolean,
    disabled: Boolean,
    errors: { type: Object, default: () => ({}) },
    path: { type: String, default: '' },
});
const value = computed({
    get: () => model.value ?? '',
    set: (v) => {
        model.value = v === '' ? null : v;
    },
});
</script>

<template>
    <select :id="id" v-model="value" class="k-input k-select" :disabled="disabled" :aria-invalid="invalid ? 'true' : undefined">
        <option value="">{{ field.required ? __('Choose…') : __('None') }}</option>
        <option v-for="c in field.options.choices" :key="c.value" :value="c.value">{{ c.label }}</option>
    </select>
</template>
