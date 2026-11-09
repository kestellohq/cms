<script setup>
import { computed, ref } from 'vue';
import { ApiError, formatDateTime, formatRelative, notify, useApi, __ } from '@kestello/sdk';
import { KAlert, KBadge, KButton, KCard, KField, KIcon, KModal, KTable } from '@kestello/ui';
import { signatureNote } from './signature.js';

/**
 * Plugins and themes on the Updates screen: their versions, and, for those whose manifest says
 * where their updates come from (a GitHub repository or the vendor's feed), what the last check
 * found, the license key a paid one needs, and review → update through the same pipeline as
 * uploads (backup, health check, automatic rollback). `data` is GET /updates; every action
 * answers with a fresh copy, given back through `update:data`.
 */
const props = defineProps({ data: { type: Object, required: true } });
const emit = defineEmits(['update:data']);
const api = useApi();
const busy = ref('');

const rows = computed(() => [
    ...(props.data.plugins ?? []).map((p) => ({ ...p, owner: `plugin:${p.slug}`, kind: __('Plugin') })),
    ...(props.data.themes ?? []).map((t) => ({ ...t, owner: `theme:${t.slug}`, kind: __('Theme') })),
]);
// the ones with an update first, then by name
const sorted = computed(() => [...rows.value].sort((a, b) => Number(!!b.update?.offer) - Number(!!a.update?.offer) || a.name.localeCompare(b.name)));
const anySource = computed(() => rows.value.some((r) => r.source));

async function send(key, request) {
    busy.value = key;
    try {
        const answer = (await request()).data;
        emit('update:data', answer);
        return answer;
    } catch (e) {
        if (e instanceof ApiError && e.status === 422) notify.error(Object.values(e.fieldErrors()).flat()[0] ?? e.message);
        else notify.error(e);
        return null;
    } finally {
        busy.value = '';
    }
}

const checkAll = () => send('check', () => api.post('/updates/packages/check'));

// ── license keys ─────────────────────────────────────────────────────
const licenseFor = ref(null);
const licenseKey = ref('');
function editLicense(row) {
    licenseFor.value = row;
    licenseKey.value = '';
}
async function saveLicense() {
    const row = licenseFor.value;
    const answer = await send('license', () => api.put('/updates/packages/license', { owner: row.owner, key: licenseKey.value }));
    licenseKey.value = '';
    if (!answer) return;
    licenseFor.value = null;
    const [type, slug] = row.owner.split(':');
    const now = (type === 'plugin' ? answer.plugins : answer.themes).find((p) => p.slug === slug);
    if (now?.update?.error) notify.error(now.update.error);
    else notify.success(__('The license key for {name} was saved.', { name: row.name }));
}
async function removeLicense() {
    const row = licenseFor.value;
    if (await send('license', () => api.post('/updates/packages/license/remove', { owner: row.owner }))) {
        licenseFor.value = null;
        notify.success(__('The license key for {name} was removed.', { name: row.name }));
    }
}

// ── review and update ────────────────────────────────────────────────
const review = ref(null);
const reviewing = ref(null);
const failed = ref(null);
const reviewOpen = computed({
    get: () => review.value !== null,
    set: (open) => {
        if (!open) review.value = null;
    },
});
const pkg = computed(() => review.value?.package ?? null);
const signature = computed(() => (pkg.value ? signatureNote(pkg.value.signature) : null));
const unsignedNote = __(
    'Only updates signed by a publisher this site trusts are applied from here. A developer can apply this one on the server: bin/cms package:update <zip>.',
);

async function startReview(row) {
    busy.value = `review:${row.owner}`;
    failed.value = null;
    try {
        reviewing.value = row;
        review.value = (await api.post('/updates/packages/download', { owner: row.owner })).data;
    } catch (e) {
        notify.error(e);
    } finally {
        busy.value = '';
    }
}

async function apply() {
    failed.value = null;
    const answer = await send('apply', () => api.post('/updates/packages/update', { token: review.value.token, owner: review.value.owner }));
    if (!answer) return;
    if (answer.result.ok) {
        review.value = null;
        notify.success(__('{name} {v} is running.', { name: reviewing.value.name, v: answer.result.to }));
    } else {
        failed.value = answer.result;
    }
}
</script>

