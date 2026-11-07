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
        <Link href="/" class="btn btn-primary">{{ home }}</Link>
    </section>
</template>

<style scoped>
/* the theme's colours, only quieter: right on light and dark themes alike */
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
</style>
