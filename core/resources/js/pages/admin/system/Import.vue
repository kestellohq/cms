<script setup>
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { ApiError, confirm, formatBytes, notify, useApi, __ } from '@kestello/sdk';
import { KAlert, KBadge, KButton, KCard, KField, KFileInput, KPageHeader, KSelect, KSwitch, KTable, KTime } from '@kestello/ui';

defineOptions({ layout: 'Admin' });

/**
 * System → Import: bring a WordPress site here from its export file.
 * Upload → review what will come over (authors, options) → it runs in the
 * background (or, with no queue worker, from this screen while it is open)
 * → a report of everything that was skipped or could not come over.
 */
const api = useApi();
const list = ref(null);
const current = ref(null); // the import on screen
const loadError = ref(null);
const busy = ref('');

const KINDS = {
    entry: __('Posts and pages'),
    attachment: __('Files'),
    file: __('Files linked from posts'),
    term: __('Categories and tags'),
    menu: __('Menus'),
    redirect: __('Redirects from old addresses'),
};
const PHASES = {
    terms: __('Categories and tags'),
    media: __('Files'),
    entries: __('Posts and pages'),
    links: __('Links between posts'),
    menus: __('Menus'),
    redirects: __('Redirects'),
    finish: __('Finishing'),
};
const STATUS = {
    ready: { label: __('Not started'), tone: 'neutral' },
    running: { label: __('Importing'), tone: 'info' },
    done: { label: __('Done'), tone: 'success' },
    cancelled: { label: __('Stopped'), tone: 'warning' },
};

async function load() {
    try {
        list.value = (await api.get('/imports')).data;
        const open = list.value.imports.find((i) => i.status === 'ready' || i.status === 'running');
        if (open) await show(open.id);
    } catch (e) {
        loadError.value = e;
    }
}
load();

async function show(id) {
    current.value = (await api.get(`/imports/${id}`)).data;
}

function rows(counts) {
    return Object.entries(counts ?? {})
        .filter(([kind]) => KINDS[kind])
        .map(([kind, c]) => ({ kind, label: KINDS[kind], ...c }));
}

// ── upload ───────────────────────────────────────────────────────────
const file = ref(null);
const uploadError = ref('');

async function upload() {
    if (!file.value) return;
    busy.value = 'upload';
    uploadError.value = '';
    const form = new FormData();
    form.append('file', file.value);
    try {
        current.value = (await api.upload('/imports', form)).data;
        prepare();
    } catch (e) {
        if (e instanceof ApiError && (e.status === 422 || e.status === 409)) uploadError.value = e.fieldErrors().file ?? e.message;
        else notify.error(e);
    } finally {
        busy.value = '';
    }
}

// ── review ───────────────────────────────────────────────────────────
const options = reactive({ authors: {}, default_author: '', media: true, redirects: true });

function prepare() {
    options.authors = Object.fromEntries((current.value.summary.authors ?? []).map((a) => [a.login, a.match ?? '']));
    options.default_author = '';
    options.media = true;
    options.redirects = true;
}
watch(
    () => current.value?.id,
    () => current.value?.status === 'ready' && prepare(),
);

const people = computed(() => [
    { value: '', label: __('Me, the person importing') },
    ...(current.value?.people ?? []).map((p) => ({ value: p.id, label: p.username })),
]);
const toImport = computed(() => rows(current.value?.counts).filter((r) => r.total - r.skipped > 0 || r.skipped > 0));

async function start() {
    busy.value = 'start';
    const authors = Object.fromEntries(Object.entries(options.authors).filter(([, id]) => id !== ''));
    try {
        current.value = (
            await api.post(`/imports/${current.value.id}/start`, {
                authors,
                default_author: options.default_author === '' ? null : options.default_author,
                media: options.media,
                redirects: options.redirects,
            })
        ).data;
    } catch (e) {
        notify.error(e);
    } finally {
        busy.value = '';
    }
}

async function discard(i = current.value) {
    const started = i.status !== 'ready';
    if (
        started &&
        !(await confirm({
            title: __('Forget this import?'),
            message: __('Its report goes. What it imported stays, and importing the same site again still updates instead of copying.'),
            confirmLabel: __('Forget'),
        }))
    )
        return;
    busy.value = `discard:${i.id}`;
    try {
        await api.delete(`/imports/${i.id}`);
        if (current.value?.id === i.id) current.value = null;
        list.value = (await api.get('/imports')).data;
    } catch (e) {
        notify.error(e);
    } finally {
        busy.value = '';
    }
}

