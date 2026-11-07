<script setup>
import { useCanvas } from '@kestello/app';

defineProps({ text: { type: String, default: '' }, cite: { type: String, default: '' }, align: { type: String, default: 'start' } });
const canvas = useCanvas();
</script>

<template>
    <figure v-if="text || canvas" :class="['k-b-quote', `k-b-align--${align}`]">
        <blockquote data-k-field="text" data-placeholder="Quote">{{ text }}</blockquote>
        <figcaption v-if="cite || canvas" data-k-field="cite" data-placeholder="Who said it">{{ cite }}</figcaption>
    </figure>
</template>

<style scoped>
:where(.k-b-quote) {
    margin-block: 0;
} /* no weight: the spacing between blocks (.k-b-stack > * + *) wins */
.k-b-quote {
    padding-block: 1.25rem;
    border-block: 1px solid color-mix(in srgb, currentColor 22%, transparent);
}
.k-b-quote blockquote {
    margin: 0;
    font-size: clamp(1.25rem, 2.6vw, 1.5rem);
    font-weight: 600;
    line-height: 1.4;
    letter-spacing: -0.01em;
    white-space: pre-line;
    text-wrap: pretty;
}
.k-b-quote figcaption {
    margin-top: 0.6rem;
    font-size: 0.9375rem;
    opacity: 0.75;
}
.k-b-align--center {
    text-align: center;
}
.k-b-align--end {
    text-align: end;
}
</style>
