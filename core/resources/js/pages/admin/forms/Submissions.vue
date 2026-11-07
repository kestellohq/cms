<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Head, usePage } from '@kestello/app';
import { ApiError, confirm, formatBytes, notify, useApi, __ } from '@kestello/sdk';
import { KAlert, KButton, KEmpty, KField, KIcon, KInput, KPageHeader, KTabs, KTime } from '@kestello/ui';

defineOptions({ layout: 'Admin' });

/** What people sent through one form: an inbox with a reading pane. */
const api = useApi();
const page = usePage();
const formId = computed(() => String(page.props.params?.id ?? ''));

const state = reactive({
    rows: [],
    total: null,
    page: 1,
    lastPage: 1,
    loading: false,
    search: '',
    status: 'inbox',
    counts: { new: 0, spam: 0 },
    form: null,
    error: null,
});
const open = ref(null); // the submission in the reading pane
let seq = 0;

async function load(p = 1) {
    const mine = ++seq;
    state.loading = true;
    try {
        const body = await api.get(`/forms/${formId.value}/submissions`, {
            page: p,
            per_page: 25,
            status: state.status,
            search: state.search.trim() || undefined,
        });
        if (mine !== seq) return;
        Object.assign(state, {
            rows: body.data,
            total: body.meta.total,
            page: body.meta.page,
            lastPage: body.meta.last_page,
            counts: body.meta.counts,
            form: body.meta.form,
            error: null,
        });
    } catch (e) {
        state.error = e;
    } finally {
        if (mine === seq) state.loading = false;
    }
}
let debounce = null;
watch(
    () => state.search,
    () => {
        clearTimeout(debounce);
        debounce = setTimeout(() => load(), 250);
    },
);
watch(
    () => state.status,
    () => {
        open.value = null;
        load();
    },
);
load().then(() => {
    const wanted = Number(new URLSearchParams(window.location.search).get('open')); // the link in notification emails
    if (wanted) show({ id: wanted });
});

const fields = computed(() => state.form?.definition.fields ?? []);
const tabs = computed(() => [
    { id: 'inbox', label: __('Inbox'), count: state.status === 'inbox' ? state.total : null },
    { id: 'new', label: __('New'), count: state.counts.new },
    { id: 'spam', label: __('Spam'), count: state.counts.spam },
]);

async function show(row) {
    try {
        const { data } = await api.get(`/form-submissions/${row.id}`);
        open.value = data;
        const listed = state.rows.find((r) => r.id === data.id);
        if (listed && listed.status === 'new') {
            listed.status = 'read';
            state.counts.new = Math.max(0, state.counts.new - 1);
        }
    } catch (e) {
        notify.error(e);
    }
}

// the first two answers say who it is from and what it is about
const preview = (row) =>
    fields.value
        .filter((f) => f.type !== 'file' && f.type !== 'boolean')
        .slice(0, 2)
        .map((f) => display(f, row.data[f.name]))
        .filter(Boolean);
function display(f, v) {
    if (v === null || v === undefined || v === '') return '';
    if (f.type === 'boolean') return v ? __('Yes') : __('No');
    if (Array.isArray(v)) return v.map((x) => f.options?.choices?.[x] ?? x).join(', ');
    if (f.type === 'select') return f.options?.choices?.[v] ?? String(v);
    return String(v);
}

async function mark(status) {
    try {
        await api.patch(`/form-submissions/${open.value.id}`, { status });
        notify.success(status === 'spam' ? __('Moved to spam.') : status === 'new' ? __('Marked as new.') : __('Moved to the inbox.'));
        open.value = null;
        await load(state.page);
    } catch (e) {
        notify.error(e);
    }
}
async function remove() {
    if (
        !(await confirm({
            title: __('Delete this submission?'),
            message: __('Its answers and files are deleted for good.'),
            confirmLabel: __('Delete'),
            danger: true,
        }))
    )
        return;
    try {
        await api.delete(`/form-submissions/${open.value.id}`);
        notify.success(__('Submission deleted.'));
        open.value = null;
        await load(state.rows.length === 1 && state.page > 1 ? state.page - 1 : state.page);
    } catch (e) {
        notify.error(e);
    }
}
async function download(url, filename) {
    try {
        const response = await fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        if (!response.ok) throw new ApiError(response.status, { title: response.statusText });
        const href = URL.createObjectURL(await response.blob());
        Object.assign(document.createElement('a'), { href, download: filename }).click();
        setTimeout(() => URL.revokeObjectURL(href), 1000);
    } catch (e) {
        notify.error(e);
    }
}
const exportCsv = () => download(`/api/v1/forms/${formId.value}/submissions/export`, `form-${formId.value}-${new Date().toISOString().slice(0, 10)}.csv`);
</script>

