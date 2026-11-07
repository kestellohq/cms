<script setup>
defineProps({ size: { type: String, default: 'md' } });
</script>

<template>
    <div class="k-b-spacer" :class="`k-b-spacer--${size}`" aria-hidden="true"></div>
</template>

<style scoped>
.k-b-spacer {
    height: 1.5rem;
}
.k-b-spacer--sm {
    height: 0.75rem;
}
.k-b-spacer--lg {
    height: clamp(2.5rem, 6vw, 4rem);
}
.k-b-spacer--xl {
    height: clamp(4rem, 10vw, 7rem);
}
</style>
