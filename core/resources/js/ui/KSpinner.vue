<script setup>
defineProps({ label: { type: String, default: 'Loading' }, size: { type: String, default: 'md' } });
</script>

<template>
    <span class="k-spinner" :class="`k-spinner--${size}`" role="status"
        ><span class="k-sr-only">{{ label }}</span></span
    >
</template>
