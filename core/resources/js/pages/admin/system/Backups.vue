<script setup>
import { computed, reactive, ref } from 'vue';
import { ApiError, confirm, formatBytes, notify, useApi, __ } from '@kestello/sdk';
import { KAlert, KBadge, KButton, KCard, KField, KInput, KModal, KPageHeader, KSelect, KSwitch, KTable, KTime } from '@kestello/ui';

defineOptions({ layout: 'Admin' });

/**
 * System → Backups: back up now, on a schedule, download, and restore here
 * or on another Kestello site (any database: rows travel as JSON, the
 * schema comes from migrations).
 */
const api = useApi();
const data = ref(null);
const loadError = ref(null);
const busy = ref('');

async function load() {
    try {
        data.value = (await api.get('/backups')).data;
        Object.assign(schedule, data.value.settings);
    } catch (e) {
        loadError.value = e;
    }
}
load();

const kinds = {
    manual: __('Made by hand'),
    scheduled: __('Scheduled'),
    'before-restore': __('Before a restore'),
    'before-update': __('Before an update'),
    uploaded: __('From another site'),
};
const latest = computed(() => data.value?.backups?.[0] ?? null);

// ── schedule ─────────────────────────────────────────────────────────
const schedule = reactive({ schedule: 'daily', files: false, keep: 7 });
const scheduleOptions = [
    { value: 'daily', label: __('Every day') },
    { value: 'weekly', label: __('Every week (Sundays)') },
    { value: 'off', label: __('Off') },
];
const scheduleDirty = computed(
    () =>
        data.value &&
        (schedule.schedule !== data.value.settings.schedule ||
            schedule.files !== data.value.settings.files ||
            Number(schedule.keep) !== data.value.settings.keep),
);

async function saveSchedule() {
    busy.value = 'schedule';
    try {
        data.value = (await api.put('/backups/settings', { schedule: schedule.schedule, files: schedule.files, keep: Number(schedule.keep) })).data;
        notify.success(__('Backup schedule saved.'));
    } catch (e) {
        notify.error(e);
    } finally {
        busy.value = '';
    }
}

// ── back up now ──────────────────────────────────────────────────────
const making = ref(false);
const makeForm = reactive({ files: true, note: '' });

async function makeBackup() {
    busy.value = 'create';
    try {
        data.value = (await api.post('/backups', { files: makeForm.files, note: makeForm.note })).data;
        making.value = false;
        notify.success(__('The backup is ready.'));
    } catch (e) {
        notify.error(e);
    } finally {
        busy.value = '';
    }
}

async function download(b) {
    busy.value = `download:${b.name}`;
    try {
        await api.download(`/backups/${b.name}/download`, b.name);
    } catch (e) {
        notify.error(e);
    } finally {
        busy.value = '';
    }
}

async function remove(b) {
    if (
        !(await confirm({
            title: __('Delete this backup?'),
            message: __('The backup from {date} is deleted for good.', { date: new Date(b.created_at * 1000).toLocaleString() }),
            confirmLabel: __('Delete backup'),
            danger: true,
        }))
    )
        return;
    busy.value = `delete:${b.name}`;
    try {
        data.value = (await api.delete(`/backups/${b.name}`)).data;
        notify.success(__('The backup was deleted.'));
    } catch (e) {
        notify.error(e);
    } finally {
        busy.value = '';
    }
}

// ── upload ───────────────────────────────────────────────────────────
const uploading = ref(false);
const uploadFile = ref(null);
const uploadErrors = ref({});

async function upload() {
    if (!uploadFile.value) return;
    busy.value = 'upload';
    uploadErrors.value = {};
    const form = new FormData();
    form.append('file', uploadFile.value);
    try {
        data.value = (await api.upload('/backups/upload', form)).data;
        uploading.value = false;
        notify.success(__('The backup was added. Restore it when you are ready.'));
    } catch (e) {
        if (e instanceof ApiError && e.status === 422) uploadErrors.value = e.fieldErrors();
        else notify.error(e);
    } finally {
        busy.value = '';
    }
}

// ── restore ──────────────────────────────────────────────────────────
const restoring = ref(null);
const confirmName = ref('');
const restoreErrors = ref({});
const restored = ref(null);

function openRestore(b) {
    restoring.value = b;
    confirmName.value = '';
    restoreErrors.value = {};
}

async function restore() {
    busy.value = 'restore';
    restoreErrors.value = {};
    try {
        restored.value = (await api.post(`/backups/${restoring.value.name}/restore`, { confirm: confirmName.value })).data.report;
        restoring.value = null;
    } catch (e) {
        if (e instanceof ApiError && e.status === 422) restoreErrors.value = e.fieldErrors();
        else notify.error(e);
    } finally {
        busy.value = '';
    }
}
// (an ICU plural's closing braces would end a {{ }} in the template)
const rowsText = (n) => __('{n, plural, one {# row} other {# rows}}', { n });
</script>

