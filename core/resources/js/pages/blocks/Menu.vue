<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@kestello/app';

// Menus are shared with every site page (usePage().props.menus), so this block needs no data of its own.
const props = defineProps({ location: { type: String, default: '' }, orientation: { type: String, default: 'horizontal' } });
const page = usePage();
const items = computed(() => page.props.menus?.[props.location] ?? []);
const internal = (item) => item.url.startsWith('/') && !item.url.startsWith('//') && !item.target;
</script>

<template>
    <nav v-if="items.length" class="k-b-menu" :class="`k-b-menu--${orientation}`" :aria-label="location">
        <ul>
            <li v-for="item in items" :key="item.url + item.label">
                <Link v-if="internal(item)" :href="item.url">{{ item.label }}</Link>
                <a v-else :href="item.url" :target="item.target ?? undefined" :rel="item.target ? 'noopener noreferrer' : undefined">{{ item.label }}</a>
            </li>
        </ul>
    </nav>
</template>

<style scoped>
.k-b-menu ul {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
}
.k-b-menu--horizontal ul {
    flex-direction: row;
    flex-wrap: wrap;
    gap: 0.35rem 1.25rem;
}
.k-b-menu a {
    font-weight: 600;
    text-underline-offset: 3px;
}
</style>
