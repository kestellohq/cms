<script>
// Wears the active theme's Site layout. Themes replace this page by shipping their own "@core/content/Entry".
export default { layout: 'Site' };
</script>

<script setup>
// the column, spacing, screen sizes and block design: shared by every page that draws blocks
import '../../shared/block-layout.css';
import { computed } from 'vue';
import { BlockTree, Head, KImage, Link, usePage } from '@kestello/app';
import { useI18n } from '@kestello/sdk';

const props = defineProps({
    entry: { type: Object, required: true },
    type: { type: Object, required: true },
    terms: { type: Array, default: () => [] }, // [{ taxonomy, label, terms: [{ name, path }] }]
    translation_missing: Boolean, // shown in the main language, at an address in another: not translated yet
});
const { __ } = useI18n();
const page = usePage();

// the page's language, and the one the entry is written in (the main one) when it isn't translated yet
const languages = computed(() => page.props.i18n?.languages ?? []);
const asked = computed(() => languages.value.find((l) => l.current) ?? null);
const original = computed(() => (props.translation_missing ? (languages.value[0] ?? null) : null));

// Only rich-text fields (entry.html) are rendered as HTML: they are sanitized on save with the
// rich-text profile. Every other value stays text.
// Blocks fields arrive prepared by the server (validated, visibility applied, data resolved).
const trees = computed(() =>
    (props.entry.blocks ?? []).map((k) => ({ key: k, blocks: props.entry.data?.[k] })).filter((t) => Array.isArray(t.blocks) && t.blocks.length),
);
const body = computed(() =>
    (props.entry.html ?? []).map((k) => ({ key: k, html: props.entry.data?.[k] })).filter((p) => typeof p.html === 'string' && p.html !== ''),
);
const date = computed(() =>
    props.type.archive !== null && props.entry.published_at
        ? new Intl.DateTimeFormat(undefined, { dateStyle: 'long' }).format(new Date(props.entry.published_at))
        : '',
);
</script>

<template>
    <Head :title="entry.title" />
    <article class="k-entry" :lang="original?.code">
        <p v-if="original && asked" class="k-entry__untranslated" :lang="asked.code" role="note">
            {{ __('This page is not available in {language} yet. Here it is in {original}.', { language: asked.name, original: original.name }) }}
        </p>
        <header class="k-entry__header">
            <Link v-if="type.archive" :href="type.archive" class="k-entry__back">{{ type.plural }}</Link>
            <h1 class="k-entry__title" data-k-title>{{ entry.title }}</h1>
            <p v-if="date" class="k-entry__meta">
                <time :datetime="entry.published_at">{{ date }}</time>
                <template v-if="entry.author"
                    ><span aria-hidden="true"> · </span><Link v-if="entry.author.path" :href="entry.author.path">{{ entry.author.name }}</Link
                    ><span v-else>{{ entry.author.name }}</span></template
                >
            </p>
        </header>
        <figure v-if="entry.data?.image?.url" class="k-entry__image">
            <KImage :media="entry.data.image" size="large" sizes="(min-width: 46rem) 44rem, calc(100vw - 2rem)" priority />
            <figcaption v-if="entry.data.image.caption">{{ entry.data.image.caption }}</figcaption>
        </figure>
        <div v-for="part in body" :key="part.key" class="prose k-entry__body" v-html="part.html"></div>
        <div v-for="tree in trees" :key="tree.key" class="k-blocks k-entry__blocks"><BlockTree :blocks="tree.blocks" /></div>
        <footer v-if="terms.length" class="k-entry__terms">
            <p v-for="group in terms" :key="group.taxonomy">
                <span class="k-entry__terms-label">{{ group.label }}</span>
                <template v-for="(t, i) in group.terms" :key="t.path ?? t.name">
                    <span v-if="i" aria-hidden="true">, </span>
                    <Link v-if="t.path" :href="t.path">{{ t.name }}</Link>
                    <span v-else>{{ t.name }}</span>
                </template>
            </p>
        </footer>
    </article>
</template>

<style scoped>
.k-entry__untranslated {
    margin: 0 0 1.5rem;
    padding: 0.75rem 1rem;
    border-left: 3px solid currentColor;
    font-size: 0.9375rem;
    opacity: 0.85;
}
.k-entry > .k-entry__image {
    margin-bottom: 2rem;
}
.k-entry__image :deep(img) {
    display: block;
    width: 100%;
    height: auto;
    border-radius: 4px;
}
.k-entry__image figcaption {
    margin-top: 0.5rem;
    font-size: 0.875rem;
    opacity: 0.75;
}
.k-entry__terms {
    margin-top: 3rem;
    padding-top: 1.25rem;
    border-top: 1px solid color-mix(in srgb, currentColor 18%, transparent);
    font-size: 0.9375rem;
}
.k-entry__terms p {
    margin: 0 0 0.4em;
}
.k-entry__terms-label {
    font-weight: 600;
    margin-right: 0.5em;
}
.k-entry {
    padding: clamp(2rem, 6vw, 4.5rem) 0 4rem;
}
.k-entry > :not(.k-blocks) {
    max-width: 44rem;
    margin-inline: auto;
    padding-inline: 1rem;
    box-sizing: content-box;
}
.k-entry__blocks {
    margin-block: 2rem;
}
.k-entry__header {
    margin-bottom: 2rem;
}
.k-entry__back {
    font-size: 0.9rem;
    font-weight: 600;
    text-underline-offset: 3px;
}
.k-entry__title {
    font-size: clamp(2rem, 5vw, 3rem);
    line-height: 1.1;
    letter-spacing: -0.02em;
    margin: 0.4em 0 0.3em;
    text-wrap: balance;
}
.k-entry__meta {
    opacity: 0.75;
    margin: 0;
}
.k-entry__body {
    font-size: 1.075rem;
    line-height: 1.7;
}
.k-entry__body :deep(img) {
    max-width: 100%;
    height: auto;
}
</style>
