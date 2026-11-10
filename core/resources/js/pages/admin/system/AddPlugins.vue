<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Head } from '@kestello/app';
import { formatBytes, formatDateTime, formatRelative, notify, useApi, __ } from '@kestello/sdk';
import { KAlert, KBadge, KButton, KEmpty, KField, KIcon, KInput, KModal, KPageHeader, KSelect, KSpinner } from '@kestello/ui';
import PackageReviewModal from '../../../components/packages/PackageReviewModal.vue';
import { permissionRows } from '../../../components/packages/permissions.js';

defineOptions({ layout: 'Admin' });

/**
 * Plugins → Add new: the plugins in the Kestello registry, searched and filtered by category.
 * Details shows what the registry knows about one (its description, publisher, what the newest
 * version this site can run declares); Install downloads that version, checked against the
 * registry, and opens the same review as an uploaded zip. Nothing is loaded from a publisher's
 * server: the registry's text comes through the site, without images.
 */
const api = useApi();

const filters = reactive({ q: '', category: '' });
const result = ref(null);
const rows = ref([]);
const loading = ref(false);
const loadError = ref(null);
let request = 0;

async function load({ page = 1, refresh = false } = {}) {
    const mine = ++request;
    loading.value = true;
    try {
        const query = { q: filters.q.trim() || undefined, category: filters.category || undefined, page, refresh: refresh || undefined };
        const data = (await api.get('/plugins/registry', query)).data;
        if (mine !== request) return; // a newer search answered first
        result.value = data;
        rows.value = page === 1 ? data.packages : [...rows.value, ...data.packages];
        loadError.value = null;
    } catch (e) {
        if (mine === request) loadError.value = e;
    } finally {
        if (mine === request) loading.value = false;
    }
}
load({ refresh: true });

let debounce = null;
watch(
    () => filters.q,
    () => {
        clearTimeout(debounce);
        debounce = setTimeout(() => load(), 250);
    },
);
watch(
    () => filters.category,
    () => load(),
);

const categoryLabels = {
    content: __('Content'),
    design: __('Design and layout'),
    forms: __('Forms and surveys'),
    commerce: __('Shops and payments'),
    marketing: __('Marketing and search'),
    media: __('Pictures and media'),
    people: __('Members and accounts'),
    integrations: __('Other services'),
    developer: __('Developer tools'),
    theme: __('Themes'),
};
const categoryLabel = (id) => categoryLabels[id] ?? id;
const categoryOptions = computed(() => [
    { value: '', label: __('All categories') },
    ...Object.entries(result.value?.categories ?? {}).map(([id, count]) => ({ value: id, label: `${categoryLabel(id)} (${count})` })),
]);
const more = computed(() => !!result.value && rows.value.length < result.value.total);
const filtered = computed(() => !!(filters.q.trim() || filters.category));

function clearFilters() {
    filters.q = '';
    filters.category = '';
}

// ── one plugin in full ───────────────────────────────────────────────
const detailsOpen = ref(false);
const details = ref(null);
const detailsError = ref(null);

async function openDetails(row) {
    details.value = { slug: row.slug, name: row.name, loading: true };
    detailsError.value = null;
    detailsOpen.value = true;
    try {
        details.value = (await api.get(`/plugins/registry/${row.slug}`)).data;
    } catch (e) {
        detailsError.value = e;
    }
}
const needs = computed(() => {
    const o = details.value?.offer;
    if (!o) return '';
    return [
        __('Kestello {core}', { core: o.requires.core }),
        o.requires.php ? `PHP ${o.requires.php}` : null,
        ...o.requires.extensions,
        ...Object.keys(o.dependencies),
    ]
        .filter(Boolean)
        .join(', ');
});
const detailRows = computed(() => (details.value?.offer ? permissionRows(details.value.offer.permissions) : []));

// ── download for review, then the review shared with Upload ─────────
const busy = ref('');
const review = ref(null);
const reviewing = ref(false);
const policy = ref({ trusted: [] });
// which publishers this site trusts itself, so a refused review can say so
api.get('/plugins')
    .then((r) => (policy.value = r.data.policy))
    .catch(() => {});

async function startInstall(slug, version) {
    busy.value = slug;
    try {
        review.value = (await api.post(`/plugins/registry/${slug}/review`, { version })).data;
        detailsOpen.value = false;
        reviewing.value = true;
    } catch (e) {
        notify.error(e);
    } finally {
        busy.value = '';
    }
}
async function installRow(row) {
    // a row shows the newest version; the details say which one this site can run
    busy.value = row.slug;
    let full = null;
    try {
        full = (await api.get(`/plugins/registry/${row.slug}`)).data;
    } catch (e) {
        notify.error(e);
    } finally {
        busy.value = '';
    }
    if (!full) return;
    if (full.offer) startInstall(row.slug, full.offer.version);
    else openDetails(row);
}

