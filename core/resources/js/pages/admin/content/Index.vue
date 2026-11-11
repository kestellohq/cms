<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Head, Link, usePage } from '@kestello/app';
import { confirm, notify, useApi, useExtensions, useResource, __ } from '@kestello/sdk';
import { KAlert, KBadge, KButton, KCard, KExtension, KField, KInput, KPageHeader, KSelect, KTable, KTabs, KWhen } from '@kestello/ui';
import TranslationLinks from '../../../components/languages/TranslationLinks.vue';

defineOptions({ layout: 'Admin' });

const page = usePage();
const api = useApi();
const typeName = computed(() => String(page.props.params?.type ?? ''));
const types = useResource('/content/types');
const type = computed(() => (types.data.value ?? []).find((t) => t.name === typeName.value) ?? null);

// A site in several languages lists one language at a time, the main one first; each row links its translations.
const languageInfo = useResource('/content/languages');
const languages = computed(() => languageInfo.data.value?.languages ?? []);
const multilingual = computed(() => languages.value.length > 1);
const languageOptions = computed(() => [
    ...languages.value.map((l) => ({ value: l.code, label: l.main ? __('{language} (main)', { language: l.name }) : l.name })),
    { value: 'any', label: __('All languages') },
]);

const filters = reactive({ status: 'any', search: '', sort: '-updated_at', page: 1, locale: '' });
const rows = ref([]);
const meta = ref(null);
const loading = ref(false);
const failed = ref(null);
let seq = 0;

async function load() {
    const mine = ++seq;
    loading.value = true;
    try {
        const body = await api.get(`/content/${typeName.value}/entries`, {
            status: filters.status,
            search: filters.search.trim() || undefined,
            sort: filters.sort,
            page: filters.page,
            per_page: 25,
            // one language at a time only while the site has several: entries in a removed language stay listed
            locale: !multilingual.value ? 'any' : filters.locale === '' ? 'main' : filters.locale, // '' would be left out of the query
        });
        if (mine !== seq) return;
        // trashing the last entry of the last page: go back a page instead of showing "Page 3 of 2"
        if (!body.data.length && filters.page > 1 && body.meta.last_page >= 1 && filters.page > body.meta.last_page) {
            filters.page = body.meta.last_page;
            return;
        }
        rows.value = body.data;
        meta.value = body.meta;
        failed.value = null;
    } catch (e) {
        if (mine === seq) failed.value = e;
    } finally {
        if (mine === seq) loading.value = false;
    }
}

let debounce = null;
watch(
    () => filters.search,
    () => {
        clearTimeout(debounce);
        debounce = setTimeout(() => {
            filters.page = 1;
            load();
        }, 250);
    },
);
watch(
    () => [filters.status, filters.sort, filters.locale],
    () => {
        filters.page = 1;
        load();
    },
);
watch(() => filters.page, load);
watch(multilingual, () => load());
watch(
    typeName,
    () => {
        Object.assign(filters, { status: 'any', search: '', sort: '-updated_at', page: 1, locale: '' });
        rows.value = [];
        load();
    },
    { immediate: true },
);

const STATUS = {
    draft: { label: __('Draft'), tone: 'neutral' },
    pending: { label: __('In review'), tone: 'accent' },
    scheduled: { label: __('Scheduled'), tone: 'info' },
    published: { label: __('Published'), tone: 'success' },
    private: { label: __('Private'), tone: 'warning' },
};
const tabs = computed(() => {
    const c = meta.value?.counts ?? {};
    const list = [
        { id: 'any', label: __('All'), count: c.any },
        { id: 'published', label: __('Published'), count: c.published },
        { id: 'pending', label: __('In review'), count: c.pending },
        { id: 'draft', label: __('Drafts'), count: c.draft },
        { id: 'scheduled', label: __('Scheduled'), count: c.scheduled },
        { id: 'private', label: __('Private'), count: c.private },
        { id: 'trash', label: __('Trash'), count: c.trash },
    ];
    // Keep the tab bar short: hide empty statuses unless one is selected.
    return list.filter((t) => t.id === 'any' || t.id === filters.status || (t.count ?? 0) > 0);
});