<template>
    <KCard flush class="k-pkg-updates" :title="__('Plugins and themes')">
        <template v-if="anySource" #actions>
            <KButton
                size="sm"
                icon="arrows-clockwise"
                :aria-label="__('Check plugins and themes for updates')"
                :loading="busy === 'check'"
                :disabled="!!busy"
                @click="checkAll"
                >{{ __('Check now') }}</KButton
            >
        </template>
        <KTable
            :columns="[
                { key: 'name', label: __('Name') },
                { key: 'version', label: __('Version'), align: 'end', width: '5.5rem' },
                { key: 'status', label: __('Updates') },
                { key: 'actions', label: '', align: 'end' },
            ]"
            :rows="sorted"
            row-key="owner"
            :empty="__('No plugins or themes installed.')"
        >
            <template #cell-name="{ row }">
                {{ row.name }} <span class="k-muted k-small">{{ row.kind }}</span>
                <KBadge v-if="row.active" tone="success" class="k-pkg-updates__active">{{ __('Active') }}</KBadge>
            </template>
            <template #cell-version="{ row }"
                ><span class="k-num">{{ row.version }}</span></template
            >
            <template #cell-status="{ row }">
                <span v-if="!row.source" class="k-muted k-small">{{ __('By upload') }}</span>
                <template v-else-if="row.update?.offer">
                    <KBadge tone="accent">{{ __('{v} available', { v: row.update.offer.version }) }}</KBadge>
                </template>
                <span v-else-if="row.license.required && !row.license.set" class="k-small">{{ __('Needs a license key') }}</span>
                <span v-else-if="row.update?.error" class="k-pkg-updates__error k-small">
                    {{ row.update.error }}
                    <a v-if="row.update.renew_url" :href="row.update.renew_url" target="_blank" rel="noopener noreferrer">{{ __('Renew') }}</a>
                </span>
                <span v-else-if="row.update" class="k-muted k-small">{{
                    __('Up to date, checked {date}', { date: formatRelative(row.update.checked_at) })
                }}</span>
                <span v-else class="k-muted k-small">{{ __('Not checked yet') }}</span>
                <span v-if="row.update?.needs_core" class="k-muted k-small k-pkg-updates__needs">{{
                    __('{v} needs Kestello {core}', { v: row.update.needs_core.version, core: row.update.needs_core.requires_core })
                }}</span>
            </template>
            <template #cell-actions="{ row }">
                <div class="k-pkg-updates__actions">
                    <KButton v-if="row.license.required" size="sm" variant="ghost" icon="key" @click="editLicense(row)">{{
                        row.license.set ? __('Key …{last4}', { last4: row.license.last4 }) : __('Add license key')
                    }}</KButton>
                    <KButton
                        v-if="row.update?.offer"
                        size="sm"
                        variant="primary"
                        icon="download-simple"
                        :loading="busy === `review:${row.owner}`"
                        :disabled="!!busy || !!data.stopped_update"
                        @click="startReview(row)"
                        >{{ __('Review') }}</KButton
                    >
                </div>
            </template>
        </KTable>
    </KCard>
    <p class="k-muted k-small k-pkg-updates__note">
        {{
            __(
                'Plugins and themes that say where their updates come from are updated here. Others are updated by uploading the new version under Plugins → Manage plugins, where you can also switch back after an update.',
            )
        }}
    </p>

    <KModal
        :open="licenseFor !== null"
        :title="licenseFor ? __('License key for {name}', { name: licenseFor.name }) : ''"
        :dismissible="busy !== 'license'"
        @update:open="(o) => !o && (licenseFor = null)"
    >
        <form v-if="licenseFor" id="k-license" class="k-form" autocomplete="off" @submit.prevent="saveLicense">
            <p class="k-muted k-small">
                {{
                    __(
                        'The key is sent only to {name}’s own update server, to look for and download new versions. It is stored encrypted and not shown again.',
                        { name: licenseFor.name },
                    )
                }}
            </p>
            <p v-if="licenseFor.license.set" class="k-small">{{ __('A key ending in {last4} is saved.', { last4: licenseFor.license.last4 }) }}</p>
            <KField v-slot="{ id }" :label="licenseFor.license.set ? __('Replace with') : __('License key')">
                <input :id="id" v-model="licenseKey" class="k-input k-num" spellcheck="false" autocapitalize="off" maxlength="500" required />
            </KField>
        </form>
        <template #footer>
            <KButton v-if="licenseFor?.license.set" variant="ghost" class="k-pkg-updates__remove" :disabled="!!busy" @click="removeLicense">{{
                __('Remove key')
            }}</KButton>
            <KButton :disabled="busy === 'license'" @click="licenseFor = null">{{ __('Cancel') }}</KButton>
            <KButton type="submit" form="k-license" variant="primary" :loading="busy === 'license'" :disabled="!licenseKey.trim()">{{
                __('Save and check')
            }}</KButton>
        </template>
    </KModal>

    <KModal
        v-model:open="reviewOpen"
        :title="pkg ? __('Update {name} to {v}?', { name: pkg.name, v: pkg.version }) : ''"
        size="lg"
        :dismissible="busy !== 'apply'"
    >
        <div v-if="pkg" class="k-pkg-updates__review">
            <p>
                <span class="k-num">{{ pkg.installed?.version }}</span> <KIcon name="arrow-right" /> <strong class="k-num">{{ pkg.version }}</strong>
                <span v-if="pkg.author" class="k-muted k-small"> · {{ __('by {author}', { author: pkg.author }) }}</span>
            </p>
            <p class="k-pkg-updates__sig" :class="`is-${signature.tone}`"><KIcon :name="signature.icon" /> {{ signature.text }}</p>
            <!-- notes_html is rendered on the server from Markdown, with raw HTML escaped -->
            <div v-if="reviewing?.update?.offer?.notes_html" class="k-pkg-updates__notes k-prose" v-html="reviewing.update.offer.notes_html"></div>
            <p v-if="reviewing?.update?.offer?.published_at" class="k-muted k-small">
                {{ __('Published {date}.', { date: formatDateTime(reviewing.update.offer.published_at) }) }}
            </p>
            <p class="k-muted k-small">
                {{
                    __(
                        'The database is backed up, visitors see a short maintenance page, and the site is checked with the new version. If anything is wrong, {v} and the database come back.',
                        { v: pkg.installed?.version },
                    )
                }}
            </p>
            <KAlert v-if="failed" tone="danger" :title="__('The update was not applied')">
                <p v-for="p in failed.problems" :key="p" class="k-pkg-updates__problem">{{ p }}</p>
                <p v-if="failed.rolled_back">
                    {{ __('{name} {v} and the database were put back. The site runs as before.', { name: pkg.name, v: failed.from }) }}
                </p>
                <p v-for="p in failed.rollback_problems" v-else :key="p">{{ p }}</p>
            </KAlert>
            <KAlert v-if="pkg.problems.length" tone="warning" :title="__('This update cannot be applied')">
                <p v-for="p in pkg.problems" :key="p" class="k-pkg-updates__problem">{{ p }}</p>
            </KAlert>
            <KAlert v-else-if="!review.allowed" tone="warning">{{ unsignedNote }}</KAlert>
        </div>
        <template #footer>
            <KButton :disabled="busy === 'apply'" @click="review = null">{{ failed ? __('Close') : __('Cancel') }}</KButton>
            <KButton v-if="pkg" variant="primary" icon="arrows-clockwise" :loading="busy === 'apply'" :disabled="!review.allowed || !!failed" @click="apply">{{
                __('Update to {v}', { v: pkg.version })
            }}</KButton>
        </template>
    </KModal>
