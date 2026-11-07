<script>
// Wears the active theme's Site layout. Themes replace this page by shipping their own "@core/content/Archive".
export default { layout: 'Site' };
</script>

<script setup>
import { computed } from 'vue';
import { Head, KImage, Link } from '@kestello/app';

const props = defineProps({
    type: { type: Object, required: true },
    entries: { type: Array, default: () => [] },
    pagination: { type: Object, required: true },
    term: { type: Object, default: null }, // a category or tag page
    date: { type: Object, default: null }, // /blog/2026/11: { year, month, archive, label }
    author: { type: Object, default: null }, // /author/ana: { name }
});
const heading = computed(() => {
    if (props.date) return `${props.date.label} from ${props.type.plural}`;
    if (props.author) return `Published by ${props.author.name}`;
    return props.type.plural;
});
const fmt = (iso) => (iso ? new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(new Date(iso)) : '');
const pageUrl = (n) => (n <= 1 ? props.type.path : `${props.type.path}?page=${n}`);
</script>

<template>
    <Head :title="heading" />
    <section class="k-archive">
        <header class="k-archive__header">
            <h1>{{ heading }}</h1>
            <p v-if="type.description">{{ type.description }}</p>
            <p v-else-if="date">
                <Link :href="date.archive">All {{ date.label.toLowerCase() }}</Link>
            </p>
        </header>
        <p v-if="!entries.length" class="k-archive__empty">{{ term ? 'Nothing published here uses it yet.' : 'Nothing has been published here yet.' }}</p>
        <ol v-else class="k-archive__list">
            <li v-for="(e, i) in entries" :key="e.id" class="k-archive__item" :class="{ 'has-image': e.image }">
                <div class="k-archive__text">
                    <time v-if="e.published_at" :datetime="e.published_at" class="k-archive__date">{{ fmt(e.published_at) }}</time>
                    <h2 class="k-archive__title">
                        <Link :href="e.path">{{ e.title }}</Link>
                    </h2>
                    <p v-if="e.excerpt" class="k-archive__excerpt">{{ e.excerpt }}</p>
                </div>
                <Link v-if="e.image" :href="e.path" class="k-archive__image" tabindex="-1" aria-hidden="true">
                    <KImage :media="e.image" size="medium" sizes="(min-width: 40rem) 12rem, 100vw" :priority="i === 0" alt="" />
                </Link>
            </li>
        </ol>
        <nav v-if="pagination.last > 1" class="k-archive__pager" aria-label="Pages">
            <Link v-if="pagination.page > 1" :href="pageUrl(pagination.page - 1)" rel="prev">Newer</Link>
            <span>Page {{ pagination.page }} of {{ pagination.last }}</span>
            <Link v-if="pagination.page < pagination.last" :href="pageUrl(pagination.page + 1)" rel="next">Older</Link>
        </nav>
    </section>
</template>

<style scoped>
.k-archive {
    max-width: 44rem;
    margin: 0 auto;
    padding: clamp(2rem, 6vw, 4.5rem) 1rem 4rem;
}
.k-archive__header h1 {
    font-size: clamp(2rem, 5vw, 3rem);
    letter-spacing: -0.02em;
    margin: 0 0 0.3em;
}
.k-archive__header p {
    opacity: 0.75;
    margin: 0;
}
.k-archive__list {
    list-style: none;
    margin: 2.5rem 0 0;
    padding: 0;
}
.k-archive__item {
    padding: 1.5rem 0;
    border-top: 1px solid color-mix(in srgb, currentColor 18%, transparent);
}
.k-archive__item.has-image {
    display: grid;
    gap: 1rem;
}
.k-archive__image {
    display: block;
    order: -1;
    aspect-ratio: 3 / 2;
    overflow: hidden;
    border-radius: 4px;
    background: color-mix(in srgb, currentColor 8%, transparent);
}
.k-archive__image :deep(img) {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
    transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1);
}
.k-archive__image:hover :deep(img) {
    transform: scale(1.03);
}
@media (min-width: 40rem) {
    .k-archive__item.has-image {
        grid-template-columns: minmax(0, 1fr) 12rem;
        gap: 1.75rem;
        align-items: start;
    }
    .k-archive__image {
        order: 0;
    }
}
@media (prefers-reduced-motion: reduce) {
    .k-archive__image :deep(img) {
        transition: none;
    }
}
.k-archive__date {
    font-size: 0.875rem;
    opacity: 0.7;
}
.k-archive__title {
    font-size: 1.4rem;
    line-height: 1.25;
    margin: 0.25em 0 0.35em;
    text-wrap: balance;
}
.k-archive__title a {
    text-decoration: none;
}
.k-archive__title a:hover {
    text-decoration: underline;
    text-underline-offset: 3px;
}
.k-archive__excerpt {
    margin: 0;
    max-width: 65ch;
    opacity: 0.85;
}
.k-archive__empty {
    margin-top: 2rem;
    opacity: 0.75;
}
.k-archive__pager {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    margin-top: 2rem;
    padding-top: 1.5rem;
    border-top: 1px solid color-mix(in srgb, currentColor 18%, transparent);
}
</style>
