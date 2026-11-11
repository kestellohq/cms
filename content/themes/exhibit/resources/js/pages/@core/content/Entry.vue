<script>
// Exhibit's page for every entry (it replaces core's "@core/content/Entry"):
// a project hangs as prints with its label plate, a talk is dated wall text,
// everything else is read on paper. The text and blocks are always below.
export default { layout: 'Site' };
</script>

<script setup>
import '@kestello/blocks.css';
import { computed } from 'vue';
import { BlockTree, Head, Link, usePage } from '@kestello/app';
import { useI18n } from '@kestello/sdk';
import Plate from '../../../components/Plate.vue';
import Print from '../../../components/Print.vue';
import { credit, photosOf, roomName, talkDate } from '../../../components/rooms.js';

const props = defineProps({
    entry: { type: Object, required: true },
    type: { type: Object, required: true },
    terms: { type: Array, default: () => [] },
    translation_missing: Boolean,
});
const { __ } = useI18n('exhibit');
const page = usePage();
const locale = computed(() => page.props.i18n?.locale ?? 'en');

const kind = computed(() => (props.type.name === 'projects' ? 'project' : props.type.name === 'talks' ? 'talk' : 'text'));
const photos = computed(() => photosOf(props.entry.data));
const [first, rest] = [computed(() => photos.value[0] ?? null), computed(() => photos.value.slice(1))];
const talk = computed(() => (kind.value === 'talk' ? talkDate(props.entry.data?.starts ?? props.entry.published_at, locale.value) : null));
const room = computed(() => roomName(props.type.name, props.type.plural, __));

// rich text arrives sanitized; blocks arrive prepared by the server
const trees = computed(() =>
    (props.entry.blocks ?? []).map((k) => ({ key: k, blocks: props.entry.data?.[k] })).filter((t) => Array.isArray(t.blocks) && t.blocks.length),
);
const body = computed(() =>
    (props.entry.html ?? []).map((k) => ({ key: k, html: props.entry.data?.[k] })).filter((p) => typeof p.html === 'string' && p.html !== ''),
);
const date = computed(() =>
    kind.value === 'text' && props.type.archive && props.entry.published_at
        ? new Intl.DateTimeFormat(locale.value, { dateStyle: 'long' }).format(new Date(props.entry.published_at))
        : '',
);

// shown in the main language at an address in another: say so, in the page's language
const languages = computed(() => page.props.i18n?.languages ?? []);
const asked = computed(() => languages.value.find((l) => l.current) ?? null);
const original = computed(() => (props.translation_missing ? (languages.value[0] ?? null) : null));
</script>

<template>
    <Head :title="entry.title" />

    <!-- a project: the first print on the wall, its label plate beside it -->
    <section v-if="kind === 'project'" class="work" :lang="original?.code">
        <Print :media="first" size="large" sizes="(min-width: 900px) 62vw, 92vw" priority alt="" />
        <div>
            <Link v-if="type.archive" :href="type.archive" class="work__back">{{ room }}</Link>
            <h1 class="work__title" data-k-title>{{ entry.title }}</h1>
            <Plate :title="entry.title" :fields="entry.data" :media="first" heading="p" />
        </div>
    </section>
    <div v-if="kind === 'project' && rest.length" class="hang">
        <figure v-for="m in rest" :key="m.id ?? m.url">
            <Print :media="m" size="medium" sizes="(min-width: 760px) 40vw, 92vw" :alt="m.alt ?? ''" />
            <figcaption v-if="credit(m)">{{ credit(m) }}</figcaption>
        </figure>
    </div>

    <!-- a talk: its date as wall text -->
    <section v-else-if="kind === 'talk'" class="wall" :lang="original?.code">
        <Link v-if="type.archive" :href="type.archive" class="work__back">{{ room }}</Link>
        <p v-if="talk" class="programme__date">
            {{ talk.day }} {{ talk.year }}<small>{{ talk.past ? __('This talk has taken place.') : talk.time }}</small>
        </p>
        <h1 class="work__title" data-k-title>{{ entry.title }}</h1>
        <p v-if="entry.data?.place" class="wall-lede">{{ entry.data.place }}</p>
    </section>

    <!-- the text, on paper -->
    <article class="reading" :lang="original?.code">
        <p v-if="original && asked" class="untranslated" :lang="asked.code" role="note">
            {{ __('This page is not available in {language} yet. Here it is in {original}.', { language: asked.name, original: original.name }) }}
        </p>
        <header v-if="kind === 'text'" class="reading__head">
            <Link v-if="type.archive" :href="type.archive" class="work__back">{{ room }}</Link>
            <h1 data-k-title>{{ entry.title }}</h1>
            <p v-if="date" class="reading__meta">
                <time :datetime="entry.published_at">{{ date }}</time>
                <template v-if="entry.author"><span aria-hidden="true"> · </span>{{ entry.author.name }}</template>
            </p>
        </header>
        <figure v-if="kind === 'text' && first" class="reading__figure">
            <Print :media="first" size="large" sizes="(min-width: 960px) 58rem, 92vw" priority alt="" />
            <figcaption v-if="credit(first)">{{ credit(first) }}</figcaption>
        </figure>
        <div v-if="body.length" class="reading__inner">
            <div v-for="part in body" :key="part.key" class="prose" v-html="part.html"></div>
        </div>
        <div v-for="tree in trees" :key="tree.key" class="k-blocks"><BlockTree :blocks="tree.blocks" /></div>
        <footer v-if="terms.length" class="reading__terms">
            <p v-for="group in terms" :key="group.taxonomy">
                <span>{{ group.label }}</span>
                <template v-for="(t, i) in group.terms" :key="t.path ?? t.name">
                    <span v-if="i" aria-hidden="true">, </span>
                    <Link v-if="t.path" :href="t.path">{{ t.name }}</Link>
                    <span v-else>{{ t.name }}</span>
                </template>
            </p>
        </footer>
    </article>
</template>
