<script setup>
import { computed, ref } from 'vue';
import { notify, useApi, useCan, useResource, __ } from '@kestello/sdk';
import { KAlert, KBadge, KButton, KCard, KEmpty, KIcon, KKeyValue, KPageHeader, KStatusBadge, KTabs, KTime } from '@kestello/ui';

defineOptions({ layout: 'Admin' });

const api = useApi();
const refresh = ref(false);
const report = useResource('/system/health', { query: () => (refresh.value ? { refresh: true } : {}) });
const filter = ref('issues');
const copying = ref(false);
const can = useCan();
const clearing = ref(false);

// The page cache refills as visitors arrive; edits refresh their pages on their own, so this is for hand-edited theme files and the like.
async function clearPageCache() {
    clearing.value = true;
    try {
        await api.post('/system/page-cache/clear');
        notify.success(__('Page cache cleared. Pages are rebuilt as visitors open them.'));
    } catch (e) {
        notify.error(e);
    } finally {
        clearing.value = false;
    }
}

const checks = computed(() => report.data.value?.checks ?? []);
const counts = computed(() => report.data.value?.counts ?? { good: 0, recommended: 0, critical: 0 });
const visible = computed(() =>
    filter.value === 'all'
        ? checks.value
        : filter.value === 'issues'
          ? checks.value.filter((c) => c.status !== 'good')
          : checks.value.filter((c) => c.status === filter.value),
);
const tabs = computed(() => [
    { id: 'issues', label: __('Needs attention'), count: counts.value.critical + counts.value.recommended },
    { id: 'critical', label: __('Critical'), count: counts.value.critical },
    { id: 'recommended', label: __('Recommended'), count: counts.value.recommended },
    { id: 'good', label: __('Passed'), count: counts.value.good },
    { id: 'all', label: __('All'), count: checks.value.length },
]);
const summary = computed(
    () =>
        ({
            critical: [__('Your site has critical problems'), 'danger'],
            recommended: [__('Your site works, with a few recommendations'), 'warning'],
            good: [__('Your site is healthy'), 'success'],
        })[report.data.value?.status] ?? [__('Running checks…'), 'info'],
);

function rerun() {
    if (refresh.value) report.reload();
    else refresh.value = true; // the query changes, so useResource re-requests with refresh=1
}

async function copyInfo() {
    copying.value = true;
    try {
        const info = (await api.get('/system/info')).data;
        const text = JSON.stringify({ info, health: checks.value.map(({ id, status, message }) => ({ id, status, message })) }, null, 2);
        await navigator.clipboard.writeText(text);
        notify.success(__('Debug information copied. It contains no passwords or keys.'));
    } catch (e) {
        notify.error(e);
    } finally {
        copying.value = false;
    }
}
</script>

<template>
    <KPageHeader :title="__('Site Health')" :description="__('Checks that keep your site secure, fast and reliable.')">
        <template #actions>
            <KButton v-if="can('settings.manage')" icon="lightning" :loading="clearing" @click="clearPageCache">{{ __('Clear page cache') }}</KButton>
            <KButton icon="clipboard" :loading="copying" @click="copyInfo">{{ __('Copy debug info') }}</KButton>
            <KButton variant="primary" icon="arrows-clockwise" :loading="report.loading.value" @click="rerun">{{ __('Run checks again') }}</KButton>
        </template>
    </KPageHeader>

    <KAlert v-if="report.error.value" tone="danger">{{ report.error.value.message }}</KAlert>

    <div class="k-health-summary" :class="`is-${summary[1]}`">
        <KIcon :name="summary[1] === 'success' ? 'shield-check' : summary[1] === 'danger' ? 'shield-warning' : 'shield'" />
        <div>
            <strong>{{ summary[0] }}</strong>
            <p v-if="report.data.value" class="k-muted">
                {{ __('Checked') }} <KTime :value="report.data.value.generated_at" /> ·
                {{ __('{good} passed, {recommended} recommended, {critical} critical', counts) }}
            </p>
        </div>
    </div>

    <KCard flush>
        <template #title><KTabs v-model="filter" :tabs="tabs" :label="__('Filter checks')" /></template>
        <ul v-if="visible.length" class="k-checks">
            <li v-for="check in visible" :key="check.id" class="k-check">
                <details>
                    <summary>
                        <KStatusBadge :status="check.status" />
                        <span class="k-check__label">{{ check.label }}</span>
                        <span class="k-check__message">{{ check.message }}</span>
                        <KBadge class="k-check__category">{{ check.category }}</KBadge>
                    </summary>
                    <div class="k-check__details">
                        <p>{{ check.message }}</p>
                        <KKeyValue v-if="Object.keys(check.details ?? {}).length" :items="check.details" />
                        <p class="k-muted k-small">
                            {{ __('Check') }} <code>{{ check.id }}</code> · {{ check.owner }} · {{ check.ms }} ms
                        </p>
                        <KButton v-if="check.action" size="sm" :href="check.action.url" icon="arrow-right">{{ check.action.label }}</KButton>
                    </div>
                </details>
            </li>
        </ul>
        <KEmpty
            v-else-if="report.data.value"
            icon="check-circle"
            :title="filter === 'issues' ? __('Nothing needs your attention.') : __('No checks in this group.')"
        />
    </KCard>
</template>
