<script setup>
import { computed, ref } from 'vue';
import { ApiError, confirm, formatDateTime, formatRelative, notify, useApi, __ } from '@kestello/sdk';
import { KAlert, KBadge, KButton, KCard, KField, KIcon, KModal, KPageHeader } from '@kestello/ui';
import PackageUpdates from '../../../components/updates/PackageUpdates.vue';
import StoppedUpdate from '../../../components/updates/StoppedUpdate.vue';
import { signatureNote } from '../../../components/updates/signature.js';
import { rememberSwitchBack, switchBackOutcome, takeSwitchBack } from '../../../components/updates/switch-back.js';

defineOptions({ layout: 'Admin' });

/**
 * Updates: what runs (Kestello, plugins, themes) and applying a Kestello
 * release, downloaded from GitHub or uploaded. Plugin and theme updates from
 * their own sources are in PackageUpdates. A release is reviewed first; applying it backs up the database,
 * switches to the new release, checks the site, and switches back by itself
 * when anything is wrong. After an update, Switch back goes to the version
 * before it, with the database from before it.
 */
const api = useApi();
const data = ref(null);
const loadError = ref(null);

async function load() {
    try {
        data.value = (await api.get('/updates')).data;
    } catch (e) {
        loadError.value = e;
        return;
    }
    const note = takeSwitchBack();
    if (note?.owner === 'core') {
        const outcome = switchBackOutcome(note, data.value.core.version, data.value.stopped_update);
        if (outcome === 'done') notify.success(__('Kestello {v} is running again, with the database from before the update.', { v: note.version }));
        else if (outcome === 'not-done') notify.error(__('Switching back to Kestello {v} did not finish. The audit log says why.', { v: note.version }));
    }
}
load();

// ── switching back after an update ──────────────────────────────────
async function switchBack() {
    const option = data.value.core.switch_back;
    if (
        !(await confirm({
            title: __('Switch back to Kestello {v}?', { v: option.to }),
            message: __(
                'Kestello {v} is still on the server. Switching back also puts the database back as it was before the update, on {date}: anything changed on the site since then is lost from the site. A backup of the site as it is now is made first, under Site → Backups. Visitors see a short maintenance page.',
                { v: option.to, date: formatDateTime(option.backup_at || option.updated_at) },
            ),
            confirmLabel: __('Switch back to {v}', { v: option.to }),
            danger: true,
        }))
    )
        return;
    busy.value = 'switch-back';
    try {
        await api.post('/updates/core/switch-back');
        rememberSwitchBack('core', 'Kestello', option.to);
        // the next request runs the old release and puts the database back; the page has to come from it too
        window.location.reload();
    } catch (e) {
        notify.error(e);
        busy.value = '';
    }
}

// ── new versions on GitHub ───────────────────────────────────────────
const available = computed(() => data.value?.available ?? null);
const latest = computed(() => available.value?.latest ?? null);

async function checkNow() {
    busy.value = 'check';
    try {
        data.value = (await api.post('/updates/core/check')).data;
    } catch (e) {
        notify.error(e);
    } finally {
        busy.value = '';
    }
}

async function reviewLatest() {
    busy.value = 'download';
    try {
        const result = (await api.post('/updates/core/download', { version: latest.value.version })).data;
        start();
        review.value = result;
    } catch (e) {
        notify.error(e);
    } finally {
        busy.value = '';
    }
}

// ── upload, review, apply ────────────────────────────────────────────
const open = ref(false);
const file = ref(null);
const review = ref(null);
const uploadErrors = ref({});
const busy = ref('');
const failed = ref(null);

function start() {
    open.value = true;
    file.value = null;
    review.value = null;
    uploadErrors.value = {};
    failed.value = null;
}

async function upload() {
    if (!file.value) return;
    busy.value = 'upload';
    uploadErrors.value = {};
    const form = new FormData();
    form.append('file', file.value);
    try {
        review.value = (await api.upload('/updates/core/upload', form)).data;
    } catch (e) {
        if (e instanceof ApiError && e.status === 422) uploadErrors.value = e.fieldErrors();
        else notify.error(e);
    } finally {
        busy.value = '';
    }
}

const release = computed(() => review.value?.release ?? null);
const signature = computed(() => (release.value?.signature ? signatureNote(release.value.signature) : null));
const blocked = computed(() => {
    if (!release.value || release.value.allowed) return '';
    if (release.value.signature.status === 'tampered') return __('This release was changed after it was signed, so it is refused.');
    return __(
        'Only releases signed by a publisher this site trusts are applied from here. A developer can apply this one on the server: bin/cms core:update <zip>.',
    );
});

