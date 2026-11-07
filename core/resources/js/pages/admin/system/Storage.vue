<script setup>
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { Head } from '@kestello/app';
import { ApiError, confirm, formatBytes, notify, useApi, __ } from '@kestello/sdk';
import { KAlert, KBadge, KButton, KCard, KField, KInput, KModal, KPageHeader, KSwitch, KTable } from '@kestello/ui';

defineOptions({ layout: 'Admin' });

/** System → File storage: where media files live, moving them, upload quotas per role. */
const api = useApi();
const data = ref(null);
const loadError = ref(null);

async function load() {
    try {
        const body = await api.get('/system/storage');
        data.value = body.data;
        loadError.value = null;
    } catch (e) {
        loadError.value = e;
    }
}
load();

const DRIVERS = {
    local: __('This server'),
    s3: __('S3-compatible bucket'),
    scoped: __('Folder of another disk'),
    mirror: __('Two disks at once'),
    cached: __('Remembered metadata'),
    missing: __('Not configured'),
};

const columns = [
    { key: 'name', label: __('Disk') },
    { key: 'driver', label: __('Kind') },
    { key: 'files', label: __('Files'), align: 'end' },
    { key: 'bytes', label: __('Size'), align: 'end' },
    { key: 'actions', label: '', align: 'end' },
];
const disks = computed(() => data.value?.disks ?? []);
const move = computed(() => data.value?.move ?? null);
const running = computed(() => move.value?.status === 'running');
const progress = computed(() => (move.value && move.value.total ? Math.round((move.value.done / move.value.total) * 100) : 0));
const failures = computed(() => Object.entries(move.value?.failed ?? {}));

// ── moving ───────────────────────────────────────────────────────────
const target = ref(null);
const keepSource = ref(false);
const starting = ref(false);
const moveError = ref('');

function askMove(disk) {
    target.value = disk;
    keepSource.value = false;
    moveError.value = '';
}

async function startMove() {
    starting.value = true;
    moveError.value = '';
    try {
        const body = await api.post('/system/storage/move', { to: target.value.name, keep_source: keepSource.value });
        data.value = body.data;
        target.value = null;
        notify.success(__('Moving started. New uploads already go to {disk}.', { disk: body.data.current }));
    } catch (e) {
        if (e instanceof ApiError && e.status === 422) moveError.value = e.fieldErrors().to ?? e.message;
        else notify.error(e);
    } finally {
        starting.value = false;
    }
}

async function stopMove() {
    if (
        !(await confirm({
            title: __('Stop moving files?'),
            message: __('Files already moved stay on the new disk and keep working. Starting the move again carries on where it stopped.'),
            confirmLabel: __('Stop'),
        }))
    )
        return;
    try {
        data.value = (await api.delete('/system/storage/move')).data;
    } catch (e) {
        notify.error(e);
    }
}

