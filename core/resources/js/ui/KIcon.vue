<script setup>
defineProps({ name: { type: String, required: true }, label: { type: String, default: '' } });
</script>

<template>
    <i class="ph k-icon" :class="`ph-${name}`" :aria-hidden="label ? undefined : 'true'" :aria-label="label || undefined" :role="label ? 'img' : undefined"></i>
</template>