function done(overview) {
    policy.value = overview.policy ?? policy.value;
    load(); // installed and update marks
}

function rowAction(row) {
    if (!row.compatible) return null;
    if (row.update) return { label: __('Update'), icon: 'arrows-clockwise' };
    if (row.installed) return null;
    return { label: __('Install'), icon: 'download-simple' };
}
// in the script: "}}" in a template expression would end it
const countLabel = computed(() => __('{count, plural, one {# plugin} other {# plugins}}', { count: result.value?.total ?? 0 }));
const checked = computed(() => (result.value?.status?.checked_at ? formatRelative(result.value.status.checked_at) : null));
</script>

<template>
    <Head :title="__('Add plugins')" />
    <KPageHeader
        :title="__('Add plugins')"
        :description="
            __(
                'Plugins from the Kestello registry. Every version there was checked and signed by its publisher, and this site checks the zip again before installing it.',
            )
        "
    >
        <template #actions>
            <KButton href="/admin/system/plugins" icon="plug">{{ __('Manage plugins') }}</KButton>
        </template>
    </KPageHeader>

    <KAlert v-if="loadError" tone="danger">{{ loadError.message }}</KAlert>
    <template v-else-if="result && !result.enabled">
        <KEmpty icon="prohibit" :title="__('The registry is turned off on this site')">
            {{ __('Plugins are installed from a zip under Manage plugins. A developer turns the registry on in config/registry.php (enabled).') }}
        </KEmpty>
    </template>
    <template v-else-if="result">
        <KAlert v-if="result.status.error" :tone="result.status.usable ? 'warning' : 'danger'" :title="__('The registry could not be checked')">
            {{ result.status.error }}
            <template v-if="result.status.usable"> {{ __('The list below is the last one this site checked.') }}</template>
        </KAlert>

        <div class="k-toolbar" role="search">
            <KField v-slot="{ id }" :label="__('Search plugins')" class="k-toolbar__grow">
                <KInput :id="id" v-model="filters.q" type="search" autocomplete="off" maxlength="100" />
            </KField>
            <KField v-slot="{ id }" :label="__('Category')">
                <KSelect :id="id" v-model="filters.category" :options="categoryOptions" />
            </KField>
        </div>

        <p class="k-muted k-small k-add__count" aria-live="polite">
            <KSpinner v-if="loading" />
            <template v-else>
                {{ countLabel }}
                <template v-if="checked"> · {{ __('list checked {when}', { when: checked }) }}</template>
            </template>
        </p>

        <KEmpty v-if="!rows.length && !loading" icon="magnifying-glass" :title="filtered ? __('No plugins match') : __('No plugins yet')">
            <template v-if="filtered">
                <KButton size="sm" @click="clearFilters">{{ __('Clear the search') }}</KButton>
            </template>
            <template v-else>{{ __('The registry has no plugins yet.') }}</template>
        </KEmpty>
        <ul v-else class="k-add">
            <li v-for="row in rows" :key="row.slug" class="k-add__row">
                <div class="k-add__main">
                    <div class="k-add__title">
                        <h2>
                            <button type="button" class="k-add__name" @click="openDetails(row)">{{ row.name }}</button>
                        </h2>
                        <span class="k-num k-muted k-small">{{ row.version }}</span>
                        <KBadge v-if="row.update" tone="info">{{ __('Update to {v}', { v: row.version }) }}</KBadge>
                        <KBadge v-else-if="row.installed" tone="success">{{ __('Installed') }}</KBadge>
                        <KBadge v-if="!row.compatible" tone="warning">{{ __('Needs Kestello {v}', { v: row.requires_core }) }}</KBadge>
                        <KBadge v-if="row.prerelease" tone="neutral">{{ __('Test version') }}</KBadge>
                    </div>
                    <p class="k-add__summary">{{ row.summary }}</p>
                    <p class="k-muted k-small k-add__meta">
                        {{ __('By {organisation}', { organisation: row.organisation.name }) }}
                        <span v-if="row.organisation.verified" class="k-add__verified">
                            <KIcon name="seal-check" :label="__('Verified: {domain}', { domain: row.organisation.verified })" />
                        </span>
                        <template v-if="row.categories.length"> · {{ row.categories.map(categoryLabel).join(', ') }}</template>
                        <template v-if="row.installed && row.update"> · {{ __('{v} installed', { v: row.installed }) }}</template>
                    </p>
                </div>
                <div class="k-add__actions">
                    <KButton size="sm" variant="ghost" :aria-label="__('Details of {plugin}', { plugin: row.name })" @click="openDetails(row)">{{
                        __('Details')
                    }}</KButton>
                    <KButton
                        v-if="rowAction(row)"
                        size="sm"
                        variant="primary"
                        :icon="rowAction(row).icon"
                        :loading="busy === row.slug"
                        :disabled="!!busy"
                        :aria-label="`${rowAction(row).label}: ${row.name}`"
                        @click="installRow(row)"
                        >{{ rowAction(row).label }}</KButton
                    >
                </div>
            </li>
        </ul>
        <div v-if="more" class="k-add__more">
            <KButton :loading="loading" @click="load({ page: result.page + 1 })">{{ __('Show more') }}</KButton>
        </div>
    </template>
    <p v-else class="k-add__loading"><KSpinner /> {{ __('Reading the registry…') }}</p>

    <KModal v-model:open="detailsOpen" :title="details?.name ?? ''" size="lg">
        <KAlert v-if="detailsError" tone="danger">{{ detailsError.message }}</KAlert>
        <p v-else-if="details?.loading" class="k-add__loading"><KSpinner /> {{ __('Reading the registry…') }}</p>
        <div v-else-if="details" class="k-detail">
            <p class="k-detail__summary">{{ details.summary }}</p>
            <p class="k-muted k-small">
                {{ __('By {organisation}', { organisation: details.organisation.name }) }}
                <template v-if="details.organisation.verified"> · {{ __('verified owner of {domain}', { domain: details.organisation.verified }) }}</template>
            </p>

            <KAlert v-if="details.installed?.withdrawn" tone="danger" :title="__('The installed version was withdrawn')">
                {{ details.installed.withdrawn }}
            </KAlert>
            <KAlert v-if="!details.offer && details.needs_core" tone="warning">
                {{
                    __('Version {v} needs Kestello {core}. Update Kestello under Site → Updates first.', {
                        v: details.needs_core.version,
                        core: details.needs_core.requires,
                    })
                }}
            </KAlert>
            <KAlert v-else-if="!details.offer" tone="warning">{{ __('No version of it runs on this site (PHP version or extensions).') }}</KAlert>

            <dl class="k-detail__facts">
                <template v-if="details.offer">
                    <dt>{{ __('Version') }}</dt>
                    <dd class="k-num">
                        {{ details.offer.version }}
                        <span class="k-muted">· {{ formatDateTime(details.offer.released_at) }} · {{ formatBytes(details.offer.size) }}</span>
                    </dd>
                    <dt>{{ __('Needs') }}</dt>
                    <dd>{{ needs }}</dd>
                </template>
                <template v-if="details.installed">
                    <dt>{{ __('Installed') }}</dt>
                    <dd class="k-num">{{ details.installed.version }}{{ details.installed.active ? '' : ` (${__('inactive')})` }}</dd>
                </template>
                <dt>{{ __('License') }}</dt>
                <dd>{{ details.license }}</dd>
                <template v-if="details.categories.length">
                    <dt>{{ __('Category') }}</dt>
                    <dd>{{ details.categories.map(categoryLabel).join(', ') }}</dd>
                </template>
                <template v-if="details.homepage || details.support || details.source">
                    <dt>{{ __('Links') }}</dt>
                    <dd class="k-detail__links">
                        <a v-if="details.homepage" :href="details.homepage" target="_blank" rel="noopener noreferrer nofollow">{{ __('Website') }}</a>
                        <a v-if="details.support" :href="details.support" target="_blank" rel="noopener noreferrer nofollow">{{ __('Support') }}</a>
                        <a v-if="details.source" :href="details.source" target="_blank" rel="noopener noreferrer nofollow">{{ __('Source code') }}</a>
                    </dd>
                </template>
            </dl>

            <template v-if="details.offer">
                <h3 class="k-detail__h">{{ __('What it may do') }}</h3>
                <dl v-if="detailRows.length" class="k-detail__facts">
                    <template v-for="row in detailRows" :key="row.label"
                        ><dt>{{ row.label }}</dt>
                        <dd>{{ row.value }}</dd></template
                    >
                </dl>
                <p v-else class="k-muted k-small">{{ __('It declares nothing beyond its own pages, tables and settings.') }}</p>
            </template>

            <template v-if="details.description_html">
                <h3 class="k-detail__h">{{ __('About') }}</h3>
                <!-- eslint-disable-next-line vue/no-v-html -- sanitised on the server (RegistryCatalog::html), without images -->
                <div class="k-detail__text" v-html="details.description_html"></div>
            </template>
            <template v-if="details.offer?.notes_html">
                <h3 class="k-detail__h">{{ __('What changed in {v}', { v: details.offer.version }) }}</h3>
                <!-- eslint-disable-next-line vue/no-v-html -- sanitised on the server (RegistryCatalog::html), without images -->
                <div class="k-detail__text" v-html="details.offer.notes_html"></div>
            </template>
            <template v-if="details.versions.length > 1">
                <h3 class="k-detail__h">{{ __('Versions') }}</h3>
                <ul class="k-detail__versions">
                    <li v-for="v in details.versions" :key="v.version">
                        <span class="k-num">{{ v.version }}</span>
                        <span class="k-muted"> · {{ formatDateTime(v.released_at) }}</span>
                        <template v-if="v.withdrawn">
                            · <span class="k-detail__withdrawn">{{ __('withdrawn: {reason}', { reason: v.withdrawn }) }}</span></template
                        >
                    </li>
                </ul>
            </template>
        </div>
        <template #footer>
            <KButton @click="detailsOpen = false">{{ __('Close') }}</KButton>
            <KButton
                v-if="details?.offer && (!details.installed || details.update)"
                variant="primary"
                :icon="details.update ? 'arrows-clockwise' : 'download-simple'"
                :loading="busy === details.slug"
                @click="startInstall(details.slug, details.offer.version)"
                >{{ details.update ? __('Update to {v}', { v: details.offer.version }) : __('Install {v}', { v: details.offer.version }) }}</KButton
            >
        </template>
    </KModal>

    <PackageReviewModal v-model:open="reviewing" :review="review" :policy="policy" @done="done" />
