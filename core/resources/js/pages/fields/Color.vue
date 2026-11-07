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
    <div class="k-input-row k-color-field">
        <input v-model="model" type="color" class="k-color-field__swatch" :disabled="disabled" :aria-label="field.label" tabindex="-1" />
        <input
            :id="id"
            v-model="model"
            class="k-input k-mono-input"
            maxlength="7"
            placeholder="#000000"
            pattern="#[0-9a-fA-F]{6}"
            spellcheck="false"
            autocomplete="off"
            :disabled="disabled"
            :aria-invalid="invalid ? 'true' : undefined"
        />
    </div>
</template>