<template>
    <Head :title="state.form ? __('{name}: submissions', { name: state.form.title }) : __('Submissions')" />
    <KPageHeader
        :title="state.form?.title ?? __('Submissions')"
        :description="
            state.form
                ? state.form.definition.store
                    ? state.form.definition.retention_days
                        ? __('{n, plural, one {Submissions are deleted after # day.} other {Submissions are deleted after # days.}}', {
                              n: state.form.definition.retention_days,
                          })
                        : __('Submissions are kept until you delete them.')
                    : __('This form doesn\'t keep submissions; they are only emailed.')
                : ''
        "
    >
        <template #actions>
            <KButton icon="pencil" :href="`/admin/forms/${formId}`">{{ __('Edit the form') }}</KButton>
            <KButton icon="download" :disabled="!state.total" @click="exportCsv">{{ __('Export CSV') }}</KButton>
        </template>
    </KPageHeader>

    <KAlert v-if="state.error" tone="danger">{{ state.error.message }}</KAlert>
    <KTabs v-model="state.status" :tabs="tabs" :label="__('Submissions')" />

    <div class="k-inbox">
        <section class="k-inbox__list" :aria-label="__('Submissions')">
            <div class="k-inbox__search" role="search">
                <KField v-slot="{ id }" :label="__('Search the answers')">
                    <KInput :id="id" v-model="state.search" type="search" autocomplete="off" />
                </KField>
            </div>
            <KEmpty
                v-if="!state.loading && !state.rows.length"
                icon="tray"
                :title="state.search ? __('Nothing matches.') : state.status === 'spam' ? __('No spam.') : __('Nothing yet.')"
            >
                <template v-if="!state.search && state.status !== 'spam'">{{ __('What people send through this form shows up here.') }}</template>
            </KEmpty>
            <ul v-else class="k-inbox__rows">
                <li v-for="row in state.rows" :key="row.id">
                    <button
                        type="button"
                        class="k-inbox__row"
                        :class="{ 'is-open': open?.id === row.id, 'is-new': row.status === 'new' }"
                        :aria-current="open?.id === row.id ? 'true' : undefined"
                        @click="show(row)"
                    >
                        <span class="k-inbox__who">
                            <span v-if="row.status === 'new'" class="k-inbox__dot" :aria-label="__('New')"></span>
                            {{ preview(row)[0] || __('Submission {id}', { id: row.id }) }}
                        </span>
                        <KTime class="k-inbox__when" :value="row.created_at" />
                        <span class="k-inbox__what">{{ preview(row)[1] ?? '' }}</span>
                    </button>
                </li>
            </ul>
            <div v-if="state.lastPage > 1" class="k-inbox__pager">
                <KButton size="sm" :disabled="state.page <= 1" @click="load(state.page - 1)">{{ __('Newer') }}</KButton>
                <span class="k-muted k-small">{{ __('Page {page} of {last}', { page: state.page, last: state.lastPage }) }}</span>
                <KButton size="sm" :disabled="state.page >= state.lastPage" @click="load(state.page + 1)">{{ __('Older') }}</KButton>
            </div>
        </section>

        <article class="k-inbox__pane" :aria-label="__('Submission')">
            <div v-if="!open" class="k-inbox__placeholder k-muted">{{ state.rows.length ? __('Choose a submission to read it.') : '' }}</div>
            <template v-else>
                <header class="k-inbox__head">
                    <div>
                        <h2 class="k-inbox__title">{{ preview(open)[0] || __('Submission {id}', { id: open.id }) }}</h2>
                        <p class="k-muted k-small">
                            <KTime :value="open.created_at" /><template v-if="open.page"> · {{ __('from {page}', { page: open.page }) }}</template>
                        </p>
                    </div>
                    <div class="k-row-actions">
                        <KButton v-if="open.status !== 'spam'" size="sm" icon="envelope" @click="mark('new')">{{ __('Mark as new') }}</KButton>
                        <KButton v-if="open.status !== 'spam'" size="sm" icon="prohibit" @click="mark('spam')">{{ __('Spam') }}</KButton>
                        <KButton v-else size="sm" icon="tray" @click="mark('read')">{{ __('Not spam') }}</KButton>
                        <KButton size="sm" variant="ghost" icon="trash" @click="remove">{{ __('Delete') }}</KButton>
                    </div>
                </header>
                <dl class="k-inbox__answers">
                    <template v-for="f in fields" :key="f.name">
                        <template v-if="open.data[f.name] !== undefined && open.data[f.name] !== null">
                            <dt>{{ f.label }}</dt>
                            <dd v-if="f.type === 'file'">
                                <ul class="k-inbox__files">
                                    <li v-for="(file, i) in open.data[f.name]" :key="file.key">
                                        <button
                                            type="button"
                                            class="k-link-btn"
                                            @click="download(`/api/v1/form-submissions/${open.id}/files/${f.name}/${i}`, file.name)"
                                        >
                                            <KIcon name="paperclip" /> {{ file.name }}
                                        </button>
                                        <span class="k-muted k-small">{{ formatBytes(file.size) }}</span>
                                    </li>
                                </ul>
                            </dd>
                            <dd v-else-if="f.type === 'email'">
                                <a class="k-link" :href="`mailto:${open.data[f.name]}`">{{ open.data[f.name] }}</a>
                            </dd>
                            <dd v-else>
                                <span class="k-inbox__text">{{ display(f, open.data[f.name]) }}</span>
                            </dd>
                        </template>
                    </template>
                </dl>
                <details class="k-inbox__meta">
                    <summary>{{ __('Sent from') }}</summary>
                    <p class="k-small k-muted">{{ open.ip || __('Unknown address') }} · {{ open.user_agent || __('Unknown browser') }}</p>
                </details>
            </template>
        </article>
    </div>
