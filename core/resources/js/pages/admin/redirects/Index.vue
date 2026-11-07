<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Head } from '@kestello/app';
import { ApiError, confirm, notify, useApi, __ } from '@kestello/sdk';
import { KBadge, KButton, KCard, KField, KInput, KModal, KPageHeader, KSelect, KTable, KTabs, KTime } from '@kestello/ui';

defineOptions({ layout: 'Admin' });

/**
 * Appearance → Redirects: rules for addresses that no longer exist, and
 * the addresses visitors asked for that found nothing.
 */
const api = useApi();
const tab = ref('rules');

const MATCHES = [
    { value: 'exact', label: __('This address') },
    { value: 'prefix', label: __('This address and everything below it') },
    { value: 'regex', label: __('A pattern (regular expression)') },
];
const STATUSES = [
    { value: 301, label: __('Moved for good (301)') },
    { value: 302, label: __('Moved for now (302)') },
    { value: 307, label: __('Moved for now, keep the method (307)') },
    { value: 308, label: __('Moved for good, keep the method (308)') },
    { value: 410, label: __('Gone (410)') },
];
const ORIGINS = [
    { value: '', label: __('Added any way') },
    { value: 'manual', label: __('Added here') },
    { value: 'auto', label: __('When a page moved') },
    { value: 'import', label: __('Imported') },
];

// ── rules ────────────────────────────────────────────────────────────
const rules = reactive({ rows: [], total: null, page: 1, lastPage: 1, loading: false, search: '', origin: '' });
let seq = 0;
async function loadRules(page = 1) {
    const mine = ++seq;
    rules.loading = true;
    try {
        const body = await api.get('/redirects', { page, per_page: 50, search: rules.search.trim() || undefined, origin: rules.origin || undefined });
        if (mine !== seq) return;
        Object.assign(rules, { rows: body.data, total: body.meta.total, page: body.meta.page, lastPage: body.meta.last_page });
    } catch (e) {
        notify.error(e);
    } finally {
        if (mine === seq) rules.loading = false;
    }
}
let debounce = null;
watch(
    () => rules.search,
    () => {
        clearTimeout(debounce);
        debounce = setTimeout(() => loadRules(), 250);
    },
);
watch(
    () => rules.origin,
    () => loadRules(),
);
loadRules();

const ruleColumns = [
    { key: 'source', label: __('From') },
    { key: 'target', label: __('To') },
    { key: 'status', label: __('Kind') },
    { key: 'hits', label: __('Used'), align: 'end' },
    { key: 'last_hit_at', label: __('Last used') },
    { key: 'actions', label: '', align: 'end' },
];
const statusShort = (s) => ({ 301: __('Permanent'), 302: __('Temporary'), 307: __('Temporary'), 308: __('Permanent'), 410: __('Gone') })[s] ?? s;

// ── not found ────────────────────────────────────────────────────────
const missing = reactive({ rows: [], total: null, page: 1, lastPage: 1, loading: false, sort: 'hits' });
async function loadMissing(page = 1) {
    missing.loading = true;
    try {
        const body = await api.get('/redirects/not-found', { page, per_page: 50, sort: missing.sort });
        Object.assign(missing, { rows: body.data, total: body.meta.total, page: body.meta.page, lastPage: body.meta.last_page });
    } catch (e) {
        notify.error(e);
    } finally {
        missing.loading = false;
    }
}
watch(
    () => missing.sort,
    () => loadMissing(),
);
loadMissing();
const missingColumns = [
    { key: 'path', label: __('Address') },
    { key: 'hits', label: __('Asked for'), align: 'end' },
    { key: 'last_seen_at', label: __('Last time') },
    { key: 'referrer', label: __('Linked from') },
    { key: 'actions', label: '', align: 'end' },
];
async function forget(row) {
    try {
        await api.delete('/redirects/not-found', { query: { id: row.id } });
        missing.rows = missing.rows.filter((r) => r.id !== row.id);
        missing.total = Math.max(0, (missing.total ?? 1) - 1);
    } catch (e) {
        notify.error(e);
    }
}
async function clearLog() {
    if (
        !(await confirm({
            title: __('Clear the list?'),
            message: __('Every logged address is forgotten. New ones appear as visitors find them.'),
            confirmLabel: __('Clear the list'),
            danger: true,
        }))
    )
        return;
    try {
        await api.delete('/redirects/not-found');
        await loadMissing();
        notify.success(__('The list is empty.'));
    } catch (e) {
        notify.error(e);
    }
}