</template>

<style scoped>
.k-add__count {
    display: flex;
    gap: 6px;
    align-items: center;
    min-height: 20px;
    margin: 10px 0 0;
}
.k-add {
    list-style: none;
    margin: 8px 0 0;
    padding: 0;
    border: 1px solid var(--k-rule);
    background: var(--k-sheet);
}
.k-add__row {
    display: flex;
    gap: 16px;
    align-items: flex-start;
    padding: 14px 16px;
}
.k-add__row + .k-add__row {
    border-top: 1px solid var(--k-rule);
}
.k-add__main {
    flex: 1;
    min-width: 0;
    display: grid;
    gap: 4px;
    justify-items: start;
}
.k-add__title {
    display: flex;
    align-items: baseline;
    gap: 8px;
    flex-wrap: wrap;
}
.k-add__title h2 {
    margin: 0;
    font-size: 15px;
    font-weight: 650;
}
.k-add__name {
    all: unset;
    cursor: pointer;
    color: var(--k-ink);
}
.k-add__name:hover {
    text-decoration: underline;
}
.k-add__name:focus-visible {
    outline: 2px solid var(--k-focus, currentColor);
    outline-offset: 2px;
}
.k-add__summary,
.k-add__meta {
    margin: 0;
}
.k-add__summary {
    font-size: 13px;
    color: var(--k-ink-2);
    max-width: 70ch;
}
.k-add__verified {
    color: var(--k-success);
}
.k-add__actions {
    display: flex;
    gap: 6px;
    align-items: center;
    flex-shrink: 0;
}
.k-add__more {
    display: flex;
    justify-content: center;
    margin: 12px 0 0;
}
.k-add__loading {
    display: flex;
    gap: 8px;
    align-items: center;
    color: var(--k-ink-3);
    font-size: 13px;
}
.k-detail {
    display: grid;
    gap: 10px;
}
.k-detail p {
    margin: 0;
}
.k-detail__summary {
    font-size: 14px;
}
.k-detail__h {
    margin: 6px 0 0;
    font-size: 13px;
    font-weight: 650;
}
.k-detail__facts {
    display: grid;
    grid-template-columns: max-content 1fr;
    gap: 4px 14px;
    margin: 0;
    font-size: 13px;
}
.k-detail__facts dt {
    color: var(--k-ink-3);
}
.k-detail__facts dd {
    margin: 0;
    overflow-wrap: anywhere;
}
.k-detail__links {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}
.k-detail__text {
    font-size: 13px;
    color: var(--k-ink-2);
    max-width: 72ch;
    overflow-wrap: anywhere;
}
.k-detail__text :deep(h1),
.k-detail__text :deep(h2),
.k-detail__text :deep(h3),
.k-detail__text :deep(h4) {
    font-size: 13px;
    font-weight: 650;
    color: var(--k-ink);
    margin: 12px 0 4px;
}
.k-detail__text :deep(p),
.k-detail__text :deep(ul),
.k-detail__text :deep(ol) {
    margin: 0 0 8px;
}
.k-detail__text :deep(pre) {
    overflow-x: auto;
    padding: 8px 10px;
    border: 1px solid var(--k-rule);
}
.k-detail__versions {
    list-style: none;
    margin: 0;
    padding: 0;
    display: grid;
    gap: 2px;
    font-size: 13px;
}
.k-detail__withdrawn {
    color: var(--k-danger);
}
@media (max-width: 640px) {
    .k-add__row {
        flex-direction: column;
    }
}
</style>