</template>

<style scoped>
.k-inbox {
    display: grid;
    grid-template-columns: minmax(16rem, 22rem) minmax(0, 1fr);
    border: 1px solid var(--k-rule);
    background: var(--k-sheet);
    margin-top: 16px;
    /* a workspace: as tall as the screen, the list and the open submission scroll on their own */
    height: max(28rem, calc(100dvh - 205px));
}
.k-inbox__list {
    border-right: 1px solid var(--k-rule);
    min-width: 0;
    min-height: 0;
    display: flex;
    flex-direction: column;
}
.k-inbox__search {
    padding: 12px 14px 4px;
    border-bottom: 1px solid var(--k-rule-soft);
}
.k-inbox__rows {
    list-style: none;
    margin: 0;
    padding: 0;
    overflow: auto;
    min-height: 0;
}
.k-inbox__row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 2px 10px;
    width: 100%;
    padding: 11px 14px;
    border: 0;
    border-bottom: 1px solid var(--k-rule-soft);
    background: none;
    color: inherit;
    font: inherit;
    text-align: left;
    cursor: pointer;
}
.k-inbox__row:hover {
    background: var(--k-sheet-2);
}
.k-inbox__row.is-open {
    background: var(--k-mark-soft);
}
.k-inbox__row:focus-visible {
    outline: 2px solid var(--k-focus);
    outline-offset: -2px;
}
.k-inbox__who {
    display: flex;
    align-items: center;
    gap: 7px;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.k-inbox__row.is-new .k-inbox__who {
    font-weight: 650;
}
.k-inbox__dot {
    flex-shrink: 0;
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: var(--k-mark);
}
.k-inbox__when {
    font-size: 12px;
    color: var(--k-ink-3);
    white-space: nowrap;
}
.k-inbox__what {
    grid-column: 1 / -1;
    font-size: 13px;
    color: var(--k-ink-2);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.k-inbox__pager {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    padding: 10px 14px;
    margin-top: auto;
    border-top: 1px solid var(--k-rule-soft);
}
.k-inbox__pane {
    min-width: 0;
    min-height: 0;
    overflow: auto;
    padding: 22px 28px 28px;
}
.k-inbox__placeholder {
    display: grid;
    place-items: center;
    height: 100%;
    font-size: 14px;
}
.k-inbox__head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px 20px;
    flex-wrap: wrap;
    padding-bottom: 14px;
    border-bottom: 1px solid var(--k-rule);
}
.k-inbox__title {
    margin: 0 0 2px;
    font-size: 18px;
    font-weight: 650;
}
.k-inbox__head p {
    margin: 0;
}
.k-inbox__answers {
    display: grid;
    grid-template-columns: minmax(8rem, 12rem) minmax(0, 1fr);
    margin: 0;
}
.k-inbox__answers dt,
.k-inbox__answers dd {
    margin: 0;
    padding: 11px 0;
    border-bottom: 1px solid var(--k-rule-soft);
}
.k-inbox__answers dt {
    font-size: 13px;
    font-weight: 600;
    color: var(--k-ink-2);
    padding-right: 16px;
}
.k-inbox__text {
    display: block;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    max-width: 70ch;
}
.k-inbox__files {
    list-style: none;
    margin: 0;
    padding: 0;
    display: grid;
    gap: 4px;
}
.k-inbox__files li {
    display: flex;
    align-items: baseline;
    gap: 10px;
}
.k-inbox__meta {
    margin-top: 16px;
    font-size: 13px;
}
.k-inbox__meta summary {
    cursor: pointer;
    color: var(--k-ink-2);
}
@media (max-width: 900px) {
    .k-inbox {
        grid-template-columns: 1fr;
        height: auto;
    }
    .k-inbox__list {
        border-right: 0;
        border-bottom: 1px solid var(--k-rule);
        max-height: 24rem;
    }
    .k-inbox__pane {
        padding: 18px 16px;
    }
    .k-inbox__answers {
        grid-template-columns: 1fr;
    }
    .k-inbox__answers dt {
        border-bottom: 0;
        padding-bottom: 0;
    }
}
</style>
