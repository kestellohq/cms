<script setup>
const model = defineModel({ type: [String, Number], default: '' });
defineProps({ options: { type: Array, default: () => [] }, invalid: Boolean }); // [{ value, label, disabled }]
</script>

<template>
    <select v-model="model" class="k-input k-select" :aria-invalid="invalid ? 'true' : undefined">
        <option v-for="option in options" :key="option.value" :value="option.value" :disabled="option.disabled">{{ option.label }}</option>
    </select>
</template>