async function apply() {
    busy.value = 'apply';
    failed.value = null;
    try {
        const response = (await api.post('/updates/core', { token: review.value.token })).data;
        data.value = response;
        if (response.result.ok) {
            open.value = false;
            notify.success(__('Kestello {v} is running.', { v: response.result.to }));
        } else {
            failed.value = response.result;
        }
    } catch (e) {
        notify.error(e);
    } finally {
        busy.value = '';
    }
}
</script>

<template>
    <KPageHeader
        :title="__('Updates')"
        :description="
            __(
                'What this site runs. An update backs up the database first and checks the site before visitors see it; if anything is wrong, the previous version comes back by itself.',
            )
        "
    >
        <template v-if="data" #actions>
            <KButton variant="primary" icon="upload" @click="start">{{ __('Upload a Kestello release') }}</KButton>
        </template>
    </KPageHeader>
    <KAlert v-if="loadError" tone="danger">{{ loadError.message }}</KAlert>
    <StoppedUpdate v-if="data?.stopped_update" :stopped="data.stopped_update" />

    <template v-if="data">
        <KCard :title="__('Kestello')">
            <dl class="k-updates__facts">
                <dt>{{ __('Version') }}</dt>
                <dd class="k-num">{{ data.core.version }}</dd>
                <dt>{{ __('PHP') }}</dt>
                <dd class="k-num">{{ data.php }}</dd>
                <dt>{{ __('On disk') }}</dt>
                <dd v-if="data.core.mode === 'development'">
                    {{ __('A development checkout: core runs from its folder directly. The first update adds release folders next to it.') }}
                </dd>
                <dd v-else>
                    <span v-for="(r, i) in data.core.releases" :key="r.version" class="k-num"
                        >{{ i ? ', ' : '' }}{{ r.version }}<template v-if="r.current"> ({{ __('running') }})</template></span
                    >
                </dd>
            </dl>
            <div class="k-updates__available">
                <template v-if="latest">
                    <p class="k-updates__headline">
                        <strong>{{ __('Kestello {v} is available.', { v: latest.version }) }}</strong>
                        <KBadge v-if="latest.prerelease" tone="warning">{{ __('Pre-release') }}</KBadge>
                    </p>
                    <p class="k-muted k-small">
                        {{ latest.published_at ? __('Published {date}.', { date: formatDateTime(latest.published_at) }) : '' }}
                        <a :href="latest.page" target="_blank" rel="noopener noreferrer">{{ __('Release page on GitHub') }}</a>
                    </p>
                    <!-- notes_html is rendered on the server from Markdown, with raw HTML escaped -->
                    <div v-if="latest.notes_html" class="k-updates__notes k-prose" v-html="latest.notes_html"></div>
                </template>
                <p v-else-if="available && !available.error" class="k-muted k-small">
                    {{ __('Kestello is up to date. Last checked {date}.', { date: formatRelative(available.checked_at) }) }}
                </p>
                <p v-else-if="!available" class="k-muted k-small">{{ __('Kestello has not checked for new versions yet.') }}</p>
                <KAlert v-if="available?.error" tone="warning">{{ available.error }}</KAlert>
                <div class="k-updates__actions">
                    <KButton v-if="latest" variant="primary" icon="download-simple" :loading="busy === 'download'" :disabled="!!busy" @click="reviewLatest">{{
                        __('Review the update')
                    }}</KButton>
                    <KButton icon="arrows-clockwise" :loading="busy === 'check'" :disabled="!!busy" @click="checkNow">{{ __('Check now') }}</KButton>
                </div>
            </div>
            <div v-if="data.core.switch_back" class="k-updates__back">
                <p class="k-small">
                    {{
                        __('Updated from {v} on {date}. Kestello {v} and the backup from before the update are kept, so you can go back to it.', {
                            v: data.core.switch_back.to,
                            date: formatDateTime(data.core.switch_back.updated_at),
                        })
                    }}
                </p>
                <KButton icon="clock-counter-clockwise" :loading="busy === 'switch-back'" :disabled="!!busy || !!data.stopped_update" @click="switchBack">{{
                    __('Switch back to {v}', { v: data.core.switch_back.to })
                }}</KButton>
            </div>
            <KAlert v-if="!data.core.writable" tone="warning">{{
                __('PHP cannot write to the core folder, so Kestello can only be updated by uploading files to the server.')
            }}</KAlert>
        </KCard>

        <PackageUpdates v-model:data="data" />
    </template>

    <KModal
        v-model:open="open"
        :title="release ? __('Update to Kestello {v}?', { v: release.version }) : __('Upload a Kestello release')"
        size="lg"
        :dismissible="busy !== 'apply'"
    >
        <form v-if="!review" id="k-core-upload" class="k-form" @submit.prevent="upload">
            <p class="k-muted k-small">
                {{
                    __(
                        'Choose a kestello-core zip, for a site that cannot reach GitHub. Nothing changes yet: you see the version, who signed it and what it would affect first.',
                    )
                }}
            </p>
            <KField v-slot="{ id }" :label="__('Release zip')" :error="uploadErrors.file ?? ''">
                <input :id="id" type="file" accept=".zip,application/zip" class="k-input" required @change="file = $event.target.files?.[0] ?? null" />
            </KField>
        </form>
        <div v-else class="k-updates__review">
            <p>
                <span class="k-num">{{ release.from }}</span> <KIcon name="arrow-right" /> <strong class="k-num">{{ release.version }}</strong>
            </p>
            <p class="k-updates__sig" :class="`is-${signature.tone}`"><KIcon :name="signature.icon" /> {{ signature.text }}</p>
            <p class="k-muted k-small">
                {{
                    __(
                        'The database is backed up, visitors see a short maintenance page, and the site is checked on the new release: database changes, every active plugin, the home page. If anything is wrong, {v} and the database come back.',
                        { v: release.from },
                    )
                }}
            </p>
            <KAlert v-if="failed" tone="danger" :title="__('The update was not applied')">
                <p v-for="p in failed.problems" :key="p" class="k-updates__problem">{{ p }}</p>
                <p v-if="failed.rolled_back">{{ __('Kestello {v} and the database were put back. The site runs as before.', { v: failed.from }) }}</p>
                <p v-for="p in failed.rollback_problems" v-else :key="p">{{ p }}</p>
            </KAlert>
            <KAlert v-if="blocked" tone="warning">{{ blocked }}</KAlert>
            <KAlert v-else-if="release.problems.length" tone="warning" :title="__('This release cannot be applied')">
                <p v-for="p in release.problems" :key="p" class="k-updates__problem">{{ p }}</p>
            </KAlert>
        </div>
        <template #footer>
            <KButton :disabled="busy === 'apply'" @click="open = false">{{ failed ? __('Close') : __('Cancel') }}</KButton>
            <KButton v-if="!review" type="submit" form="k-core-upload" variant="primary" icon="upload" :loading="busy === 'upload'" :disabled="!file">{{
                __('Upload and review')
            }}</KButton>
            <KButton
                v-else
                variant="primary"
                icon="arrows-clockwise"
                :loading="busy === 'apply'"
                :disabled="!release.allowed || release.problems.length > 0 || !!failed"
                @click="apply"
                >{{ __('Update to {v}', { v: release.version }) }}</KButton
            >
        </template>
    </KModal>
