<script>
export default { layout: 'Site' };
</script>

<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@kestello/app';
import { useI18n } from '@kestello/sdk';
import Plate from '../components/Plate.vue';
import Print from '../components/Print.vue';
import { plateRows, roomName, talkDate } from '../components/rooms.js';

/**
 * The entrance: wall text and the newest work on the projects wall, then
 * one band per room. A room the site has no content type for is left out.
 */
const props = defineProps({
    rooms: { type: Object, default: () => ({}) }, // { projects, talks, post }: { name, plural, path, entries }
    intro: { type: Object, default: null }, // the studio page: { title, description, path }
    contact: { type: Object, default: null }, // the contact page
});
const { __ } = useI18n('exhibit');
const page = usePage();
const locale = computed(() => page.props.i18n?.locale ?? 'en');

const tagline = computed(() => page.props.site?.titles?.tagline || page.props.site?.name || '');
const projects = computed(() => props.rooms.projects ?? null);
const talks = computed(() => props.rooms.talks ?? null);
const journal = computed(() => props.rooms.post ?? null);
const people = computed(() => props.rooms.team ?? null);
const [large, small] = [computed(() => projects.value?.entries?.[0] ?? null), computed(() => projects.value?.entries?.[1] ?? null)];

// a room's number is its place in the rooms menu, so the walls and the signs agree
const menu = computed(() => page.props.menus?.primary ?? []);
const numberOf = (path) => {
    const i = path ? menu.value.findIndex((item) => item.url === path) : -1;
    return i < 0 ? null : i + 1;
};
const fmt = (iso) => (iso ? new Intl.DateTimeFormat(locale.value, { dateStyle: 'long' }).format(new Date(iso)) : '');
const date = (e) => talkDate(e.fields?.starts ?? e.published_at, locale.value);
// a catalogue row's place and use, as the label plate words them
const meta = (e) =>
    plateRows(e.fields, __)
        .filter((r) => r.key === 'place' || r.key === 'use')
        .map((r) => r.value)
        .join(', ');
</script>

