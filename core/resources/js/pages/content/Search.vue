<script>
// Wears the active theme's Site layout. Themes replace this page by shipping their own "@core/content/Search".
export default { layout: 'Site' };
</script>

<script setup>
import { computed, ref } from 'vue';
import { Head, Link, usePage, useRouter } from '@kestello/app';

const props = defineProps({
    title: { type: String, default: 'Search' },
    query: { type: String, default: '' },
    type: { type: String, default: '' },
    types: { type: Array, default: () => [] }, // [{ value, label }]
    results: { type: Object, default: null }, // { hits, total, page, last_page, facets }
    limited: Boolean,
});
const router = useRouter();
const page = usePage();
const action = computed(() => `${page.props.i18n?.prefix ?? ''}/search`); // in the page's language
const q = ref(props.query);

function submit() {
    router.get(action.value, { q: q.value.trim(), type: props.type || undefined });
}
const href = (params) => {
    const s = new URLSearchParams();
    const merged = { q: props.query, type: props.type, ...params };
    for (const [k, v] of Object.entries(merged)) if (v && !(k === 'page' && v === 1)) s.set(k, String(v));
    return `${action.value}?${s.toString()}`;
};
const counts = computed(() => Object.fromEntries((props.results?.facets ?? []).map((f) => [f.value, f.count])));
const allCount = computed(() => (props.results?.facets ?? []).reduce((n, f) => n + f.count, 0));
const date = (iso) => (iso ? new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(new Date(iso)) : '');
const summary = computed(() => {
    const r = props.results;
    if (!r) return '';
    return r.total === 1 ? `1 result for “${props.query}”` : `${r.total} results for “${props.query}”`;
});
</script>

<template>
    <Head :title="title" />
    <div class="k-search">
        <form class="k-search__form" role="search" :action="action" method="get" @submit.prevent="submit">
            <label class="k-search__label" for="k-search-q">Search the site</label>
            <div class="k-search__bar">
                <input
                    id="k-search-q"
                    v-model="q"
                    class="k-search__input"
                    type="search"
                    name="q"
                    maxlength="200"
                    autocomplete="off"
                    enterkeyhint="search"
                    :autofocus="!query"
                />
                <input v-if="type" type="hidden" name="type" :value="type" />
                <button class="k-search__submit" type="submit">Search</button>
            </div>
        </form>

        <p v-if="limited" class="k-search__note" role="alert">Too many searches in a short time. Please wait a minute and try again.</p>

        <template v-else-if="results">
            <div class="k-search__head">
                <p class="k-search__summary" role="status">{{ summary }}</p>
                <nav v-if="(allCount && results.facets.length > 1) || type" class="k-search__filters" aria-label="Filter results">
                    <Link :href="href({ type: '', page: 1 })" class="k-search__filter" :aria-current="!type ? 'true' : undefined"
                        >All<span v-if="allCount">{{ allCount }}</span></Link
                    >
                    <Link
                        v-for="t in types.filter((t) => counts[t.value] || t.value === type)"
                        :key="t.value"
                        :href="href({ type: t.value, page: 1 })"
                        class="k-search__filter"
                        :aria-current="type === t.value ? 'true' : undefined"
                    >
                        {{ t.label }}<span v-if="counts[t.value]">{{ counts[t.value] }}</span>
                    </Link>
                </nav>
            </div>

            <ol v-if="results.hits.length" class="k-search__hits">
                <li v-for="hit in results.hits" :key="hit.id" class="k-search__hit">
                    <div class="k-search__text">
                        <p class="k-search__meta">
                            <span>{{ hit.type_label }}</span
                            ><time v-if="hit.published_at && hit.type !== 'page'" :datetime="hit.published_at">{{ date(hit.published_at) }}</time>
                        </p>
                        <h2 class="k-search__title">
                            <Link v-if="hit.url" :href="hit.url"
                                ><template v-for="(part, i) in hit.title_parts" :key="i"
                                    ><mark v-if="part.match">{{ part.text }}</mark
                                    ><template v-else>{{ part.text }}</template></template
                                ></Link
                            >
                        </h2>
                        <p v-if="hit.snippet.length" class="k-search__snippet">
                            <template v-for="(part, i) in hit.snippet" :key="i"
                                ><mark v-if="part.match">{{ part.text }}</mark
                                ><template v-else>{{ part.text }}</template></template
                            >
                        </p>
                    </div>
                    <img v-if="hit.image" class="k-search__thumb" :src="hit.image" alt="" loading="lazy" decoding="async" width="96" height="96" />
                </li>
            </ol>
            <div v-else class="k-search__empty">
                <p>Nothing matches “{{ query }}”{{ type ? ' here' : '' }}.</p>
                <p>
                    Try fewer or different words<template v-if="type">, or <Link :href="href({ type: '', page: 1 })">search everything</Link></template
                    >.
                </p>
            </div>

            <nav v-if="results.last_page > 1" class="k-search__pages" aria-label="Pages">
                <Link v-if="results.page > 1" :href="href({ page: results.page - 1 })" rel="prev">Previous</Link>
                <span>Page {{ results.page }} of {{ results.last_page }}</span>
                <Link v-if="results.page < results.last_page" :href="href({ page: results.page + 1 })" rel="next">Next</Link>
            </nav>
        </template>
    </div>