const trash = computed(() => filters.status === 'trash');
// Plugins add columns with $cms->admin->column('entries.list', …, when: ['type' => …]); they sit before "Author".
const extColumns = useExtensions('columns', 'entries.list', () => ({ type: typeName.value }));
const columns = computed(() => [
    { key: 'when', label: trash.value ? __('Trashed') : __('When'), class: 'k-when' },
    { key: 'title', label: __('Title') },
    ...extColumns.value.map((c) => ({ key: c.key, label: c.label, align: c.align })),
    ...(multilingual.value && !trash.value ? [{ key: 'translations', label: __('Translations') }] : []),
    { key: 'author', label: __('Author') },
    { key: 'status', label: __('Status') },
    { key: 'actions', label: '', align: 'end', width: trash.value ? '220px' : '56px' },
]);
const sortOptions = computed(() => [
    { value: '-updated_at', label: __('Last edited') },
    { value: '-published_at', label: __('Newest published') },
    { value: 'title', label: __('Title A–Z') },
    ...(type.value?.hierarchical ? [{ value: 'menu_order', label: __('Page order') }] : []),
]);

const singular = computed(() => type.value?.singular ?? '');
// "New" on a list filtered to one language starts the entry in it
const newHref = computed(
    () =>
        `/admin/content/${typeName.value}/new${multilingual.value && filters.locale && filters.locale !== 'any' ? `?locale=${encodeURIComponent(filters.locale)}` : ''}`,
);
const plural = computed(() => type.value?.plural ?? __('Content'));
const empty = computed(() => {
    if (filters.search.trim()) return __('No {plural} match “{q}”.', { plural: plural.value.toLowerCase(), q: filters.search.trim() });
    if (trash.value) return __('The trash is empty.');
    if (filters.status !== 'any') return __('Nothing here with this status.');
    return __('No {plural} yet.', { plural: plural.value.toLowerCase() });
});

// one request per row at a time: a double click must not trash, then fail "already in the trash"
const busy = ref(new Set());
async function withRow(row, work) {
    if (busy.value.has(row.id)) return;
    busy.value = new Set([...busy.value, row.id]);
    try {
        await work();
    } finally {
        const next = new Set(busy.value);
        next.delete(row.id);
        busy.value = next;
    }
}
const moveToTrash = (row) => withRow(row, () => trashNow(row));
async function trashNow(row) {
    try {
        await api.delete(`/content/${typeName.value}/entries/${row.id}`);
        notify.success(__('“{title}” moved to the trash.', { title: row.title || __('(untitled)') }));
        load();
    } catch (e) {
        notify.error(e);
    }
}
const restore = (row) => withRow(row, () => restoreNow(row));
async function restoreNow(row) {
    try {
        await api.post(`/content/${typeName.value}/entries/${row.id}/restore`);
        notify.success(__('“{title}” restored.', { title: row.title || __('(untitled)') }));
        load();
    } catch (e) {
        notify.error(e);
    }
}
const destroy = (row) => withRow(row, () => destroyNow(row));
async function destroyNow(row) {
    const ok = await confirm({
        title: __('Delete “{title}” for good?', { title: row.title || __('(untitled)') }),
        message: __('Its revisions are deleted too. This cannot be undone.'),
        confirmLabel: __('Delete permanently'),
        danger: true,
    });
    if (!ok) return;
    try {
        await api.delete(`/content/${typeName.value}/entries/${row.id}`, { query: { permanent: true } });
        notify.success(__('Deleted.'));
        load();
    } catch (e) {
        notify.error(e);
    }
}
const dateOf = (row) => (trash.value ? row.trashed_at : row.status === 'published' || row.status === 'scheduled' ? row.published_at : row.updated_at);
// The timetable: on date orders, what is still to go out sits above the
// now-line, soonest just above it; everything already out runs below.
const byDate = computed(() => filters.sort === '-updated_at' || filters.sort === '-published_at');
const future = (row) => row.status === 'scheduled' && !trash.value;
const board = computed(() => {
    if (!byDate.value) return rows.value;
    const ahead = rows.value.filter(future).sort((a, b) => Date.parse(b.published_at) - Date.parse(a.published_at));
    return [...ahead, ...rows.value.filter((r) => !future(r))];
});
const nowLabel = computed(() => __('Now {time}', { time: new Intl.DateTimeFormat(undefined, { hour: '2-digit', minute: '2-digit' }).format(new Date()) }));
const marker = computed(() => {
    const ahead = board.value.filter(future).length;
    return byDate.value && ahead > 0 && ahead < board.value.length ? { before: ahead, label: nowLabel.value } : null;
});
const rowClass = (row) => ({ 'is-scheduled': future(row), 'is-faded': trash.value });
const dateHint = (row) => (trash.value ? '' : row.status === 'scheduled' ? __('Goes live') : row.status === 'published' ? __('Published') : __('Edited'));
</script>

