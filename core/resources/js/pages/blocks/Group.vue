<script setup>
// core/group. Children render in the default slot.
defineProps({ tag: { type: String, default: 'div' }, width: { type: String, default: 'content' }, tone: { type: String, default: 'plain' } });
const tags = ['div', 'section', 'aside', 'header', 'footer'];
</script>

<template>
    <component :is="tags.includes(tag) ? tag : 'div'" class="k-b-group k-b-stack" :class="`k-b-group--${tone}`" :data-width="width"><slot /></component>
</template>

<style scoped>
.k-b-group--muted,
.k-b-group--inverse {
    padding: clamp(1.5rem, 4vw, 3rem);
    border-radius: var(--block-radius, 6px);
}
.k-b-group--muted {
    background: var(--block-muted-bg, color-mix(in srgb, currentColor 6%, transparent));
}
.k-b-group--inverse {
    background: var(--block-inverse-bg, #16181d);
    color: var(--block-inverse-ink, #f6f5f1);
}
.k-b-group--inverse :deep(a) {
    color: inherit;
}
</style>