const tabs = computed(() => [
    { id: 'rules', label: __('Redirects'), count: rules.total },
    { id: 'missing', label: __('Not found'), count: missing.total },
]);

// ── add / edit ───────────────────────────────────────────────────────
const editing = ref(null); // null (closed), 'new' or a rule id
const form = reactive({ source: '', match: 'exact', target: '', status: 301, note: '' });
const errors = ref({});
const saving = ref(false);
function openRule(rule = null, source = '') {
    Object.assign(
        form,
        rule
            ? { source: rule.source, match: rule.match, target: rule.target, status: rule.status, note: rule.note }
            : { source, match: 'exact', target: '', status: 301, note: '' },
    );
    errors.value = {};
    editing.value = rule ? rule.id : 'new';
}
async function saveRule() {
    saving.value = true;
    errors.value = {};
    const body = { ...form, status: Number(form.status), target: Number(form.status) === 410 ? '' : form.target.trim(), source: form.source.trim() };
    try {
        if (editing.value === 'new') await api.post('/redirects', body);
        else await api.patch(`/redirects/${editing.value}`, body);
        notify.success(editing.value === 'new' ? __('Redirect added.') : __('Redirect saved.'));
        editing.value = null;
        await Promise.all([loadRules(rules.page), loadMissing(missing.page)]);
    } catch (e) {
        if (e instanceof ApiError && e.status === 422) errors.value = e.fieldErrors();
        else notify.error(e);
    } finally {
        saving.value = false;
    }
}
async function removeRule(rule) {
    if (
        !(await confirm({
            title: __('Remove this redirect?'),
            message: __('Visitors to {from} will see the "not found" page.', { from: rule.source }),
            confirmLabel: __('Remove'),
            danger: true,
        }))
    )
        return;
    try {
        await api.delete(`/redirects/${rule.id}`);
        notify.success(__('Redirect removed.'));
        await loadRules(rules.rows.length === 1 && rules.page > 1 ? rules.page - 1 : rules.page);
    } catch (e) {
        notify.error(e);
    }
}
const sourceHelp = computed(
    () =>
        ({
            exact: __('An address on this site, such as /old-page.'),
            prefix: __('/old-blog also sends /old-blog/any/post to the same place below the new address.'),
            regex: __('Matched against the whole address, such as ^/news/(\\d+)/(.+)$. Use (…) groups and $1, $2 in the target.'),
        })[form.match],
);

