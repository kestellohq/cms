<script>
// Exhibit's lists (they replace core's "@core/content/Archive"): projects as
// a catalogue with the newest face out, talks as a programme, everything
// else as a journal: the newest essay laid open, the rest as an index.
export default { layout: 'Site' };
</script>

<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@kestello/app';
import { useI18n } from '@kestello/sdk';
import Plate from '../../../components/Plate.vue';
import Print from '../../../components/Print.vue';
import { plateRows, programme, roomLede, roomName, talkDate } from '../../../components/rooms.js';

const props = defineProps({
    type: { type: Object, required: true },
    entries: { type: Array, default: () => [] },
    pagination: { type: Object, required: true },
    term: { type: Object, default: null },
    date: { type: Object, default: null },
    author: { type: Object, default: null },
});
const { __ } = useI18n('exhibit');
const page = usePage();
const locale = computed(() => page.props.i18n?.locale ?? 'en');

const kind = computed(() => ({ projects: 'projects', talks: 'talks', team: 'people' })[props.type.name] ?? 'journal');
const heading = computed(() => {
    if (props.date) return __('{period} in {room}', { period: props.date.label, room: roomName(props.type.name, props.type.plural, __) });
    if (props.author) return __('Written by {name}', { name: props.author.name });
    return roomName(props.type.name, props.type.plural, __);
});
const first = computed(() => (props.pagination.page === 1 && !props.date && !props.term && !props.author ? (props.entries[0] ?? null) : null));
const face = computed(() => (kind.value === 'projects' && props.pagination.page === 1 ? (props.entries[0] ?? null) : null));
const spine = computed(() => (face.value ? props.entries.slice(1) : props.entries));
const lead = computed(() => (kind.value === 'journal' ? first.value : null));
const index = computed(() => (lead.value ? props.entries.slice(1) : props.entries));
// a room the theme knows says what it holds in the page's language; a type's own description is the admin's, in one language
const lede = computed(() => (props.term ? props.type.description : roomLede(props.type.name, props.type.description, __)));
const programmed = computed(() => programme(props.entries));
const meta = (e) =>
    plateRows(e.fields, __)
        .filter((r) => r.key === 'place' || r.key === 'use')
        .map((r) => r.value)
        .join(', ');
const when = (e) => talkDate(e.fields?.starts ?? e.published_at, locale.value);
const fmt = (iso) => (iso ? new Intl.DateTimeFormat(locale.value, { dateStyle: 'long' }).format(new Date(iso)) : '');
const pageUrl = (n) => (n <= 1 ? props.type.path : `${props.type.path}?page=${n}`);
</script>

<template>
    <Head :title="heading" />
    <section class="wall">
        <h1 class="wall-text">{{ heading }}</h1>
        <p v-if="lede" class="wall-lede">{{ lede }}</p>
        <p v-else-if="date" class="wall-lede">
            <Link :href="date.archive">{{ __('Everything in {room}', { room: roomName(type.name, type.plural, __) }) }}</Link>
        </p>
    </section>

    <section class="wall" :aria-label="heading">
        <p v-if="!entries.length" class="state">{{ term ? __('Nothing published here uses it yet.') : __('Nothing has been hung in this room yet.') }}</p>

        <!-- projects: the newest face out, the rest spine out -->
        <ol v-else-if="kind === 'projects'" class="catalogue">
            <li v-if="face" class="catalogue__row catalogue__row--face">
                <span class="catalogue__no">{{ face.fields?.number ? String(face.fields.number).padStart(2, '0') : '' }}</span>
                <div class="catalogue__face-text">
                    <h2 class="catalogue__title">
                        <Link :href="face.path">{{ face.title }}</Link>
                    </h2>
                    <Plate :title="face.title" :fields="face.fields" :media="face.image" heading="p" />
                </div>
                <Print :media="face.image" size="large" sizes="(min-width: 760px) 44vw, 92vw" priority alt="" />
            </li>
            <li v-for="e in spine" :key="e.id" class="catalogue__row">
                <span class="catalogue__no">{{ e.fields?.number ? String(e.fields.number).padStart(2, '0') : '' }}</span>
                <h2 class="catalogue__title">
                    <Link :href="e.path">{{ e.title }}</Link>
                </h2>
                <span class="catalogue__meta">{{ meta(e) }}</span>
                <span class="catalogue__year">{{ e.fields?.year ?? '' }}</span>
            </li>
        </ol>

        <!-- talks: the programme -->
        <ol v-else-if="kind === 'talks'" class="programme">
            <li v-for="e in programmed" :key="e.id" class="programme__item" :class="{ programme__past: when(e)?.past }">
                <p v-if="when(e)" class="programme__date">
                    {{ when(e).day }}<small>{{ when(e).past ? __('Held in {year}', { year: when(e).year }) : when(e).time }}</small>
                </p>
                <div>
                    <h2 class="programme__title">
                        <Link :href="e.path">{{ e.title }}</Link>
                    </h2>
                    <p v-if="e.fields?.place || e.excerpt">{{ e.fields?.place ?? e.excerpt }}</p>
                </div>
            </li>
        </ol>

        <!-- people: a wall of name plates, no portraits -->
        <ul v-else-if="kind === 'people'" class="people">
            <li v-for="e in entries" :key="e.id" class="plate person">
                <h2 class="plate__title">
                    <Link :href="e.path">{{ e.title }}</Link>
                </h2>
                <p v-if="e.fields?.role">{{ e.fields.role }}</p>
            </li>
        </ul>

        <!-- the journal: the newest essay laid open, then the index -->
        <template v-else>
            <article v-if="lead" class="lead-essay">
                <Print v-if="lead.image" :media="lead.image" size="large" sizes="(min-width: 900px) 56vw, 92vw" priority alt="" />
                <div class="lead-essay__text">
                    <time v-if="lead.published_at" :datetime="lead.published_at">{{ fmt(lead.published_at) }}</time>
                    <h2 class="lead-essay__title">
                        <Link :href="lead.path">{{ lead.title }}</Link>
                    </h2>
                    <p v-if="lead.excerpt">{{ lead.excerpt }}</p>
                    <span class="lead-essay__read" aria-hidden="true">{{ __('Read') }} →</span>
                </div>
            </article>
            <ol v-if="index.length" class="journal-index">
                <li v-for="e in index" :key="e.id" class="journal-index__item">
                    <time v-if="e.published_at" :datetime="e.published_at">{{ fmt(e.published_at) }}</time>
                    <div>
                        <h2 class="journal-index__title">
                            <Link :href="e.path">{{ e.title }}</Link>
                        </h2>
                        <p v-if="e.excerpt">{{ e.excerpt }}</p>
                    </div>
                    <Print v-if="e.image" :media="e.image" size="medium" sizes="(min-width: 760px) 14rem, 30vw" alt="" />
                </li>
            </ol>
        </template>

        <nav v-if="pagination.last > 1" class="pager" :aria-label="__('Pages')">
            <Link v-if="pagination.page > 1" :href="pageUrl(pagination.page - 1)" rel="prev">{{ __('Newer') }}</Link>
            <span>{{ __('Page {n} of {last}', { n: pagination.page, last: pagination.last }) }}</span>
            <Link v-if="pagination.page < pagination.last" :href="pageUrl(pagination.page + 1)" rel="next">{{ __('Older') }}</Link>
        </nav>
    </section>
</template>