// ── running ──────────────────────────────────────────────────────────
const running = computed(() => current.value?.status === 'running');
const progress = computed(() => {
    const p = current.value?.progress;
    return p && p.total ? Math.round((p.done / p.total) * 100) : 0;
});

// This screen works a slice at a time while it is open. With a queue worker
// it shares the work: when the worker has the current slice the answer says
// busy, and the screen asks again a little later.
let alive = true;
let timer = null;
let failedSteps = 0;
async function tick() {
    if (!alive || !running.value) return;
    const id = current.value.id;
    try {
        current.value = (await api.post(`/imports/${id}/step`)).data;
        failedSteps = 0;
    } catch (e) {
        // try again, more slowly: one failed request must not stop the import for good
        failedSteps++;
        if (failedSteps === 1) notify.error(e);
        if (alive && running.value) timer = setTimeout(tick, Math.min(30000, 1000 * 2 ** failedSteps));
        return;
    }
    if (alive && running.value) timer = setTimeout(tick, current.value.busy ? 2000 : 50);
    else if (current.value.status === 'done') list.value = (await api.get('/imports')).data;
}
watch(running, (now) => {
    if (now) {
        clearTimeout(timer);
        timer = setTimeout(tick, 50);
    }
});
onBeforeUnmount(() => {
    alive = false;
    clearTimeout(timer);
});

async function act(action) {
    busy.value = action;
    try {
        current.value = (await api.post(`/imports/${current.value.id}/${action}`)).data;
    } catch (e) {
        notify.error(e);
    } finally {
        busy.value = '';
    }
}

// ── report ───────────────────────────────────────────────────────────
const finished = computed(() => current.value && ['done', 'cancelled'].includes(current.value.status));
const total = (key) => rows(current.value?.counts).reduce((n, r) => n + (r[key] ?? 0), 0);
const failed = computed(() => (current.value?.problems ?? []).filter((p) => p.result === 'failed'));
const kept = computed(() => (current.value?.problems ?? []).filter((p) => p.result === 'kept'));

// (an ICU plural's closing braces would end a {{ }} in the template)
const postsText = (n) => __('{n, plural, one {# post} other {# posts}}', { n });
const commentsText = (n) => __('{n, plural, one {# comment is} other {# comments are}} not imported: visitor comments are not part of Kestello.', { n });
const failedText = (n) => __('{n, plural, one {# item} other {# items}} could not be imported. The reason is next to each.', { n });
</script>