</template>

<style scoped>
.k-updates__facts {
    display: grid;
    grid-template-columns: max-content 1fr;
    gap: 6px 18px;
    margin: 0;
    font-size: 14px;
}
.k-updates__facts dt {
    color: var(--k-ink-3);
}
.k-updates__facts dd {
    margin: 0;
}
.k-updates__available {
    display: grid;
    gap: 8px;
    margin-top: 20px;
}
.k-updates__available p {
    margin: 0;
}
.k-updates__headline {
    display: flex;
    gap: 8px;
    align-items: center;
}
.k-updates__notes {
    max-height: 280px;
    overflow: auto;
    font-size: 14px;
}
.k-updates__actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}
.k-updates__back {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    margin-top: 20px;
    padding-top: 16px;
    border-top: 1px solid var(--k-rule);
}
.k-updates__back p {
    margin: 0;
    color: var(--k-ink-2);
    max-width: 60ch;
}
.k-updates__review {
    display: grid;
    gap: 10px;
}
.k-updates__review p {
    margin: 0;
}
.k-updates__sig {
    display: flex;
    gap: 8px;
    align-items: baseline;
    font-size: 13px;
    padding: 8px 10px;
    border: 1px solid var(--k-rule);
}
.k-updates__sig.is-success {
    color: var(--k-success);
}
.k-updates__sig.is-warning {
    color: var(--k-warning);
}
.k-updates__sig.is-danger {
    color: var(--k-danger);
}
.k-updates__problem {
    overflow-wrap: anywhere;
}
</style>
