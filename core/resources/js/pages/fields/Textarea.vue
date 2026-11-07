<script setup>
const model = defineModel({ default: null });
defineProps({
    field: { type: Object, required: true },
    id: { type: String, default: '' },
    invalid: Boolean,
    disabled: Boolean,
    errors: { type: Object, default: () => ({}) },
    path: { type: String, default: '' },
});
</script>

<template>
    <textarea
        :id="id"
        v-model="model"
        class="k-input k-textarea"
        :rows="field.options.rows ?? 4"
        :maxlength="field.options.max"
        :placeholder="field.options.placeholder"
        :disabled="disabled"
        :aria-invalid="invalid ? 'true' : undefined"
    ></textarea>
</template>