<template>
    <KPageHeader
        :title="__('Import')"
        :description="
            __(
                'Bring a WordPress site here: posts and pages, categories and tags, files, menus, and redirects from the old addresses. Importing the same site again updates what came over before instead of copying it.',
            )
        "
    >
        <template v-if="current && !running" #actions>
            <KButton v-if="finished" icon="arrow-left" @click="current = null">{{ __('All imports') }}</KButton>
        </template>
    </KPageHeader>
    <KAlert v-if="loadError" tone="danger">{{ loadError.message }}</KAlert>

    <!-- upload, and earlier imports -->
    <template v-if="list && !current">
        <KCard :title="__('Import from WordPress')">
            <ol class="k-import__how">
                <li>{{ __('In WordPress, open Tools → Export, choose All content and download the export file.') }}</li>
                <li>{{ __('Upload it here. Nothing is imported until you have seen what will come over.') }}</li>
            </ol>
            <form class="k-import__upload" @submit.prevent="upload">
                <KField
                    v-slot="{ id, describedBy }"
                    :label="__('WordPress export file')"
                    :error="uploadError"
                    :help="list.max_upload ? __('Up to {size}.', { size: formatBytes(list.max_upload) }) : ''"
                >
                    <KFileInput
                        :id="id"
                        v-model="file"
                        accept=".xml,text/xml,application/xml"
                        :max-bytes="list.max_upload || 0"
                        :described-by="describedBy"
                        required
                        @update:model-value="uploadError = ''"
                    />
                </KField>
                <div>
                    <KButton type="submit" variant="primary" icon="upload" :loading="busy === 'upload'" :disabled="!file">{{ __('Read the file') }}</KButton>
                </div>
            </form>
            <p class="k-muted k-small k-import__cli">
                {{ __('A larger export, or a site whose old server is gone: copy the file (and wp-content/uploads) to this server and run') }}
                <code>bin/cms import:wordpress export.xml --uploads=path/to/uploads</code>
            </p>
        </KCard>

        <KCard v-if="list.imports.length" :title="__('Earlier imports')" flush>
            <KTable
                :columns="[
                    { key: 'site', label: __('Site') },
                    { key: 'status', label: __('Status') },
                    { key: 'when', label: __('Started') },
                    { key: 'actions', label: '', align: 'end', width: '1%' },
                ]"
                :rows="list.imports"
            >
                <template #cell-site="{ row }">
                    {{ row.site.title || row.site.url }}
                    <div class="k-muted k-small">{{ row.site.url }}</div>
                </template>
                <template #cell-status="{ row }">
                    <KBadge :tone="STATUS[row.status]?.tone ?? 'neutral'">{{ STATUS[row.status]?.label ?? row.status }}</KBadge>
                </template>
                <template #cell-when="{ row }"><KTime :value="row.started_at ?? row.created_at" /></template>
                <template #cell-actions="{ row }">
                    <div class="k-import__actions">
                        <KButton size="sm" @click="show(row.id)">{{ __('Report') }}</KButton>
                        <KButton
                            size="sm"
                            variant="ghost"
                            icon="trash"
                            :aria-label="__('Forget')"
                            :loading="busy === `discard:${row.id}`"
                            @click="discard(row)"
                        />
                    </div>
                </template>
            </KTable>
        </KCard>
    </template>

    <!-- review -->
    <template v-else-if="current?.status === 'ready'">
        <KCard :title="current.site.title || current.site.url" :description="current.site.url">
            <KTable
                :columns="[
                    { key: 'label', label: __('What') },
                    { key: 'in', label: __('To import'), align: 'end' },
                    { key: 'skipped', label: __('Skipped'), align: 'end' },
                ]"
                :rows="toImport"
                row-key="kind"
            >
                <template #cell-in="{ row }"
                    ><span class="k-num">{{ (row.total - row.skipped).toLocaleString() }}</span></template
                >
                <template #cell-skipped="{ row }"
                    ><span class="k-num" :class="{ 'k-muted': !row.skipped }">{{ row.skipped.toLocaleString() }}</span></template
                >
            </KTable>
            <ul v-if="current.skipped.length || current.summary.comments" class="k-import__skipped">
                <li v-for="g in current.skipped" :key="g.kind + g.reason">
                    <strong>{{ g.reason }}</strong>
                    <span class="k-muted k-import__eg">{{ g.examples.join(', ') }}<template v-if="g.count > g.examples.length">, …</template></span>
                </li>
                <li v-if="current.summary.comments" class="k-muted">{{ commentsText(current.summary.comments) }}</li>
            </ul>
        </KCard>

        <KCard
            v-if="current.summary.authors.length"
            :title="__('Authors')"
            :description="__('Whose name each post carries here. People are matched by email address, then by username.')"
            flush
        >
            <KTable
                :columns="[
                    { key: 'author', label: __('In WordPress') },
                    { key: 'to', label: __('Here') },
                ]"
                :rows="current.summary.authors"
                row-key="login"
            >
                <template #cell-author="{ row }">
                    {{ row.name || row.login }}
                    <div class="k-muted k-small">
                        {{ row.login }}<template v-if="row.email"> · {{ row.email }}</template> · {{ postsText(row.posts) }}
                    </div>
                </template>
                <template #cell-to="{ row }">
                    <KSelect v-model="options.authors[row.login]" :options="people" :aria-label="__('Credit posts by {name} to', { name: row.login })" />
                </template>
            </KTable>
        </KCard>

        <KCard :title="__('Options')">
            <div class="k-import__options">
                <div>
                    <KSwitch v-model="options.media" :label="__('Copy files from the old site')" />
                    <p class="k-muted k-small">
                        {{
                            __(
                                'Images and documents are downloaded from {url} into the media library, with their alt text and captions. Leave this off when the old site is offline; images then keep pointing at it.',
                                { url: current.site.url },
                            )
                        }}
                    </p>
                </div>
                <div>
                    <KSwitch v-model="options.redirects" :label="__('Redirect old addresses')" />
                    <p class="k-muted k-small">
                        {{
                            __(
                                'Visitors and search engines following an old link land on the same page here. A redirect only applies where nothing else lives at that address.',
                            )
                        }}
                    </p>
                </div>
            </div>
        </KCard>

        <div class="k-import__go">
            <KButton variant="primary" icon="arrow-square-in" :loading="busy === 'start'" @click="start">{{ __('Import') }}</KButton>
            <KButton :loading="busy === `discard:${current.id}`" @click="discard()">{{ __('Cancel') }}</KButton>
        </div>
    </template>

    <!-- running -->
    <KCard v-else-if="running" :title="__('Importing {site}', { site: current.site.title || current.site.url })">
        <template #actions>
            <KButton size="sm" icon="stop-circle" :loading="busy === 'cancel'" @click="act('cancel')">{{ __('Stop') }}</KButton>
        </template>
        <div class="k-import__bar" role="progressbar" :aria-valuenow="progress" aria-valuemin="0" aria-valuemax="100" :aria-label="__('Imported')">
            <span :style="{ width: `${progress}%` }"></span>
        </div>
        <p class="k-import__count">
            <strong class="k-num">{{ current.progress.done.toLocaleString() }}</strong>
            {{ __('of {total}', { total: current.progress.total.toLocaleString() }) }}
            <template v-if="PHASES[current.phase]"> · {{ PHASES[current.phase] }}</template>
        </p>
        <p class="k-muted k-small">
            {{
                current.worker
                    ? __('The background worker is importing. You can leave this screen.')
                    : __(
                          'No background worker runs on this site, so this screen imports while it stays open. Closing it pauses the import; opening it again carries on.',
                      )
            }}
        </p>
    </KCard>

    <!-- report -->
    <template v-else-if="finished">
        <KAlert v-if="current.status === 'cancelled' && current.error" tone="danger" :title="__('The import stopped by itself')">
            {{ current.error }}
            {{ __('What was imported stays. Try again carries on with the rest.') }}
        </KAlert>
        <KAlert v-else-if="current.status === 'cancelled'" tone="warning" :title="__('The import was stopped')">
            {{ __('What was imported stays. Try again carries on with the rest.') }}
        </KAlert>
        <KAlert v-else-if="failed.length" tone="warning" :title="__('Imported, with some things left behind')">
            {{ failedText(total('failed')) }}
        </KAlert>
        <KAlert v-else tone="success" :title="__('{site} is here', { site: current.site.title || current.site.url })">
            {{ __('Everything that could come over did. Below is what changed on the way.') }}
        </KAlert>

        <KCard flush>
            <template #title>
                {{ current.site.title || current.site.url }}
                <span v-if="current.finished_at" class="k-muted k-small k-import__when"><KTime :value="current.finished_at" /></span>
            </template>
            <template v-if="failed.length || current.status === 'cancelled'" #actions>
                <KButton size="sm" variant="primary" icon="arrow-clockwise" :loading="busy === 'retry'" @click="act('retry')">{{ __('Try again') }}</KButton>
            </template>
            <KTable
                :columns="[
                    { key: 'label', label: '' },
                    { key: 'created', label: __('New'), align: 'end' },
                    { key: 'updated', label: __('Updated'), align: 'end' },
                    { key: 'same', label: __('Unchanged'), align: 'end' },
                    { key: 'kept', label: __('Kept as edited'), align: 'end' },
                    { key: 'skipped', label: __('Skipped'), align: 'end' },
                    { key: 'failed', label: __('Failed'), align: 'end' },
                ]"
                :rows="rows(current.counts)"
                row-key="kind"
            >
                <template v-for="key in ['created', 'updated', 'kept', 'skipped', 'failed']" :key="key" #[`cell-${key}`]="{ row }">
                    <span class="k-num" :class="{ 'k-muted': !row[key], 'k-import__bad': key === 'failed' && row[key] }">{{ row[key].toLocaleString() }}</span>
                </template>
                <template #cell-same="{ row }">
                    <span class="k-num" :class="{ 'k-muted': !(row.unchanged + row.matched) }">{{ (row.unchanged + row.matched).toLocaleString() }}</span>
                </template>
            </KTable>
        </KCard>

        <KCard v-if="failed.length" :title="__('Not imported')" flush>
            <KTable
                :columns="[
                    { key: 'label', label: __('Item') },
                    { key: 'message', label: __('Why') },
                ]"
                :rows="failed"
                row-key="label"
            >
                <template #cell-label="{ row }">
                    {{ row.label }}
                    <div class="k-muted k-small">{{ KINDS[row.kind] ?? row.kind }}</div>
                </template>
            </KTable>
        </KCard>

        <KCard
            v-if="kept.length"
            :title="__('Kept as edited here')"
            :description="__('These changed on this site after the last import, so the WordPress version did not replace them.')"
            flush
        >
            <KTable :columns="[{ key: 'label', label: __('Item') }]" :rows="kept" row-key="label">
                <template #cell-label="{ row }">
                    {{ row.label }}
                    <div class="k-muted k-small">{{ KINDS[row.kind] ?? row.kind }}</div>
                </template>
            </KTable>
        </KCard>

        <KCard v-if="current.notices.length || current.conversion.length" :title="__('Changed on the way')">
            <ul class="k-import__list">
                <li v-for="n in current.notices" :key="n.kind + n.label">
                    <strong>{{ n.label }}</strong> <span class="k-muted k-import__eg">{{ n.message }}</span>
                </li>
                <li v-for="c in current.conversion" :key="c.key">
                    <span class="k-num k-import__times">{{ c.count }} ×</span> {{ c.text }}
                </li>
            </ul>
        </KCard>

        <KCard v-if="current.skipped.length" :title="__('Skipped')">
            <ul class="k-import__list">
                <li v-for="g in current.skipped" :key="g.kind + g.reason">
                    <span class="k-num k-import__times">{{ g.count }} ×</span> {{ g.reason }}
                    <span class="k-muted k-import__eg">{{ g.examples.join(', ') }}<template v-if="g.count > g.examples.length">, …</template></span>
                </li>
            </ul>
        </KCard>
    </template>
