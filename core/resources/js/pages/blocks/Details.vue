<script setup>
// Rich text is sanitized when it is saved and again when the page is prepared on the server.
import { useCanvas } from '@kestello/app';

defineProps({ summary: { type: String, default: '' }, text: { type: String, default: '' }, open: Boolean });
const canvas = useCanvas();
</script>

<template>
    <details v-if="summary || canvas" class="k-b-details" :open="open || !!canvas">
        <summary data-k-field="summary" data-placeholder="Question or summary" @click="canvas && $event.preventDefault()">{{ summary }}</summary>
        <div class="prose k-b-details__body" data-k-field="text" data-placeholder="What visitors read when they open it" v-html="text"></div>
    </details>
</template>

<style scoped>
.k-b-details {
    border-block: 1px solid color-mix(in srgb, currentColor 18%, transparent);
    padding-block: 0.85rem;
}
.k-b-details + .k-b-details {
    border-top: 0;
}
.k-b-details summary {
    cursor: pointer;
    font-weight: 650;
    font-size: 1.075rem;
}
.k-b-details__body {
    margin-top: 0.6rem;
    max-width: none;
}
.k-b-details__body :deep(> :first-child) {
    margin-top: 0;
}
.k-b-details__body :deep(> :last-child) {
    margin-bottom: 0;
}
</style>