<template>
    <Head :title="plural" />
    <KPageHeader :title="plural" :description="type?.description ?? ''">
        <template v-if="type?.can.create" #actions>
            <KButton variant="primary" icon="plus" :href="newHref">{{ __('New {item}', { item: singular.toLowerCase() }) }}</KButton>
        </template>
    </KPageHeader>

    <KAlert v-if="types.data.value && !type" tone="danger">{{ __('This content type does not exist, or you cannot edit it.') }}</KAlert>
    <KAlert v-else-if="failed" tone="danger">{{ failed.message }}</KAlert>

    <template v-if="type">
        <div class="k-toolbar" role="search">
            <KField v-slot="{ id }" :label="__('Search titles')" class="k-toolbar__grow">
                <KInput :id="id" v-model="filters.search" type="search" autocomplete="off" />
            </KField>
            <KField v-if="multilingual" v-slot="{ id }" :label="__('Language')">
                <KSelect :id="id" v-model="filters.locale" :options="languageOptions" />
            </KField>
            <KField v-slot="{ id }" :label="__('Order')">
                <KSelect :id="id" v-model="filters.sort" :options="sortOptions" />
            </KField>
        </div>

        <KTabs v-model="filters.status" :tabs="tabs" :label="__('Status')" />
        <KCard flush class="k-content-list">
            <KTable :columns="columns" :rows="board" :row-class="rowClass" :marker="marker" :loading="loading && !rows.length" :empty="empty" :caption="plural">
                <template #cell-title="{ row }">
                    <div class="k-entry-title">
                        <Link v-if="!trash && row.can.edit" :href="`/admin/content/${typeName}/${row.id}`" class="k-link">{{
                            row.title || __('(untitled)')
                        }}</Link>
                        <span v-else>{{ row.title || __('(untitled)') }}</span>
                        <span class="k-entry-title__path">{{
                            [dateHint(row), (row.parent ? `${row.parent.title} / ` : '') + (row.path ?? row.slug ?? '')].filter(Boolean).join(' · ')
                        }}</span>
                    </div>
                </template>
                <template v-for="col in extColumns" :key="col.id" #[`cell-${col.key}`]="{ row }">
                    <KExtension v-if="col.component" :name="col.component" :owner="col.owner" inline :row="row" :value="row[col.key]" />
                    <span v-else>{{ row[col.key] ?? '—' }}</span>
                </template>
                <template #cell-translations="{ row }">
                    <TranslationLinks
                        :type="typeName"
                        :entry="row"
                        :languages="languages"
                        :main="languageInfo.data.value?.main ?? ''"
                        :can-translate="row.can.edit"
                        compact
                    />
                </template>
                <template #cell-author="{ row }"
                    ><span :class="{ 'k-muted': !row.author }">{{ row.author?.username ?? __('Nobody') }}</span
                    ><span v-if="row.assignee" class="k-muted k-small k-list-assignee">{{
                        __('Assigned to {name}', { name: row.assignee.username })
                    }}</span></template
                >
                <template #cell-status="{ row }"
                    ><KBadge :tone="row.status === 'scheduled' ? 'scheduled' : STATUS[row.status]?.tone">{{
                        STATUS[row.status]?.label ?? row.status
                    }}</KBadge></template
                >
                <template #cell-when="{ row }"><KWhen :value="dateOf(row)" /></template>
                <template #cell-actions="{ row }">
                    <div v-if="trash" class="k-row-actions">
                        <KButton size="sm" icon="arrow-counter-clockwise" :loading="busy.has(row.id)" :disabled="!row.can.delete" @click="restore(row)">{{
                            __('Restore')
                        }}</KButton>
                        <KButton size="sm" variant="danger" :loading="busy.has(row.id)" :disabled="!row.can.delete" @click="destroy(row)">{{
                            __('Delete')
                        }}</KButton>
                    </div>
                    <KButton
                        v-else-if="row.can.delete"
                        size="sm"
                        variant="ghost"
                        icon="trash"
                        :loading="busy.has(row.id)"
                        :aria-label="__('Move “{title}” to the trash', { title: row.title || __('(untitled)') })"
                        @click="moveToTrash(row)"
                    />
                </template>
            </KTable>
            <template v-if="meta && meta.last_page > 1" #footer>
                <div class="k-pager">
                    <span class="k-muted">{{ __('Page {page} of {last} · {total} total', { page: meta.page, last: meta.last_page, total: meta.total }) }}</span>
                    <KButton size="sm" icon="caret-left" :disabled="meta.page <= 1 || loading" @click="filters.page--">{{ __('Previous') }}</KButton>
                    <KButton size="sm" :disabled="meta.page >= meta.last_page || loading" @click="filters.page++">{{ __('Next') }}</KButton>
                </div>
            </template>
        </KCard>
    </template>
</template>

<style scoped>
.k-list-assignee {
    display: block;
}
</style>