<template>
    <Head :title="page.props.site?.name ?? ''" />

    <section class="entrance" :aria-label="tagline">
        <div>
            <h1 class="wall-text">{{ tagline }}</h1>
            <p v-if="intro?.description" class="wall-lede">{{ intro.description }}</p>
            <Link v-if="contact" :href="contact.path" class="plaque">
                {{ __('Start a project') }}
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6" /></svg>
            </Link>
            <div>
                <a v-if="projects?.entries?.length" class="entrance__next" href="#room-projects">{{
                    numberOf(projects.path) ? __('Room {n}, selected projects', { n: numberOf(projects.path) }) : __('Selected projects')
                }}</a>
            </div>
        </div>
        <div v-if="large" class="entrance__prints">
            <Link :href="large.path" class="entrance__large" :aria-label="large.title">
                <Print :media="large.image" size="large" sizes="(min-width: 900px) 34vw, 90vw" priority alt="" />
            </Link>
            <div v-if="small" class="entrance__small">
                <Link :href="small.path" tabindex="-1" aria-hidden="true">
                    <Print :media="small.image" size="medium" sizes="(min-width: 900px) 20vw, 60vw" alt="" />
                </Link>
                <Plate :title="small.title" :path="small.path" :fields="small.fields" :media="small.image" heading="h2" />
            </div>
        </div>
    </section>

    <section v-if="projects?.entries?.length" id="room-projects" class="wall room-projects">
        <h2 class="room-sign">
            <span v-if="numberOf(projects.path)" class="room-sign__no">{{ numberOf(projects.path) }}</span>
            <Link :href="projects.path">{{ roomName('projects', projects.plural, __) }}</Link>
        </h2>
        <ol class="catalogue">
            <li v-for="e in projects.entries" :key="e.id" class="catalogue__row">
                <span class="catalogue__no">{{ e.fields?.number ? String(e.fields.number).padStart(2, '0') : '' }}</span>
                <h3 class="catalogue__title">
                    <Link :href="e.path">{{ e.title }}</Link>
                </h3>
                <span class="catalogue__meta">{{ meta(e) }}</span>
                <span class="catalogue__year">{{ e.fields?.year ?? '' }}</span>
            </li>
        </ol>
    </section>

    <section v-if="intro" class="wall room-studio">
        <h2 class="room-sign">
            <span v-if="numberOf(intro.path)" class="room-sign__no">{{ numberOf(intro.path) }}</span>
            <Link :href="intro.path">{{ intro.title }}</Link>
        </h2>
        <ul v-if="people?.entries?.length" class="people">
            <li v-for="e in people.entries" :key="e.id" class="plate person">
                <h3 class="plate__title">
                    <Link :href="e.path">{{ e.title }}</Link>
                </h3>
                <p v-if="e.fields?.role">{{ e.fields.role }}</p>
            </li>
        </ul>
        <p v-else-if="intro.description" class="wall-lede">{{ intro.description }}</p>
    </section>

    <section v-if="talks?.entries?.length" class="wall room-talks">
        <h2 class="room-sign">
            <span v-if="numberOf(talks.path)" class="room-sign__no">{{ numberOf(talks.path) }}</span>
            <Link :href="talks.path">{{ roomName('talks', talks.plural, __) }}</Link>
        </h2>
        <ol class="programme">
            <li v-for="e in talks.entries" :key="e.id" class="programme__item" :class="{ programme__past: date(e)?.past }">
                <p v-if="date(e)" class="programme__date">
                    {{ date(e).day }}<small>{{ date(e).past ? __('Held in {year}', { year: date(e).year }) : date(e).time }}</small>
                </p>
                <div>
                    <h3 class="programme__title">
                        <Link :href="e.path">{{ e.title }}</Link>
                    </h3>
                    <p v-if="e.fields?.place || e.excerpt">{{ e.fields?.place ?? e.excerpt }}</p>
                </div>
            </li>
        </ol>
    </section>

    <section v-if="journal?.entries?.length" class="wall room-journal">
        <h2 class="room-sign">
            <span v-if="numberOf(journal.path)" class="room-sign__no">{{ numberOf(journal.path) }}</span>
            <Link :href="journal.path">{{ roomName('post', journal.plural, __) }}</Link>
        </h2>
        <!-- the newest essay laid open, then the index, as in the journal's own room -->
        <article class="lead-essay lead-essay--band">
            <Print v-if="journal.entries[0].image" :media="journal.entries[0].image" size="large" sizes="(min-width: 900px) 56vw, 92vw" alt="" />
            <div class="lead-essay__text">
                <time :datetime="journal.entries[0].published_at">{{ fmt(journal.entries[0].published_at) }}</time>
                <h3 class="lead-essay__title">
                    <Link :href="journal.entries[0].path">{{ journal.entries[0].title }}</Link>
                </h3>
                <p v-if="journal.entries[0].excerpt">{{ journal.entries[0].excerpt }}</p>
                <span class="lead-essay__read" aria-hidden="true">{{ __('Read') }} →</span>
            </div>
        </article>
        <ol v-if="journal.entries.length > 1" class="journal-index">
            <li v-for="e in journal.entries.slice(1)" :key="e.id" class="journal-index__item">
                <time :datetime="e.published_at">{{ fmt(e.published_at) }}</time>
                <div>
                    <h3 class="journal-index__title">
                        <Link :href="e.path">{{ e.title }}</Link>
                    </h3>
                    <p v-if="e.excerpt">{{ e.excerpt }}</p>
                </div>
                <Print v-if="e.image" :media="e.image" size="medium" sizes="(min-width: 760px) 14rem, 30vw" alt="" />
            </li>
        </ol>
    </section>
</template>
