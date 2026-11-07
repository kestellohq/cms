<script setup>
import { computed, ref } from 'vue';
import { confirm, formatRelative, notify, useApi, useResource, __ } from '@kestello/sdk';
import { KAlert, KBadge, KButton, KCard, KModal, KPageHeader, KStat, KTable, KTime } from '@kestello/ui';

defineOptions({ layout: 'Admin' });

const api = useApi();
const status = useResource('/system/queue', { poll: 10 });
const failed = ref([]);
const nextBefore = ref(null);
const total = ref(0);
const loadingFailed = ref(false);
const shown = ref(null);

const worker = computed(() => status.data.value?.worker ?? null);
const workerFresh = computed(() => worker.value && Date.now() / 1000 - worker.value.seen_at < 600);
const pending = computed(() => (status.data.value?.queues ?? []).reduce((s, q) => s + q.pending, 0));

async function loadFailed(more = false) {
    loadingFailed.value = true;
    try {
        const body = await api.get('/system/failed-jobs', { limit: 25, before: more ? nextBefore.value : undefined });
        failed.value = more ? [...failed.value, ...body.data] : body.data;
        nextBefore.value = body.meta.next_before;
        total.value = body.meta.total;
    } catch (e) {
        notify.error(e);
    } finally {
        loadingFailed.value = false;
    }
}
loadFailed();

async function retry(id) {
    try {
        const { data } = await api.post(`/system/failed-jobs/${id}/retry`, {});
        notify.success(__('{count, plural, one {# job queued again.} other {# jobs queued again.}}', { count: data.retried }));
        await Promise.all([loadFailed(), status.reload()]);
    } catch (e) {
        notify.error(e);
    }
}

async function forget(id) {
    const all = id === 'all';
    if (
        !(await confirm({
            title: all ? __('Delete all failed jobs?') : __('Delete this failed job?'),
            message: __('The job and its error are removed. This cannot be undone.'),
            confirmLabel: __('Delete'),
            danger: true,
        }))
    )
        return;
    try {
        const { data } = await api.delete(`/system/failed-jobs/${id}`);
        notify.success(__('{count, plural, one {# failed job deleted.} other {# failed jobs deleted.}}', { count: data.deleted }));
        await Promise.all([loadFailed(), status.reload()]);
    } catch (e) {
        notify.error(e);
    }
}

// ── batches ──────────────────────────────────────────────────────────
const batches = computed(() => status.data.value?.batches ?? []);
const BATCH_STATUS = {
    running: ['info', __('Running')],
    cancelling: ['warning', __('Stopping')],
    finished: ['success', __('Finished')],
    finished_with_failures: ['warning', __('Finished with failures')],
    cancelled: ['neutral', __('Cancelled')],
};

async function cancelBatch(batch) {
    if (
        !(await confirm({
            title: __('Stop "{name}"?', { name: batch.name }),
            message: __('Jobs that have not started are skipped. Jobs already running finish.'),
            confirmLabel: __('Stop batch'),
            danger: true,
        }))
    )
        return;
    try {
        await api.post(`/system/queue/batches/${batch.id}/cancel`, {});
        notify.success(__('Batch stopped.'));
        await status.reload({ silent: true });
    } catch (e) {
        notify.error(e);
    }
}

function shortClass(name) {
    return String(name).split('\\').pop();
}
</script>