</template>

<style scoped>
.k-pkg-updates {
    margin-top: 14px;
}
.k-pkg-updates__active {
    margin-left: 6px;
}
.k-pkg-updates__remove {
    margin-right: auto;
}
.k-pkg-updates__error {
    color: var(--k-warning);
    overflow-wrap: anywhere;
}
.k-pkg-updates__needs {
    display: block;
}
.k-pkg-updates__actions {
    display: inline-flex;
    gap: 6px;
    justify-content: flex-end;
    flex-wrap: wrap;
}
.k-pkg-updates__note {
    margin: 8px 0 0;
}
.k-pkg-updates__review {
    display: grid;
    gap: 10px;
}
.k-pkg-updates__review p {
    margin: 0;
}
.k-pkg-updates__notes {
    max-height: 240px;
    overflow: auto;
    font-size: 14px;
}
.k-pkg-updates__sig {
    display: flex;
    gap: 8px;
    align-items: baseline;
    font-size: 13px;
    padding: 8px 10px;
    border: 1px solid var(--k-rule);
}
.k-pkg-updates__sig.is-success {
    color: var(--k-success);
}
.k-pkg-updates__sig.is-warning {
    color: var(--k-warning);
}
.k-pkg-updates__sig.is-danger {
    color: var(--k-danger);
}
.k-pkg-updates__problem {
    overflow-wrap: anywhere;
}
</style>
