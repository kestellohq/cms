<script setup>
// Rich text is sanitized when it is saved and again when the page is prepared on the server.
import { useCanvas } from '@kestello/app';

defineProps({ text: { type: String, default: '' }, align: { type: String, default: 'start' } });
const canvas = useCanvas();
</script>

<template>
    <div
        v-if="text || canvas"
        :class="['prose', 'k-b-text', `k-b-align--${align}`]"
        data-k-field="text"
        data-placeholder="Write, or type / to add a block"
        v-html="text"
    ></div>
</template>

<style scoped>
.k-b-text {
    max-width: none;
    font-size: 1.075rem;
    line-height: 1.7;
}
.k-b-text :deep(> :first-child) {
    margin-top: 0;
}
.k-b-text :deep(> :last-child) {
    margin-bottom: 0;
}
.k-b-text :deep(img) {
    max-width: 100%;
    height: auto;
}
.k-b-align--center {
    text-align: center;
}
.k-b-align--end {
    text-align: end;
}
.k-b-text :deep(mark) {
    background: color-mix(in srgb, #ffd23f 55%, transparent);
    color: inherit;
    padding: 0 0.1em;
}
.k-b-text :deep(code) {
    font-size: 0.9em;
    padding: 0.1em 0.3em;
    border-radius: 3px;
    background: color-mix(in srgb, currentColor 9%, transparent);
}
</style>