<template>
    <KPageHeader :title="__('Background jobs')" :description="__('Work that runs outside page requests: emails, imports, image processing and plugin jobs.')" />

    <KAlert v-if="status.data.value?.sync" tone="info" :title="__('Jobs run immediately')">
        {{
            __(
                'The queue driver is "sync", so jobs run during the request that creates them. Switch to the database or Redis driver under Infrastructure for background processing and retries.',
            )
        }}
    </KAlert>
    <KAlert v-else-if="status.data.value && !workerFresh && pending > 0" tone="danger" :title="__('No worker is running')">
        {{ __('Jobs are waiting but no worker has been seen for 10 minutes. Start one with "php bin/cms queue:work", or let the scheduler process jobs.') }}
    </KAlert>

    <div class="k-grid k-grid--3">
        <KStat :label="__('Waiting')" icon="hourglass-medium" :value="status.data.value ? pending : '…'" />
        <KStat
            :label="__('Failed')"
            icon="warning-octagon"
            :value="status.data.value ? status.data.value.failed : '…'"
            :tone="status.data.value?.failed ? 'warning' : 'neutral'"
        />
        <KStat
            :label="__('Worker')"
            icon="cpu"
            :value="worker ? (workerFresh ? __('Active') : __('Idle')) : __('Never seen')"
            :tone="workerFresh ? 'success' : 'neutral'"
        >
            <template v-if="worker">{{ __('Last seen {when} on {host}', { when: formatRelative(worker.seen_at), host: worker.host ?? '?' }) }}</template>
        </KStat>
    </div>

    <KCard :title="__('Queues')" flush>
        <KTable
            :columns="[
                { key: 'name', label: __('Queue') },
                { key: 'pending', label: __('Waiting'), align: 'end' },
                { key: 'delayed', label: __('Delayed'), align: 'end' },
                { key: 'reserved', label: __('Running'), align: 'end' },
                { key: 'oldest', label: __('Oldest waiting'), align: 'end' },
            ]"
            :rows="status.data.value?.queues ?? []"
            row-key="name"
            :loading="status.loading.value"
            :empty="__('No jobs are waiting.')"
        >
            <template #cell-name="{ row }"
                ><code>{{ row.name }}</code></template
            >
            <template #cell-oldest="{ row }"><KTime :value="row.oldest" /></template>
        </KTable>
    </KCard>

    <KCard v-if="batches.length" :title="__('Batches')" :description="__('Groups of jobs started together, such as an import or regenerating images.')" flush>
        <KTable
            :columns="[
                { key: 'name', label: __('Batch') },
                { key: 'progress', label: __('Progress') },
                { key: 'status', label: __('Status') },
                { key: 'actions', label: '', align: 'end', width: '1%' },
            ]"
            :rows="batches"
            :empty="__('No batches.')"
        >
            <template #cell-name="{ row }">
                <strong>{{ row.name }}</strong>
                <div class="k-muted k-small">{{ row.owner }} · <KTime :value="row.created_at" /></div>
            </template>
            <template #cell-progress="{ row }">
                <div
                    class="k-meter"
                    role="progressbar"
                    :aria-valuenow="row.progress"
                    aria-valuemin="0"
                    aria-valuemax="100"
                    :aria-label="__('{name} progress', { name: row.name })"
                >
                    <span class="k-meter__done" :style="{ width: `${((row.processed - row.failed) / Math.max(1, row.total)) * 100}%` }"></span>
                    <span class="k-meter__failed" :style="{ width: `${(row.failed / Math.max(1, row.total)) * 100}%` }"></span>
                </div>
                <div class="k-muted k-small k-num">
                    {{ __('{done} of {total}', { done: row.processed, total: row.total })
                    }}<template v-if="row.failed"> · {{ __('{n} failed', { n: row.failed }) }}</template>
                </div>
            </template>
            <template #cell-status="{ row }"
                ><KBadge :tone="(BATCH_STATUS[row.status] ?? ['neutral'])[0]">{{ (BATCH_STATUS[row.status] ?? [null, row.status])[1] }}</KBadge></template
            >
            <template #cell-actions="{ row }">
                <KButton v-if="row.status === 'running'" size="sm" variant="ghost" icon="stop-circle" @click="cancelBatch(row)">{{ __('Stop') }}</KButton>
                <span v-else class="k-sr-only">{{ __('No actions') }}</span>
            </template>
        </KTable>
    </KCard>

    <KCard :title="__('Failed jobs')" :description="__('Jobs that used up their retries. Fix the cause, then retry them.')" flush>
        <template #actions>
            <KButton v-if="total" size="sm" icon="arrows-clockwise" :disabled="status.data.value?.sync" @click="retry('all')">{{ __('Retry all') }}</KButton>
            <KButton v-if="total" size="sm" variant="danger" icon="trash" @click="forget('all')">{{ __('Delete all') }}</KButton>
        </template>
        <KTable
            :columns="[
                { key: 'job', label: __('Job') },
                { key: 'error', label: __('Error') },
                { key: 'failed_at', label: __('Failed') },
                { key: 'actions', label: '', align: 'end', width: '1%' },
            ]"
            :rows="failed"
            :loading="loadingFailed"
            :empty="__('No failed jobs.')"
        >
            <template #cell-job="{ row }">
                <strong>{{ shortClass(row.job) }}</strong>
                <div class="k-muted k-small">
                    <code>{{ row.queue }}</code> · {{ row.owner }} · {{ __('{n} attempts', { n: row.attempts }) }}
                </div>
            </template>
            <template #cell-error="{ row }"
                ><button type="button" class="k-link-btn k-truncate" @click="shown = row">{{ row.error.split('\n')[0] }}</button></template
            >
            <template #cell-failed_at="{ row }"><KTime :value="row.failed_at" /></template>
            <template #cell-actions="{ row }">
                <div class="k-row-actions">
                    <KButton
                        size="sm"
                        variant="ghost"
                        icon="arrows-clockwise"
                        :disabled="status.data.value?.sync"
                        :aria-label="__('Retry')"
                        @click="retry(row.id)"
                    />
                    <KButton size="sm" variant="ghost" icon="trash" :aria-label="__('Delete')" @click="forget(row.id)" />
                </div>
            </template>
        </KTable>
        <template v-if="nextBefore" #footer
            ><KButton size="sm" :loading="loadingFailed" @click="loadFailed(true)">{{ __('Load more') }}</KButton></template
        >
    </KCard>

    <KModal :open="!!shown" :title="shown ? shortClass(shown.job) : ''" size="lg" @close="shown = null">
        <template v-if="shown">
            <p>
                <KBadge>{{ shown.queue }}</KBadge> <KBadge>{{ shown.owner }}</KBadge> <KTime :value="shown.failed_at" />
            </p>
            <h3 class="k-subheading">{{ __('Arguments') }}</h3>
            <pre class="k-pre">{{ JSON.stringify(shown.args, null, 2) }}</pre>
            <h3 class="k-subheading">{{ __('Error') }}</h3>
            <pre class="k-pre">{{ shown.error }}</pre>
        </template>
    </KModal>
</template>
