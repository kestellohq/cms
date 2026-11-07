<script setup>
import { computed, ref } from 'vue';
import { notify, useApi, useResource, __ } from '@kestello/sdk';
import { KAlert, KBadge, KButton, KCard, KModal, KPageHeader, KStatusBadge, KSwitch, KTable, KTime } from '@kestello/ui';

defineOptions({ layout: 'Admin' });

const api = useApi();
const tasks = useResource('/system/schedule', { poll: 30 });
const running = ref(null);
const output = ref(null);

const heartbeat = computed(() => tasks.meta.value?.heartbeat ?? null);
const stale = computed(() => !heartbeat.value || Date.now() / 1000 - heartbeat.value.at > 600);

async function runNow(task) {
    running.value = task.name;
    try {
        const { data } = await api.post(`/system/schedule/${encodeURIComponent(task.name)}/run`, {});
        (data.status === 'failed' ? notify.error : notify.success)(
            __('{name}: {status} in {ms} ms', { name: task.name, status: data.status, ms: data.duration_ms ?? 0 }),
        );
        await tasks.reload({ silent: true });
    } catch (e) {
        notify.error(e);
    } finally {
        running.value = null;
    }
}

async function toggle(task, enabled) {
    try {
        await api.patch(`/system/schedule/${encodeURIComponent(task.name)}`, { disabled: !enabled });
        notify.success(enabled ? __('{name} is enabled.', { name: task.name }) : __('{name} is paused.', { name: task.name }));
        await tasks.reload({ silent: true });
    } catch (e) {
        notify.error(e);
    }
}
</script>

<template>
    <KPageHeader :title="__('Scheduled tasks')" :description="__('Recurring work: cleanup, digests, backups and plugin tasks.')" />

    <KAlert v-if="tasks.data.value && stale" tone="warning" :title="heartbeat ? __('The scheduler is behind') : __('The scheduler has never run')">
        {{
            __(
                'Add a cron job that runs every minute: * * * * * php bin/cms schedule:run. On hosting without cron, enable the web runner or call POST /system/cron from an external service.',
            )
        }}
        <template v-if="heartbeat"> {{ __('Last run') }} <KTime :value="heartbeat.at" /> ({{ heartbeat.via }}).</template>
    </KAlert>

    <KCard flush>
        <KTable
            :columns="[
                { key: 'name', label: __('Task') },
                { key: 'cron', label: __('Schedule') },
                { key: 'next', label: __('Next run') },
                { key: 'last', label: __('Last run') },
                { key: 'enabled', label: __('Enabled') },
                { key: 'actions', label: '', align: 'end', width: '1%' },
            ]"
            :rows="tasks.data.value ?? []"
            row-key="name"
            :loading="tasks.loading.value"
            :empty="__('No scheduled tasks.')"
        >
            <template #cell-name="{ row }">
                <strong>{{ row.name }}</strong> <KBadge v-if="row.owner !== 'core'" tone="accent">{{ row.owner }}</KBadge>
                <div class="k-muted k-small">{{ row.description }}</div>
            </template>
            <template #cell-cron="{ row }"
                ><code>{{ row.cron }}</code>
                <div v-if="row.timezone" class="k-muted k-small">{{ row.timezone }}</div></template
            >
            <template #cell-next="{ row }"><KTime v-if="!row.disabled" :value="row.next_run_at" /><KStatusBadge v-else status="disabled" /></template>
            <template #cell-last="{ row }">
                <template v-if="row.last">
                    <KStatusBadge :status="row.last.status" /> <KTime :value="row.last.started_at" />
                    <div class="k-muted k-small">
                        <span v-if="row.last.duration_ms !== null">{{ row.last.duration_ms }} ms</span>
                        <template v-if="row.last.output"
                            ><span v-if="row.last.duration_ms !== null" aria-hidden="true"> · </span
                            ><button type="button" class="k-link-btn" @click="output = row">{{ __('Output') }}</button></template
                        >
                    </div>
                </template>
                <span v-else class="k-muted">{{ __('Not yet') }}</span>
            </template>
            <template #cell-enabled="{ row }"
                ><KSwitch :model-value="!row.disabled" :label="__('Enabled')" hide-label @update:model-value="toggle(row, $event)"
            /></template>
            <template #cell-actions="{ row }"
                ><KButton size="sm" icon="play" :loading="running === row.name" :disabled="running !== null" @click="runNow(row)">{{
                    __('Run now')
                }}</KButton></template
            >
        </KTable>
    </KCard>

    <KModal :open="!!output" :title="output ? __('Output of {name}', { name: output.name }) : ''" size="lg" @close="output = null">
        <pre v-if="output" class="k-pre">{{ output.last.output }}</pre>
    </KModal>
</template>