</template>

<style scoped>
.k-card + .k-card {
    margin-top: 14px;
}
.k-import__how {
    margin: 0 0 16px;
    padding-left: 1.25rem;
    display: grid;
    gap: 4px;
}
.k-import__upload {
    display: grid;
    gap: 14px;
    max-width: 40rem;
}
.k-import__upload :deep(.k-field) {
    margin: 0;
}
.k-import__cli {
    margin: 20px 0 0;
    padding-top: 14px;
    border-top: 1px solid var(--k-rule-soft);
}
.k-import__cli code {
    display: block;
    width: fit-content;
    max-width: 100%;
    margin-top: 6px;
    overflow-x: auto;
    white-space: nowrap; /* one line to copy; scrolls on a narrow screen */
}
.k-import__actions {
    display: flex;
    align-items: center;
    gap: 4px;
    justify-content: flex-end;
}
.k-import__skipped,
.k-import__list {
    list-style: none;
    margin: 14px 0 0;
    padding: 0;
    display: grid;
    gap: 6px;
}
.k-import__list {
    margin: 0;
}
.k-import__times {
    display: inline-block;
    min-width: 3ch;
    color: var(--k-ink-3);
}
.k-import__eg {
    margin-left: 0.35em;
}
.k-import__options {
    display: grid;
    gap: 16px;
}
.k-import__options p {
    margin: 4px 0 0;
    max-width: 60ch;
}
.k-import__go {
    display: flex;
    gap: 8px;
    margin-top: 16px;
}
.k-import__bar {
    height: 8px;
    border: 1px solid var(--k-rule);
    background: var(--k-sheet);
    overflow: hidden;
}
.k-import__bar span {
    display: block;
    height: 100%;
    background: var(--k-mark);
    transition: width 300ms ease-out;
}
.k-import__count {
    margin: 10px 0 4px;
}
.k-import__when {
    margin-left: 8px;
    font-weight: normal;
}
.k-import__bad {
    color: var(--k-danger);
    font-weight: 600;
}
@media (prefers-reduced-motion: reduce) {
    .k-import__bar span {
        transition: none;
    }
}
</style>