// Without a queue worker this screen does the moving, a slice at a time,
// while it is open. With one, it only watches.
let alive = true;
let timer = null;
let stepping = false;
let failedSteps = 0;
async function tick() {
    if (!alive || !running.value) return;
    try {
        if (data.value.worker) {
            await load();
        } else if (!stepping) {
            stepping = true;
            data.value = (await api.post('/system/storage/move/step')).data;
        }
        failedSteps = 0;
    } catch (e) {
        // try again, more slowly: one failed request must not stop the move for good
        failedSteps++;
        if (failedSteps === 1) notify.error(e);
        stepping = false;
        if (alive && running.value) timer = setTimeout(tick, Math.min(30000, 1000 * 2 ** failedSteps));
        return;
    } finally {
        stepping = false;
    }
    if (alive && running.value) timer = setTimeout(tick, data.value.worker ? 3000 : 50);
    else if (move.value?.status === 'done')
        notify.success(
            failures.value.length ? __('Moving finished; some files could not be moved.') : __('Every file is on {disk} now.', { disk: move.value.to }),
        );
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

// ── quotas ───────────────────────────────────────────────────────────
const quotas = reactive({});
const quotaErrors = ref({});
const savingQuotas = ref(false);
// filled from what is stored; the move's progress updates replace data many times
// a second, so a field is refilled only while it still shows the stored value
watch(
    data,
    (d, before) => {
        if (!d) return;
        for (const r of d.roles) {
            const was = before?.quotas?.[r.name] ?? '';
            if (!before || !(r.name in quotas) || String(quotas[r.name] ?? '') === String(was)) quotas[r.name] = d.quotas[r.name] ?? '';
        }
    },
    { immediate: true },
);

async function saveQuotas() {
    savingQuotas.value = true;
    quotaErrors.value = {};
    const body = Object.fromEntries(Object.entries(quotas).map(([role, mb]) => [role, mb === '' || mb === null ? null : Number(mb)]));
    try {
        data.value = (await api.patch('/system/storage/quotas', { quotas: body })).data;
        notify.success(__('Quotas saved.'));
    } catch (e) {
        if (e instanceof ApiError && e.status === 422)
            quotaErrors.value = Object.fromEntries(Object.entries(e.fieldErrors()).map(([k, v]) => [k.replace(/^quotas\./, ''), v]));
        else notify.error(e);
    } finally {
        savingQuotas.value = false;
    }
}
</script>

<template>
    <Head :title="__('File storage')" />
    <KPageHeader
        :title="__('File storage')"
        :description="
            __(
                'Where the media library keeps its files. Add disks such as an S3 or R2 bucket in config/storage.php, then move the library there from this screen.',
            )
        "
    />
    <KAlert v-if="loadError" tone="danger">{{ loadError.message }}</KAlert>

    <template v-if="data">
        <KCard
            v-if="(move && move.status !== 'done') || (move && failures.length)"
            coord="S1"
            :title="
                running
                    ? __('Moving files to {disk}', { disk: move.to })
                    : move.status === 'stopped'
                      ? __('Moving to {disk} stopped', { disk: move.to })
                      : __('Moved to {disk}', { disk: move.to })
            "
        >
            <template v-if="running" #actions>
                <KButton size="sm" icon="stop-circle" @click="stopMove">{{ __('Stop') }}</KButton>
            </template>
            <div class="k-move">
                <div class="k-move__bar" role="progressbar" :aria-valuenow="progress" aria-valuemin="0" aria-valuemax="100" :aria-label="__('Files moved')">
                    <span :style="{ width: `${progress}%` }"></span>
                </div>
                <p class="k-move__count">
                    <strong class="k-num">{{ move.done.toLocaleString() }}</strong> {{ __('of {total} moved', { total: move.total.toLocaleString() }) }}
                    <template v-if="running && move.remaining"> · {{ __('{n} left', { n: move.remaining.toLocaleString() }) }}</template>
                </p>
                <p v-if="running" class="k-muted k-small">
                    {{
                        data.worker
                            ? __('The background worker is moving them. You can leave this screen.')
                            : __(
                                  'No background worker runs on this site, so this screen moves the files while it stays open. Closing it pauses the move; opening it again carries on.',
                              )
                    }}
                    {{ move.keep_source ? __('Copies stay on the old disk.') : __('Each file is removed from the old disk once its copy is checked.') }}
                </p>
                <p v-else-if="move.status === 'stopped'" class="k-muted k-small">
                    {{ __('Files already moved work from the new disk. Start the move again to carry on.') }}
                </p>
                <div v-if="failures.length" class="k-move__failed">
                    <p class="k-small">
                        <strong>{{
                            failures.length === 1 ? __('1 file stayed where it was:') : __('{n} files stayed where they were:', { n: failures.length })
                        }}</strong>
                    </p>
                    <ul class="k-small">
                        <li v-for="[id, why] in failures" :key="id">{{ why }}</li>
                    </ul>
                    <p class="k-muted k-small">{{ __('They still work from the old disk. Moving to {disk} again retries them.', { disk: move.to }) }}</p>
                </div>
            </div>
        </KCard>

        <KCard coord="S2" :title="__('Disks')" flush>
            <KTable :columns="columns" :rows="disks" :empty="__('No disks can hold media.')">
                <template #cell-name="{ row }">
                    <span class="k-mono">{{ row.name }}</span>
                    <KBadge v-if="row.current" tone="accent" class="k-disk__badge">{{ __('New uploads') }}</KBadge>
                    <div v-if="row.problem" class="k-muted k-small k-disk__problem">{{ row.problem }}</div>
                </template>
                <template #cell-driver="{ row }">{{ DRIVERS[row.driver] ?? row.driver }}</template>
                <template #cell-files="{ row }"
                    ><span class="k-num">{{ row.files.toLocaleString() }}</span></template
                >
                <template #cell-bytes="{ row }"
                    ><span class="k-num">{{ formatBytes(row.bytes) }}</span></template
                >
                <template #cell-actions="{ row }">
                    <KButton
                        v-if="!row.problem && (!row.current || disks.some((d) => !d.current && d.files))"
                        size="sm"
                        variant="ghost"
                        icon="download-simple"
                        :disabled="running"
                        @click="askMove(row)"
                        >{{ __('Move everything here') }}</KButton
                    >
                </template>
            </KTable>
            <p class="k-muted k-small k-disk__note">
                {{
                    __(
                        'Pages build file addresses from the library, so moving changes no content. Each file is checked on the new disk before the library points at it.',
                    )
                }}
            </p>
        </KCard>

        <KCard
            coord="S3"
            :title="__('Upload quotas')"
            :description="__('How much each role may keep in the media library. Administrators are never limited. Leave a field empty for no limit.')"
        >
            <form class="k-quotas" @submit.prevent="saveQuotas">
                <p v-if="!data.roles.length" class="k-muted k-small">{{ __('No role other than administrators can upload.') }}</p>
                <KField v-for="r in data.roles" :key="r.name" v-slot="{ id, invalid }" :label="r.label" :error="quotaErrors[r.name] ?? ''">
                    <span class="k-quotas__input">
                        <KInput :id="id" v-model="quotas[r.name]" type="number" min="1" max="10000000" inputmode="numeric" :invalid="invalid" />
                        <span class="k-muted k-small">MB</span>
                    </span>
                </KField>
                <div v-if="data.roles.length">
                    <KButton type="submit" variant="primary" icon="check" :loading="savingQuotas">{{ __('Save quotas') }}</KButton>
                </div>
            </form>
        </KCard>
    </template>

    <KModal :open="!!target" :title="target ? __('Move everything to {disk}?', { disk: target.name }) : ''" @close="target = null">
        <div v-if="target" class="k-form">
            <p>
                {{
                    __(
                        'New uploads go to {disk} straight away. Existing files follow one by one, with their sizes and thumbnails; each is checked there before pages use it.',
                        { disk: target.name },
                    )
                }}
            </p>
            <KSwitch v-model="keepSource" :label="__('Keep the copies on the old disk')" />
            <p class="k-muted k-small">
                {{
                    keepSource
                        ? __('Useful as a backup until you have checked the new disk. They take space until you delete them.')
                        : __('The old copy of each file is removed once its new copy is checked.')
                }}
            </p>
            <KAlert v-if="moveError" tone="danger">{{ moveError }}</KAlert>
        </div>
        <template #footer>
            <KButton @click="target = null">{{ __('Cancel') }}</KButton>
            <KButton variant="primary" icon="download-simple" :loading="starting" @click="startMove">{{ __('Start moving') }}</KButton>
        </template>
    </KModal>
</template>

<style scoped>
.k-move {
    display: grid;
    gap: 10px;
}
.k-move__bar {
    height: 8px;
    border: 1px solid var(--k-rule);
    background: var(--k-sheet);
    overflow: hidden;
}
.k-move__bar span {
    display: block;
    height: 100%;
    background: var(--k-mark);
    transition: width 300ms ease-out;
}
.k-move__count {
    margin: 0;
    font-size: 15px;
}
.k-move p {
    margin: 0;
}
.k-move__failed {
    padding-top: 10px;
    border-top: 1px dashed var(--k-rule);
}
.k-move__failed ul {
    margin: 6px 0;
    padding-left: 18px;
    display: grid;
    gap: 3px;
    overflow-wrap: anywhere;
}
.k-num {
    font-variant-numeric: tabular-nums;
}
.k-disk__badge {
    margin-left: 8px;
}
.k-disk__problem {
    max-width: 52ch;
    margin-top: 2px;
}
.k-disk__note {
    margin: 0;
    padding: 12px 16px;
    border-top: 1px solid var(--k-rule-soft);
}
.k-quotas {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(12rem, 1fr));
    gap: 4px 16px;
    align-items: end;
}
.k-quotas > div:last-child {
    grid-column: 1 / -1;
}
.k-quotas__input {
    display: flex;
    align-items: center;
    gap: 8px;
}
@media (prefers-reduced-motion: reduce) {
    .k-move__bar span {
        transition: none;
    }
}
</style>