<template>
    <KPageHeader
        :title="__('Backups')"
        :description="
            __(
                'A backup holds the whole site: content, settings, people and, when you choose, its files. Restore it here, or on another Kestello site to move there.',
            )
        "
    >
        <template v-if="data && !restored" #actions>
            <KButton
                icon="upload"
                @click="
                    uploading = true;
                    uploadFile = null;
                    uploadErrors = {};
                "
                >{{ __('Upload a backup') }}</KButton
            >
            <KButton
                variant="primary"
                icon="archive"
                @click="
                    making = true;
                    makeForm.files = true;
                    makeForm.note = '';
                "
                >{{ __('Back up now') }}</KButton
            >
        </template>
    </KPageHeader>
    <KAlert v-if="loadError" tone="danger">{{ loadError.message }}</KAlert>

    <template v-if="restored">
        <KAlert tone="success" :title="__('The site was restored')">
            {{
                __('{rows} rows in {tables} tables came back. Everyone was signed out, you too: sign in with an account from the backup.', {
                    rows: restored.rows,
                    tables: restored.tables,
                })
            }}
        </KAlert>
        <KAlert v-if="restored.skipped_code" tone="warning">{{
            __(
                'Plugin and theme folders were not restored: the backup was made on another site, and code from elsewhere is only restored from the command line (bin/cms backup:restore --with-code).',
            )
        }}</KAlert>
        <KAlert v-if="restored.missing_tables.length" tone="warning">{{
            __('Some rows were left out because their plugin is not installed here: {tables}.', { tables: restored.missing_tables.join(', ') })
        }}</KAlert>
        <KAlert v-for="w in restored.warnings" :key="w" tone="warning">{{ w }}</KAlert>
        <div class="k-backups__after">
            <KButton variant="primary" href="/login" icon="sign-in">{{ __('Sign in again') }}</KButton>
        </div>
    </template>

    <template v-else-if="data">
        <KCard :title="__('Schedule')" class="k-backups__schedule">
            <form class="k-backups__form" @submit.prevent="saveSchedule">
                <KField v-slot="{ id }" :label="__('Back up automatically')">
                    <KSelect :id="id" v-model="schedule.schedule" :options="scheduleOptions" />
                </KField>
                <KField v-slot="{ id }" :label="__('Keep')">
                    <KInput :id="id" v-model="schedule.keep" type="number" min="1" max="100" class="k-backups__keep" />
                </KField>
                <KSwitch v-model="schedule.files" :label="__('Include files (uploads, plugins, themes)')" :disabled="schedule.schedule === 'off'" />
                <KButton type="submit" variant="primary" :disabled="!scheduleDirty" :loading="busy === 'schedule'">{{ __('Save') }}</KButton>
            </form>
            <p class="k-muted k-small k-backups__where">
                {{ __('Keeps that many automatic backups; the oldest go first. Backups made by hand stay until you delete them.') }}
                {{
                    data.disk.driver === 'local'
                        ? __(
                              'Backups are kept in storage/app/backups on this server. To keep copies elsewhere, point the backups disk at S3-compatible storage in config/storage.php.',
                          )
                        : __('Backups are kept on the backups disk ({driver}).', { driver: data.disk.driver })
                }}
                <template v-if="latest"> {{ __('Newest:') }} <KTime :value="latest.created_at" />.</template>
            </p>
        </KCard>

        <KCard flush>
            <KTable
                :columns="[
                    { key: 'made', label: __('Made') },
                    { key: 'contents', label: __('Contents') },
                    { key: 'size', label: __('Size'), align: 'end' },
                    { key: 'actions', label: '', align: 'end', width: '1%' },
                ]"
                :rows="data.backups"
                row-key="name"
                :empty="__('No backups yet')"
                :empty-text="__('Make the first one with Back up now.')"
            >
                <template #cell-made="{ row }">
                    <KTime :value="row.created_at" />
                    <div class="k-small k-backups__kind">
                        <KBadge :tone="row.kind === 'uploaded' ? 'accent' : 'neutral'">{{ kinds[row.kind] ?? row.kind }}</KBadge>
                        <span v-if="row.note" class="k-muted">{{ row.note }}</span>
                    </div>
                </template>
                <template #cell-contents="{ row }">
                    {{ row.files ? __('Database and files') : __('Database only') }}
                    <div class="k-muted k-small">
                        <span class="k-num">{{ rowsText(row.rows) }}</span
                        ><template v-if="row.driver"> · {{ row.driver }}</template
                        ><template v-if="row.core"> · Kestello {{ row.core }}</template>
                    </div>
                </template>
                <template #cell-size="{ row }"
                    ><span class="k-num">{{ formatBytes(row.size) }}</span></template
                >
                <template #cell-actions="{ row }">
                    <div class="k-backups__actions">
                        <KButton size="sm" icon="arrow-counter-clockwise" @click="openRestore(row)">{{ __('Restore') }}</KButton>
                        <KButton
                            size="sm"
                            variant="ghost"
                            icon="download"
                            :loading="busy === `download:${row.name}`"
                            :aria-label="__('Download')"
                            @click="download(row)"
                        />
                        <KButton
                            size="sm"
                            variant="ghost"
                            icon="trash"
                            :loading="busy === `delete:${row.name}`"
                            :aria-label="__('Delete')"
                            @click="remove(row)"
                        />
                    </div>
                </template>
            </KTable>
        </KCard>
    </template>

    <KModal v-model:open="making" :title="__('Back up now')">
        <form id="k-backup-new" class="k-form" @submit.prevent="makeBackup">
            <KSwitch v-model="makeForm.files" :label="__('Include files (uploads, plugins, themes)')" />
            <p class="k-muted k-small k-backups__note">
                {{
                    makeForm.files
                        ? __('Everything needed to move the site. Files on remote storage (S3 and the like) are not copied.')
                        : __('Content, settings and people. Smaller and quicker; files stay as they are when restoring.')
                }}
            </p>
            <KField v-slot="{ id }" :label="__('Note')" :help="__('To find it again later, such as: before the redesign.')">
                <KInput :id="id" v-model="makeForm.note" maxlength="200" />
            </KField>
        </form>
        <template #footer>
            <KButton @click="making = false">{{ __('Cancel') }}</KButton>
            <KButton type="submit" form="k-backup-new" variant="primary" icon="archive" :loading="busy === 'create'">{{ __('Back up') }}</KButton>
        </template>
    </KModal>

    <KModal v-model:open="uploading" :title="__('Upload a backup')">
        <form id="k-backup-upload" class="k-form" @submit.prevent="upload">
            <p class="k-muted k-small k-backups__note">
                {{ __('A backup zip from another Kestello site, on any database. It is checked, then listed here to restore.') }}
            </p>
            <KField v-slot="{ id }" :label="__('Backup zip')" :error="uploadErrors.file ?? ''">
                <input :id="id" type="file" accept=".zip,application/zip" class="k-input" required @change="uploadFile = $event.target.files?.[0] ?? null" />
            </KField>
        </form>
        <template #footer>
            <KButton @click="uploading = false">{{ __('Cancel') }}</KButton>
            <KButton type="submit" form="k-backup-upload" variant="primary" icon="upload" :loading="busy === 'upload'" :disabled="!uploadFile">{{
                __('Upload')
            }}</KButton>
        </template>
    </KModal>

    <KModal :open="!!restoring" :title="__('Restore this backup?')" @close="restoring = null">
        <form v-if="restoring" id="k-backup-restore" class="k-form" @submit.prevent="restore">
            <KAlert tone="danger">
                {{
                    restoring.files
                        ? __(
                              'Everything on the site is replaced with the backup from {date}: content, settings, people and files. Everyone is signed out, you too.',
                              {
                                  date: new Date(restoring.created_at * 1000).toLocaleString(),
                              },
                          )
                        : __('Everything on the site is replaced with the backup from {date}: content, settings and people. Everyone is signed out, you too.', {
                              date: new Date(restoring.created_at * 1000).toLocaleString(),
                          })
                }}
            </KAlert>
            <p class="k-muted k-small k-backups__note">
                {{ __('The site as it is now is backed up first, and put back by itself if the restore fails.') }}
                <template v-if="restoring.kind === 'uploaded'">
                    {{
                        __(
                            'This backup comes from another site: you sign in with its accounts afterwards, and its plugin and theme folders are only restored from the command line.',
                        )
                    }}</template
                >
            </p>
            <KField v-slot="{ id }" :label="__('Type the site name, {name}, to confirm', { name: data.site_name })" :error="restoreErrors.confirm ?? ''">
                <KInput :id="id" v-model="confirmName" autocomplete="off" required />
            </KField>
        </form>
        <template #footer>
            <KButton @click="restoring = null">{{ __('Cancel') }}</KButton>
            <KButton
                type="submit"
                form="k-backup-restore"
                variant="danger"
                icon="arrow-counter-clockwise"
                :loading="busy === 'restore'"
                :disabled="confirmName.trim() !== data?.site_name"
                >{{ __('Restore') }}</KButton
            >
        </template>
    </KModal>
</template>

<style scoped>
.k-backups__schedule {
    margin-bottom: 14px;
}
.k-backups__form {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: 12px 18px;
}
.k-backups__form :deep(.k-field) {
    margin: 0;
}
.k-backups__form > :deep(.k-switch) {
    min-height: 36px;
}
.k-backups__form > :deep(.k-btn) {
    height: 36px;
} /* the height of the inputs, so all controls share one line */
.k-backups__keep {
    width: 6rem;
}
.k-backups__where {
    margin: 12px 0 0;
}
.k-backups__kind {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-top: 2px;
}
.k-backups__actions {
    display: flex;
    align-items: center;
    gap: 4px;
    justify-content: flex-end;
}
.k-backups__note {
    margin: -4px 0 0;
}
.k-backups__after {
    margin-top: 14px;
}
</style>
