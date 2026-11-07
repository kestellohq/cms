<script setup>
// Core fallback "Site" layout: used for theme-skinned pages (errors, plugin
// pages) only when the active theme ships no layouts/Site.vue of its own.
import { computed } from 'vue';
import { Link, usePage } from '@kestello/app';

const page = usePage();
const site = computed(() => page.props.site?.name ?? 'Kestello');
</script>

<template>
    <div class="kestello-fallback">
        <header>
            <Link href="/">{{ site }}</Link>
        </header>
        <main><slot /></main>
    </div>
</template>

<style scoped>
.kestello-fallback {
    font-family:
        system-ui,
        -apple-system,
        sans-serif;
    color: #1f2937;
    min-height: 100vh;
    background: #f9fafb;
}
header {
    padding: 1rem 1.5rem;
    border-bottom: 1px solid #e5e7eb;
    background: #fff;
    font-weight: 600;
}
header a {
    color: inherit;
    text-decoration: none;
}
main {
    padding: 1rem;
}
</style>
