<script>
// Error pages wear the active theme's "Site" layout (core ships a fallback).
export default { layout: 'Site' };
</script>

<script setup>
import { Head, Link } from '@kestello/app';

// The words come translated from the server (Kestello\Cms\Frontend\ErrorPage::props).
defineProps({
    status: { type: Number, default: 500 },
    heading: { type: String, default: 'Something went wrong' },
    message: { type: String, default: '' },
    home: { type: String, default: 'Go to the home page' },
});
</script>

<template>
    <Head :title="heading" />
    <section class="kestello-error">
        <p class="kestello-error-code">{{ status }}</p>
        <h1>{{ heading }}</h1>
        <p v-if="message">{{ message }}</p>
        <Link href="/" class="kestello-error-home">{{ home }}</Link>
    </section>
</template>

<style scoped>
/* the theme's colours, only quieter: right on light and dark themes alike. The button reads the
   same theme variables as the Button block, so it looks like the theme's own buttons. */
.kestello-error {
    max-width: 36rem;
    margin: 12vh auto;
    padding: 0 1rem;
    text-align: center;
}
.kestello-error-code {
    font-size: 4rem;
    font-weight: 700;
    opacity: 0.3;
    margin: 0;
    line-height: 1;
}
.kestello-error h1 {
    font-size: 1.75rem;
    margin: 1rem 0 0.5rem;
}
.kestello-error p:not(.kestello-error-code) {
    opacity: 0.75;
    margin: 0 0 1.5rem;
}
.kestello-error-home {
    display: inline-flex;
    align-items: center;
    min-height: 2.75rem;
    padding: 0.6rem 1.35rem;
    border-radius: var(--block-button-radius, 999px);
    border: 2px solid var(--block-accent, #2448c9);
    background: var(--block-accent, #2448c9);
    color: var(--block-accent-ink, #fff);
    font-weight: 700;
    text-decoration: none;
    transition:
        background-color 0.15s,
        border-color 0.15s;
}
.kestello-error-home:hover {
    background: var(--block-accent-deep, #1a379e);
    border-color: var(--block-accent-deep, #1a379e);
}
.kestello-error-home:focus-visible {
    outline: 3px solid var(--block-accent, #2448c9);
    outline-offset: 3px;
}
@media (prefers-reduced-motion: reduce) {
    .kestello-error-home {
        transition: none;
    }
}
</style>
