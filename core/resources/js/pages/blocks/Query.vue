<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@kestello/app';

// core/query: the server repeats the blocks inside it once per entry (core/query-item children).
const props = defineProps({
    type: { type: String, default: '' },
    order: { type: String, default: 'newest' },
    limit: { type: Number, default: 6 },
    exclude_current: Boolean,
    width: { type: String, default: 'wide' },
    empty: { type: String, default: '' },
    count: { type: Number, default: 0 },
    pagination: { type: Object, default: null },
});
const page = usePage();
const href = (n) => {
    const [path, query = ''] = page.url.split('?');
    const params = new URLSearchParams(query);
    n > 1 ? params.set('page', String(n)) : params.delete('page');
    const qs = params.toString();
    return qs ? `${path}?${qs}` : path;
};
const pages = computed(() => props.pagination ?? { page: 1, last: 1 });
</script>

<template>
    <div class="k-b-query" :data-width="width">
        <ul v-if="count" class="k-b-query__items">
            <slot />
        </ul>
        <p v-else-if="empty" class="k-b-query__empty" data-k-field="empty">{{ empty }}</p>
        <nav v-if="pages.last > 1" class="k-b-query__pages" aria-label="Pages">
            <Link v-if="pages.page > 1" :href="href(pages.page - 1)" rel="prev">Newer</Link>
            <span>Page {{ pages.page }} of {{ pages.last }}</span>
            <Link v-if="pages.page < pages.last" :href="href(pages.page + 1)" rel="next">Older</Link>
        </nav>
    </div>
</template>

<style scoped>
.k-b-query__items {
    list-style: none;
    margin: 0;
    padding: 0;
    display: grid;
    gap: clamp(1.25rem, 3vw, 2rem);
    grid-template-columns: repeat(auto-fill, minmax(min(100%, 17rem), 1fr));
}
.k-b-query__empty {
    opacity: 0.75;
    margin: 0;
}
.k-b-query__pages {
    display: flex;
    gap: 1.25rem;
    align-items: center;
    justify-content: center;
    margin-top: 2rem;
    font-size: 0.9375rem;
}
.k-b-query__pages a {
    font-weight: 600;
    text-underline-offset: 3px;
}
</style>
