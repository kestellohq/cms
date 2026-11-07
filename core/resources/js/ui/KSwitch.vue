<script setup>
const model = defineModel({ type: Boolean, default: false });
defineProps({ label: { type: String, required: true }, disabled: Boolean, hideLabel: Boolean });
</script>

<template>
    <button type="button" role="switch" class="k-switch" :aria-checked="model ? 'true' : 'false'" :disabled="disabled" @click="model = !model">
        <span class="k-switch__track" aria-hidden="true"><span class="k-switch__thumb"></span></span>
        <span :class="hideLabel ? 'k-sr-only' : 'k-switch__label'">{{ label }}</span>
    </button>
</template>
