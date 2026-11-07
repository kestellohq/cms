<script setup>
import { useCanvas } from '@kestello/app';

defineProps({ code: { type: String, default: '' }, language: { type: String, default: '' } });
const canvas = useCanvas();
</script>

<template>
    <figure v-if="code || canvas" class="k-b-code">
        <figcaption v-if="language" class="k-b-code__lang">{{ language }}</figcaption>
        <pre><code data-k-field="code" data-placeholder="Code">{{ code }}</code></pre>
    </figure>
</template>

<style scoped>
:where(.k-b-code) {
    margin-block: 0;
} /* no weight: the spacing between blocks (.k-b-stack > * + *) wins */
.k-b-code__lang {
    font-size: 0.8125rem;
    font-weight: 600;
    opacity: 0.7;
    margin-bottom: 0.35rem;
}
.k-b-code pre {
    margin: 0;
    padding: 1rem 1.15rem;
    overflow-x: auto;
    border-radius: 6px;
    background: color-mix(in srgb, currentColor 7%, transparent);
    font-size: 0.9rem;
    line-height: 1.6;
}
.k-b-code code {
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    white-space: pre;
    display: block;
    min-height: 1.6em;
}
</style>
