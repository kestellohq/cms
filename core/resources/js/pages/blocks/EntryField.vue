<script setup>
import { computed } from 'vue';
import { KImage, Link } from '@kestello/app';

// core/entry-field: the server sends the value (kind: text, date, html, media) of the entry being viewed or listed.
const props = defineProps({
    field: { type: String, default: 'title' },
    custom: { type: String, default: '' },
    tag: { type: String, default: 'p' },
    link: Boolean,
    kind: { type: String, default: 'text' },
    value: { type: [String, Number, Object], default: null },
    url: { type: String, default: null },
});
const tags = ['p', 'h2', 'h3', 'h4', 'span', 'div'];
const el = computed(() => (props.kind === 'media' ? 'div' : tags.includes(props.tag) ? props.tag : 'p'));
const date = computed(() =>
    props.kind === 'date' && props.value ? new Intl.DateTimeFormat(undefined, { dateStyle: 'long' }).format(new Date(props.value)) : '',
);
</script>

<template>
    <component :is="el" v-if="value !== null && value !== ''" class="k-b-field" :class="`k-b-field--${kind}`">
        <component :is="url ? Link : 'span'" :href="url ?? undefined" class="k-b-field__inner">
            <KImage v-if="kind === 'media'" :media="value" size="medium" sizes="(min-width: 40rem) 24rem, 100vw" />
            <time v-else-if="kind === 'date'" :datetime="value">{{ date }}</time>
            <span v-else-if="kind === 'html'" class="prose" v-html="value"></span>
            <template v-else>{{ value }}</template>
        </component>
    </component>
</template>

<style scoped>
:where(.k-b-field) {
    margin-block: 0;
} /* no weight: the spacing between blocks (.k-b-stack > * + *) wins */
h2.k-b-field,
h3.k-b-field,
h4.k-b-field {
    line-height: 1.2;
    letter-spacing: -0.01em;
    text-wrap: balance;
}
h3.k-b-field {
    font-size: 1.25rem;
}
.k-b-field--date {
    font-size: 0.875rem;
    opacity: 0.75;
}
span.k-b-field__inner {
    display: contents;
}
a.k-b-field__inner {
    color: inherit;
    text-decoration-thickness: 1px;
    text-underline-offset: 3px;
}
.k-b-field--media :deep(img) {
    display: block;
    width: 100%;
    height: auto;
    aspect-ratio: 3 / 2;
    object-fit: cover;
    border-radius: var(--block-radius, 6px);
}
.k-b-field--media a.k-b-field__inner {
    display: block;
}
</style>
