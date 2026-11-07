<script setup>
const model = defineModel({ type: [String, Number], default: '' });
defineProps({ type: { type: String, default: 'text' }, invalid: Boolean });
</script>

<template>
    <input v-model="model" :type="type" class="k-input" :aria-invalid="invalid ? 'true' : undefined" />
</template>
