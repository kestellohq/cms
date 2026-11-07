<script setup>
import { computed } from 'vue';
const model = defineModel({ default: null });
defineProps({
    field: { type: Object, required: true },
    id: { type: String, default: '' },
    invalid: Boolean,
    disabled: Boolean,
    errors: { type: Object, default: () => ({}) },
    path: { type: String, default: '' },
});
// Empty input means "no value", never 0.
const value = computed({
    get: () => model.value ?? '',
    set: (v) => {
        model.value = v === '' || v === null ? null : Number(v);
    },
});
</script>

<template>
    <input
        :id="id"
        v-model="value"
        type="number"
        class="k-input k-number-field"
        :min="field.options.min"
        :max="field.options.max"
        :step="field.options.integer ? 1 : (field.options.step ?? 'any')"
        :disabled="disabled"
        :aria-invalid="invalid ? 'true' : undefined"
        inputmode="decimal"
    />
</template>
