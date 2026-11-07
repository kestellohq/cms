<script setup>
import { computed } from 'vue';
import { Link, useCanvas } from '@kestello/app';

const props = defineProps({
    text: { type: String, default: '' },
    link: { type: String, default: '' },
    variant: { type: String, default: 'primary' },
    new_tab: Boolean,
});
const canvas = useCanvas();
const internal = computed(() => props.link.startsWith('/') && !props.link.startsWith('//') && !props.new_tab);
</script>

<template>
    <p v-if="canvas" class="k-b-button">
        <span class="k-b-button__link" :class="`k-b-button--${variant}`"
            ><span data-k-field="text" data-placeholder="Button">{{ text }}</span></span
        >
    </p>
    <p v-else-if="text && link" class="k-b-button">
        <Link v-if="internal" :href="link" class="k-b-button__link" :class="`k-b-button--${variant}`">{{ text }}</Link>
        <a
            v-else
            :href="link"
            class="k-b-button__link"
            :class="`k-b-button--${variant}`"
            :target="new_tab ? '_blank' : undefined"
            :rel="new_tab ? 'noopener noreferrer' : undefined"
            >{{ text }}<span v-if="new_tab" class="k-b-button__sr"> (opens in a new tab)</span></a
        >
    </p>
</template>

<style scoped>
:where(.k-b-button) {
    margin-block: 0;
} /* no weight: the spacing between blocks (.k-b-stack > * + *) wins */
.k-b-button__link {
    display: inline-flex;
    align-items: center;
    min-height: 2.75rem;
    padding: 0.6rem 1.35rem;
    border-radius: var(--block-button-radius, 999px);
    border: 2px solid var(--block-accent, #2448c9);
    font-weight: 700;
    text-decoration: none;
    transition:
        background-color 0.15s,
        color 0.15s,
        border-color 0.15s;
}
.k-b-button--primary {
    background: var(--block-accent, #2448c9);
    color: var(--block-accent-ink, #fff);
}
.k-b-button--primary:hover {
    background: var(--block-accent-deep, #1a379e);
    border-color: var(--block-accent-deep, #1a379e);
}
.k-b-button--secondary {
    color: var(--block-accent, #2448c9);
    background: none;
}
.k-b-button--secondary:hover {
    background: var(--block-accent, #2448c9);
    color: var(--block-accent-ink, #fff);
}
.k-b-button--plain {
    border-color: transparent;
    padding-inline: 0;
    color: var(--block-accent, #2448c9);
    text-decoration: underline;
    text-underline-offset: 3px;
}
.k-b-button__link:focus-visible {
    outline: 3px solid var(--block-accent, #2448c9);
    outline-offset: 3px;
}
.k-b-button__sr {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip-path: inset(50%);
    white-space: nowrap;
}
@media (prefers-reduced-motion: reduce) {
    .k-b-button__link {
        transition: none;
    }
}
</style>
