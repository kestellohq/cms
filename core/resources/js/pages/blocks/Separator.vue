<script setup>
// core/separator. The look was saved as "style" once; the server renames it (BlockRegistry::RENAMED).
defineProps({ look: { type: String, default: 'line' } });
</script>

<template>
    <hr class="k-b-separator" :class="`k-b-separator--${look}`" />
</template>

<style scoped>
:where(.k-b-separator) {
    margin-block: 0;
} /* no weight: the spacing between blocks (.k-b-stack > * + *) wins */
.k-b-separator {
    border: 0;
    border-top: 1px solid color-mix(in srgb, currentColor 35%, transparent);
    width: min(100%, 8rem);
    margin-inline: auto;
    overflow: visible;
}
.k-b-separator--wide {
    width: 100%;
}
.k-b-separator--dots {
    border: 0;
    width: 100%;
    height: auto;
    text-align: center;
    line-height: 1;
}
.k-b-separator--dots::after {
    content: '\00b7\00b7\00b7';
    display: block;
    font-size: 2.25rem;
    letter-spacing: 0.9em;
    padding-left: 0.9em;
    opacity: 0.7;
}
</style>