// ── import / export ──────────────────────────────────────────────────
const importing = ref(false);
const csv = ref('');
const importResult = ref(null);
async function readFile(event) {
    const file = event.target.files?.[0];
    if (file) csv.value = await file.text();
}
async function runImport() {
    saving.value = true;
    try {
        const { data } = await api.post('/redirects/import', { csv: csv.value });
        importResult.value = data;
        await loadRules();
        if (!data.errors.length) {
            importing.value = false;
            notify.success(__('{created} added, {updated} updated.', { created: data.created, updated: data.updated }));
        }
    } catch (e) {
        notify.error(e);
    } finally {
        saving.value = false;
    }
}
async function exportCsv() {
    try {
        const response = await fetch('/api/v1/redirects/export', { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        if (!response.ok) throw new ApiError(response.status, { title: response.statusText });
        const url = URL.createObjectURL(await response.blob());
        const a = Object.assign(document.createElement('a'), { href: url, download: `redirects-${new Date().toISOString().slice(0, 10)}.csv` });
        a.click();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (e) {
        notify.error(e);
    }
}
</script>

<template>
    <Head :title="__('Redirects')" />
    <KPageHeader
        :title="__('Redirects')"
        :description="
            __('Send visitors from addresses that no longer exist to the right place. When a published page moves, its old address is added here for you.')
        "
    >
        <template #actions>
            <KButton icon="download" :disabled="rules.total === 0 && !rules.search && !rules.origin" @click="exportCsv">{{ __('Export') }}</KButton>
            <KButton
                icon="upload"
                @click="
                    importing = true;
                    importResult = null;
                    csv = '';
                "
                >{{ __('Import') }}</KButton
            >
            <KButton variant="primary" icon="plus" @click="openRule()">{{ __('Add a redirect') }}</KButton>
        </template>
    </KPageHeader>

    <KTabs v-model="tab" :tabs="tabs" :label="__('Redirects')" />

    <template v-if="tab === 'rules'">
        <div class="k-toolbar" role="search">
            <KField v-slot="{ id }" :label="__('Search')" class="k-toolbar__grow">
                <KInput :id="id" v-model="rules.search" type="search" autocomplete="off" />
            </KField>
            <KField v-slot="{ id }" :label="__('Added')">
                <KSelect :id="id" v-model="rules.origin" :options="ORIGINS" />
            </KField>
        </div>
        <KCard coord="R1" :title="__('Redirects')" flush>
            <KTable
                :columns="ruleColumns"
                :rows="rules.rows"
                :loading="rules.loading && !rules.rows.length"
                :empty="rules.search || rules.origin ? __('No redirect matches.') : __('No redirects yet')"
                :empty-text="rules.search || rules.origin ? '' : __('Moving a published page adds one, or add one yourself.')"
            >
                <template #cell-source="{ row }">
                    <div class="k-redirect__path">{{ row.source }}</div>
                    <div v-if="row.match !== 'exact' || row.origin !== 'manual' || row.note" class="k-muted k-small">
                        <template v-if="row.match === 'prefix'">{{ __('and everything below') }}</template>
                        <template v-else-if="row.match === 'regex'">{{ __('pattern') }}</template>
                        <template v-if="row.origin === 'auto'">{{ row.match !== 'exact' ? ' · ' : '' }}{{ __('page moved') }}</template>
                        <template v-else-if="row.origin === 'import'">{{ row.match !== 'exact' ? ' · ' : '' }}{{ __('imported') }}</template>
                        <template v-if="row.note"> · {{ row.note }}</template>
                    </div>
                </template>
                <template #cell-target="{ row }">
                    <span v-if="row.status === 410" class="k-muted">{{ __('No new address') }}</span>
                    <a v-else :href="row.target" class="k-link k-redirect__path" target="_blank" rel="noopener noreferrer">{{ row.target }}</a>
                </template>
                <template #cell-status="{ row }"
                    ><KBadge>{{ statusShort(row.status) }} {{ row.status }}</KBadge></template
                >
                <template #cell-hits="{ row }"
                    ><span class="k-num">{{ row.hits.toLocaleString() }}</span></template
                >
                <template #cell-last_hit_at="{ row }"
                    ><KTime v-if="row.last_hit_at" :value="row.last_hit_at" /><span v-else class="k-muted">{{ __('Never') }}</span></template
                >
                <template #cell-actions="{ row }">
                    <div class="k-row-actions">
                        <KButton size="sm" variant="ghost" icon="pencil" @click="openRule(row)">{{ __('Edit') }}</KButton>
                        <KButton
                            size="sm"
                            variant="ghost"
                            icon="trash"
                            :aria-label="__('Remove the redirect from {from}', { from: row.source })"
                            @click="removeRule(row)"
                            >{{ __('Remove') }}</KButton
                        >
                    </div>
                </template>
            </KTable>
            <template v-if="rules.lastPage > 1" #footer>
                <div class="k-pager">
                    <KButton size="sm" :disabled="rules.page <= 1" @click="loadRules(rules.page - 1)">{{ __('Previous') }}</KButton>
                    <span class="k-muted k-small">{{ __('Page {page} of {last}', { page: rules.page, last: rules.lastPage }) }}</span>
                    <KButton size="sm" :disabled="rules.page >= rules.lastPage" @click="loadRules(rules.page + 1)">{{ __('Next') }}</KButton>
                </div>
            </template>
        </KCard>
    </template>

    <template v-else>
        <div class="k-toolbar">
            <KField v-slot="{ id }" :label="__('Order')">
                <KSelect
                    :id="id"
                    v-model="missing.sort"
                    :options="[
                        { value: 'hits', label: __('Most asked for') },
                        { value: 'recent', label: __('Most recent') },
                    ]"
                />
            </KField>
            <div class="k-toolbar__grow"></div>
            <KButton v-if="missing.total" variant="ghost" icon="x-circle" @click="clearLog">{{ __('Clear the list') }}</KButton>
        </div>
        <KCard coord="R2" :title="__('Addresses that found nothing')" flush>
            <KTable
                :columns="missingColumns"
                :rows="missing.rows"
                :loading="missing.loading && !missing.rows.length"
                :empty="__('Nothing yet')"
                :empty-text="__('Addresses visitors ask for that don\'t exist show up here, so you can send them somewhere useful.')"
            >
                <template #cell-path="{ row }"
                    ><span class="k-redirect__path">{{ row.path }}</span></template
                >
                <template #cell-hits="{ row }"
                    ><span class="k-num">{{ row.hits.toLocaleString() }}</span></template
                >
                <template #cell-last_seen_at="{ row }"><KTime :value="row.last_seen_at" /></template>
                <template #cell-referrer="{ row }">
                    <span v-if="!row.referrer" class="k-muted">{{ __('Typed or bookmarked') }}</span>
                    <span v-else class="k-redirect__ref" :title="row.referrer">{{ row.referrer.replace(/^https?:\/\//, '') }}</span>
                </template>
                <template #cell-actions="{ row }">
                    <div class="k-row-actions">
                        <KButton size="sm" icon="signpost" @click="openRule(null, row.path)">{{ __('Redirect') }}</KButton>
                        <KButton size="sm" variant="ghost" icon="x" :aria-label="__('Forget {path}', { path: row.path })" @click="forget(row)" />
                    </div>
                </template>
            </KTable>
            <template v-if="missing.lastPage > 1" #footer>
                <div class="k-pager">
                    <KButton size="sm" :disabled="missing.page <= 1" @click="loadMissing(missing.page - 1)">{{ __('Previous') }}</KButton>
                    <span class="k-muted k-small">{{ __('Page {page} of {last}', { page: missing.page, last: missing.lastPage }) }}</span>
                    <KButton size="sm" :disabled="missing.page >= missing.lastPage" @click="loadMissing(missing.page + 1)">{{ __('Next') }}</KButton>
                </div>
            </template>
        </KCard>
    </template>

    <KModal :open="editing !== null" :title="editing === 'new' ? __('Add a redirect') : __('Edit the redirect')" @close="editing = null">
        <form id="k-redirect-form" class="k-form" @submit.prevent="saveRule">
            <KField v-slot="{ id }" :label="__('Which addresses')">
                <KSelect :id="id" v-model="form.match" :options="MATCHES" />
            </KField>
            <KField
                v-slot="{ id, describedBy, invalid }"
                :label="form.match === 'regex' ? __('Pattern') : __('From')"
                :help="sourceHelp"
                :error="errors.source ?? ''"
                required
            >
                <KInput
                    :id="id"
                    v-model="form.source"
                    :class="{ 'k-mono-input': form.match === 'regex' }"
                    autocomplete="off"
                    spellcheck="false"
                    :invalid="invalid"
                    :aria-describedby="describedBy"
                />
            </KField>
            <KField
                v-slot="{ id }"
                :label="__('Kind')"
                :help="Number(form.status) === 301 || Number(form.status) === 308 ? __('Search engines move the old address\'s ranking to the new one.') : ''"
            >
                <KSelect :id="id" v-model="form.status" :options="STATUSES" />
            </KField>
            <KField
                v-if="Number(form.status) !== 410"
                v-slot="{ id, describedBy, invalid }"
                :label="__('To')"
                :help="__('A path on this site such as /new-page, or a full https:// address.')"
                :error="errors.target ?? ''"
                required
            >
                <KInput :id="id" v-model="form.target" autocomplete="off" spellcheck="false" :invalid="invalid" :aria-describedby="describedBy" />
            </KField>
            <KField v-slot="{ id, describedBy, invalid }" :label="__('Note')" :help="__('Only you and other editors see it.')" :error="errors.note ?? ''">
                <KInput :id="id" v-model="form.note" maxlength="255" :invalid="invalid" :aria-describedby="describedBy" />
            </KField>
            <p v-if="errors.match || errors.status" class="k-field__error" role="alert">{{ errors.match ?? errors.status }}</p>
        </form>
        <template #footer>
            <KButton @click="editing = null">{{ __('Cancel') }}</KButton>
            <KButton variant="primary" type="submit" form="k-redirect-form" :loading="saving">{{
                editing === 'new' ? __('Add the redirect') : __('Save')
            }}</KButton>
        </template>
    </KModal>

    <KModal :open="importing" :title="__('Import redirects')" size="lg" @close="importing = false">
        <form id="k-redirect-import" class="k-form" @submit.prevent="runImport">
            <p class="k-muted">
                {{
                    __(
                        'A CSV file with one redirect per line: source, target, status, match, note. Only the first two are needed. Existing addresses are updated.',
                    )
                }}
            </p>
            <KField v-slot="{ id }" :label="__('File')">
                <input :id="id" type="file" accept=".csv,text/csv,text/plain" @change="readFile" />
            </KField>
            <KField v-slot="{ id, describedBy }" :label="__('Or paste the lines')" :help="__('Such as: /old-page,/new-page')">
                <textarea
                    :id="id"
                    :aria-describedby="describedBy"
                    v-model="csv"
                    class="k-input k-mono-input k-redirect__csv"
                    rows="8"
                    spellcheck="false"
                ></textarea>
            </KField>
            <div v-if="importResult" class="k-redirect__result" role="status">
                <p>{{ __('{created} added, {updated} updated.', { created: importResult.created, updated: importResult.updated }) }}</p>
                <template v-if="importResult.errors.length">
                    <p>{{ __('These lines were skipped:') }}</p>
                    <ul>
                        <li v-for="err in importResult.errors.slice(0, 50)" :key="err.line">
                            {{ __('Line {line}: {message}', { line: err.line, message: err.message }) }}
                        </li>
                    </ul>
                </template>
            </div>
        </form>
        <template #footer>
            <KButton @click="importing = false">{{ importResult ? __('Close') : __('Cancel') }}</KButton>
            <KButton variant="primary" type="submit" form="k-redirect-import" :loading="saving" :disabled="!csv.trim()">{{ __('Import') }}</KButton>
        </template>
    </KModal>
</template>

<style scoped>
.k-redirect__path {
    font-family: var(--k-mono);
    font-size: 12.5px;
    word-break: break-all;
}
.k-redirect__ref {
    display: inline-block;
    max-width: 18rem;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    vertical-align: bottom;
    color: var(--k-ink-2);
}
.k-redirect__csv {
    width: 100%;
    min-height: 10rem;
    resize: vertical;
    padding: 8px 10px;
}
.k-redirect__result {
    border: 1px solid var(--k-rule);
    background: var(--k-sheet);
    padding: 10px 14px;
}
.k-redirect__result p {
    margin: 0 0 6px;
}
.k-redirect__result ul {
    margin: 0;
    padding-left: 18px;
    color: var(--k-ink-2);
    font-size: 13px;
    max-height: 12rem;
    overflow: auto;
}
.k-num {
    font-variant-numeric: tabular-nums;
}
.k-pager {
    display: flex;
    align-items: center;
    gap: 12px;
    justify-content: flex-end;
}
</style>