</template>

<style scoped>
.k-search {
    max-width: 44rem;
    margin: 0 auto;
    padding: clamp(2rem, 6vw, 4.5rem) 1rem 4rem;
}
.k-search__label {
    display: block;
    font-size: clamp(1.75rem, 4.5vw, 2.5rem);
    font-weight: 700;
    line-height: 1.1;
    letter-spacing: -0.02em;
    margin-bottom: 1.25rem;
}
.k-search__bar {
    display: flex;
    gap: 0.5rem;
}
.k-search__input {
    flex: 1;
    min-width: 0;
    min-height: 3rem;
    padding: 0.6rem 1rem;
    font: inherit;
    font-size: 1.0625rem;
    color: inherit;
    background: var(--block-field-bg, #fff);
    border: 1px solid color-mix(in srgb, currentColor 30%, transparent);
    border-radius: var(--block-radius, 6px);
}
.k-search__input:focus-visible {
    outline: 3px solid var(--block-accent, #2448c9);
    outline-offset: 1px;
    border-color: transparent;
}
.k-search__submit {
    min-height: 3rem;
    padding: 0.6rem 1.35rem;
    border: 0;
    border-radius: var(--block-button-radius, 999px);
    background: var(--block-accent, #2448c9);
    color: var(--block-accent-ink, #fff);
    font: inherit;
    font-weight: 700;
    cursor: pointer;
}
.k-search__submit:hover {
    background: var(--block-accent-deep, #1a379e);
}
.k-search__submit:focus-visible {
    outline: 3px solid var(--block-accent, #2448c9);
    outline-offset: 3px;
}
.k-search__note {
    margin: 2rem 0 0;
}
.k-search__head {
    margin: 2.25rem 0 1rem;
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}
.k-search__summary {
    margin: 0;
    font-size: 0.9375rem;
    opacity: 0.8;
}
.k-search__filters {
    display: flex;
    flex-wrap: wrap;
    gap: 0.4rem;
}
.k-search__filter {
    padding: 0.3rem 0.8rem;
    border: 1px solid color-mix(in srgb, currentColor 25%, transparent);
    border-radius: 999px;
    font-size: 0.875rem;
    font-weight: 600;
    text-decoration: none;
    color: inherit;
}
.k-search__filter span {
    margin-left: 0.35em;
    font-variant-numeric: tabular-nums;
    opacity: 0.65;
    font-weight: 500;
}
.k-search__filter:hover {
    border-color: currentColor;
}
.k-search__filter[aria-current] {
    background: var(--block-accent, #2448c9);
    border-color: var(--block-accent, #2448c9);
    color: var(--block-accent-ink, #fff);
}
.k-search__filter[aria-current] span {
    opacity: 0.85;
}
.k-search__hits {
    list-style: none;
    margin: 0;
    padding: 0;
}
.k-search__hit {
    display: flex;
    gap: 1.25rem;
    align-items: flex-start;
    padding: 1.25rem 0;
    border-top: 1px solid color-mix(in srgb, currentColor 15%, transparent);
}
.k-search__text {
    flex: 1;
    min-width: 0;
}
.k-search__meta {
    margin: 0 0 0.25rem;
    display: flex;
    gap: 0.75rem;
    font-size: 0.8125rem;
    opacity: 0.7;
}
.k-search__title {
    margin: 0;
    font-size: 1.25rem;
    line-height: 1.3;
    letter-spacing: -0.01em;
    text-wrap: balance;
}
.k-search__title a {
    color: inherit;
    text-decoration-thickness: 1px;
    text-underline-offset: 3px;
}
.k-search__snippet {
    margin: 0.4rem 0 0;
    line-height: 1.6;
    opacity: 0.85;
    overflow-wrap: anywhere;
}
.k-search mark {
    background: none;
    color: inherit;
    font-weight: 700;
    text-decoration: underline;
    text-decoration-color: var(--block-accent, #2448c9);
    text-decoration-thickness: 2px;
    text-underline-offset: 3px;
}
.k-search__thumb {
    flex: none;
    width: 6rem;
    height: 6rem;
    object-fit: cover;
    border-radius: var(--block-radius, 6px);
}
.k-search__empty {
    padding: 1.5rem 0;
    border-top: 1px solid color-mix(in srgb, currentColor 15%, transparent);
}
.k-search__empty p {
    margin: 0 0 0.4rem;
}
.k-search__pages {
    display: flex;
    gap: 1.25rem;
    align-items: center;
    justify-content: center;
    margin-top: 2rem;
    font-size: 0.9375rem;
}
.k-search__pages a {
    font-weight: 600;
    text-underline-offset: 3px;
}
@media (max-width: 30rem) {
    .k-search__thumb {
        width: 4rem;
        height: 4rem;
    }
}
</style>
