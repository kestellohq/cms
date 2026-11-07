<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Head, Link, usePage, useRouter } from '@kestello/app';
import { ApiError, confirm, formatDateTime, notify, useApi, useResource, __ } from '@kestello/sdk';
import { KAlert, KBadge, KButton, KCard, KField, KInput, KModal, KPageHeader, KSelect, KSwitch, KTable, KTime } from '@kestello/ui';
import EventPicker from '../../../../components/webhooks/EventPicker.vue';
import SecretModal from '../../../../components/webhooks/SecretModal.vue';

defineOptions({ layout: 'Admin' });

const page = usePage();
const api = useApi();
const router = useRouter();
const id = computed(() => Number(page.props.params?.id ?? 0));
const hook = useResource(() => `/webhooks/${id.value}`);
const catalog = useResource('/webhooks/events');

// ── settings ─────────────────────────────────────────────────────────
const form = reactive({ name: '', url: '', events: [], active: true });
const errors = ref({});
const saving = ref(false);
const differs = (w) => form.name !== w.name || form.url !== w.url || form.active !== w.active || [...form.events].sort().join() !== [...w.events].sort().join();
// Ping, Redeliver and Rotate secret reload the webhook: keep what is being typed
watch(
    () => hook.data.value,
    (w, before) => {
        if (w && (!before || !differs(before))) Object.assign(form, { name: w.name, url: w.url, events: [...w.events], active: w.active });
    },
    { immediate: true },
);
const dirty = computed(() => !!hook.data.value && differs(hook.data.value));

async function save() {
    saving.value = true;
    errors.value = {};
    try {
        await api.patch(`/webhooks/${id.value}`, { ...form });
        notify.success(__('Changes saved.'));
        await hook.reload({ silent: true });
    } catch (e) {
        if (e instanceof ApiError && e.status === 422) errors.value = e.fieldErrors();
        else notify.error(e);
    } finally {
        saving.value = false;
    }
}
async function turnOn() {
    form.active = true;
    await save();
}
async function remove() {
    if (
        !(await confirm({
            title: __('Delete "{name}"?', { name: hook.data.value.name }),
            message: __('Nothing more is sent to it, and its delivery log is deleted.'),
            confirmLabel: __('Delete'),
            danger: true,
        }))
    )
        return;
    try {
        await api.delete(`/webhooks/${id.value}`);
        notify.success(__('Webhook deleted.'));
        router.visit('/admin/system/webhooks');
    } catch (e) {
        notify.error(e);
    }
}

// ── secret ───────────────────────────────────────────────────────────
const secret = ref(null);
async function rotate() {
    if (
        !(await confirm({
            title: __('Make a new signing secret?'),
            message: __('The receiving service needs the new one. The old one keeps working for 24 hours.'),
            confirmLabel: __('Make a new secret'),
        }))
    )
        return;
    try {
        const { data } = await api.post(`/webhooks/${id.value}/secret`, {});
        secret.value = data.secret;
        hook.reload({ silent: true });
    } catch (e) {
        notify.error(e);
    }
}

// ── deliveries ───────────────────────────────────────────────────────
const deliveries = ref([]);
const nextBefore = ref(null);
const loadingLog = ref(false);
const filter = ref('');
const shown = ref(null);
const busy = ref(false);

async function load(more = false) {
    loadingLog.value = true;
    try {
        const body = await api.get(`/webhooks/${id.value}/deliveries`, {
            limit: 25,
            status: filter.value || undefined,
            before_id: more ? nextBefore.value : undefined,
        });
        deliveries.value = more ? [...deliveries.value, ...body.data] : body.data;
        nextBefore.value = body.meta.next_before_id;
    } catch (e) {
        notify.error(e);
    } finally {
        loadingLog.value = false;
    }
}
watch([id, filter], () => load(), { immediate: true });

async function ping() {
    busy.value = true;
    try {
        const { data } = await api.post(`/webhooks/${id.value}/ping`, {});
        data.status === 'succeeded'
            ? notify.success(__('The test arrived: answered {status}.', { status: data.response_status }))
            : notify.error(data.error ?? __('The test did not arrive.'));
        shown.value = data;
        await Promise.all([load(), hook.reload({ silent: true })]);
    } catch (e) {
        notify.error(e);
    } finally {
        busy.value = false;
    }
}
async function redeliver(d) {
    busy.value = true;
    try {
        const { data } = await api.post(`/webhooks/${id.value}/deliveries/${d.id}/redeliver`, {});
        data.status === 'succeeded'
            ? notify.success(__('Sent again: answered {status}.', { status: data.response_status }))
            : notify.error(data.error ?? __('It did not arrive.'));
        shown.value = data;
        await Promise.all([load(), hook.reload({ silent: true })]);
    } catch (e) {
        notify.error(e);
    } finally {
        busy.value = false;
    }
}

const STATUS = {
    succeeded: ['success', __('Delivered')],
    retrying: ['warning', __('Retrying')],
    failed: ['danger', __('Failed')],
    pending: ['neutral', __('Waiting')],
    sending: ['info', __('Sending')],
};
const FILTERS = [
    { value: '', label: __('All results') },
    { value: 'failed', label: __('Failed') },
    { value: 'retrying', label: __('Retrying') },
    { value: 'succeeded', label: __('Delivered') },
];
const pretty = (v) => JSON.stringify(v, null, 2);
// (an ICU plural's closing braces would end a {{ }} in the template)
const attemptsText = (n) => __('{n, plural, one {# attempt} other {# attempts}}', { n });
</script>

<template>
    <Head :title="hook.data.value?.name ?? __('Webhook')" />
    <p class="k-back">
        <Link href="/admin/system/webhooks" class="k-link">{{ __('All webhooks') }}</Link>
    </p>
    <KPageHeader :title="hook.data.value?.name ?? __('Webhook')" :description="hook.data.value?.url ?? ''">
        <template #actions
            ><KButton icon="paper-plane-tilt" :loading="busy" :disabled="!hook.data.value" @click="ping">{{ __('Send a test') }}</KButton></template
        >
    </KPageHeader>

    <KAlert v-if="hook.data.value?.disabled_reason" tone="danger" :title="__('This webhook is turned off')">
        <p class="k-hook__alert">
            {{ hook.data.value.disabled_reason }}
            {{ __('Check the delivery log below for what the service answered, fix it, then turn the webhook on again.') }}
        </p>
        <KButton size="sm" :loading="saving" @click="turnOn">{{ __('Turn on again') }}</KButton>
    </KAlert>

    <div class="k-hook">
        <KCard :title="__('Settings')">
            <form id="k-hook-edit" class="k-form" @submit.prevent="save">
                <KSwitch v-model="form.active" :label="__('Send events to this webhook')" />
                <KField v-slot="{ id: fid, invalid }" :label="__('Name')" :error="errors.name ?? ''" required>
                    <KInput :id="fid" v-model="form.name" maxlength="100" autocomplete="off" :invalid="invalid" />
                </KField>
                <KField v-slot="{ id: fid, invalid }" :label="__('Address')" :error="errors.url ?? ''" required>
                    <KInput :id="fid" v-model="form.url" type="url" inputmode="url" autocomplete="off" spellcheck="false" :invalid="invalid" />
                </KField>
                <EventPicker v-model="form.events" :events="catalog.data.value ?? []" :error="errors.events ?? ''" />
            </form>
            <template #footer>
                <KButton variant="danger" icon="trash" @click="remove">{{ __('Delete') }}</KButton>
                <KButton type="submit" form="k-hook-edit" variant="primary" :loading="saving" :disabled="!dirty">{{ __('Save changes') }}</KButton>
            </template>
        </KCard>

        <KCard :title="__('Signing secret')">
            <p class="k-small">
                {{
                    __(
                        'Every message has a Kestello-Signature header made with this secret. The receiver checks it to know the message came from this site and was not changed.',
                    )
                }}
            </p>
            <p v-if="hook.data.value?.secret_rotating_until" class="k-small k-muted">
                {{ __('The previous secret also signs messages until {when}.', { when: formatDateTime(hook.data.value.secret_rotating_until) }) }}
            </p>
            <KButton size="sm" icon="key" @click="rotate">{{ __('Make a new secret') }}</KButton>
        </KCard>
    </div>

    <KCard
        :title="__('Deliveries')"
        :description="__('Kept for 30 days. A failed delivery is tried again after 1, 5 and 30 minutes, then 2, 6 and 12 hours.')"
        flush
    >
        <template #actions>
            <KSelect v-model="filter" class="k-hook__filter" :options="FILTERS" :aria-label="__('Show')" />
        </template>
        <KTable
            :columns="[
                { key: 'event', label: __('Event') },
                { key: 'status', label: __('Result') },
                { key: 'created_at', label: __('Sent') },
                { key: 'actions', label: '', align: 'end', width: '1%' },
            ]"
            :rows="deliveries"
            :loading="loadingLog"
            :empty="filter ? __('No deliveries like that.') : __('Nothing sent yet')"
            :empty-text="filter ? '' : __('Use Send a test to try it.')"
        >
            <template #cell-event="{ row }">
                <button type="button" class="k-link-btn" @click="shown = row">
                    <code>{{ row.event }}</code>
                </button>
                <div class="k-muted k-small">
                    {{ attemptsText(row.attempts) }}<template v-if="row.duration_ms !== null"> · {{ row.duration_ms }} ms</template>
                </div>
            </template>
            <template #cell-status="{ row }">
                <KBadge :tone="(STATUS[row.status] ?? ['neutral'])[0]" dot
                    >{{ (STATUS[row.status] ?? [null, row.status])[1] }}<template v-if="row.response_status"> · {{ row.response_status }}</template></KBadge
                >
                <div v-if="row.status === 'retrying' && row.next_attempt_at" class="k-muted k-small">
                    {{ __('Next try') }} <KTime :value="row.next_attempt_at" />
                </div>
                <div v-else-if="row.error" class="k-muted k-small k-truncate">{{ row.error }}</div>
            </template>
            <template #cell-created_at="{ row }"><KTime :value="row.created_at" /></template>
            <template #cell-actions="{ row }">
                <KButton
                    size="sm"
                    variant="ghost"
                    icon="arrow-clockwise"
                    :disabled="busy"
                    :aria-label="__('Send again')"
                    :title="__('Send again')"
                    @click="redeliver(row)"
                />
            </template>
        </KTable>
        <template v-if="nextBefore" #footer>
            <KButton size="sm" :loading="loadingLog" @click="load(true)">{{ __('Show older') }}</KButton>
        </template>
    </KCard>

    <KModal :open="!!shown" :title="shown ? `${shown.event} · ${formatDateTime(shown.created_at)}` : ''" size="lg" @close="shown = null">
        <template v-if="shown">
            <dl class="k-hook__facts">
                <dt>{{ __('Result') }}</dt>
                <dd>
                    <KBadge :tone="(STATUS[shown.status] ?? ['neutral'])[0]" dot>{{ (STATUS[shown.status] ?? [null, shown.status])[1] }}</KBadge>
                </dd>
                <dt>{{ __('Answer') }}</dt>
                <dd>
                    {{ shown.response_status ?? '—' }}<template v-if="shown.duration_ms !== null"> {{ __('in {ms} ms', { ms: shown.duration_ms }) }}</template>
                </dd>
                <dt>{{ __('Event id') }}</dt>
                <dd>
                    <code>{{ shown.event_id }}</code>
                </dd>
                <template v-if="shown.error"
                    ><dt>{{ __('Problem') }}</dt>
                    <dd>{{ shown.error }}</dd></template
                >
            </dl>
            <h3 class="k-hook__h">{{ __('Sent') }}</h3>
            <pre class="k-pre k-hook__pre">{{ pretty(shown.payload) }}</pre>
            <template v-if="shown.response_body">
                <h3 class="k-hook__h">{{ __('Answered (start, secrets hidden)') }}</h3>
                <pre class="k-pre k-hook__pre">{{ shown.response_body }}</pre>
            </template>
        </template>
        <template #footer>
            <KButton @click="shown = null">{{ __('Close') }}</KButton>
            <KButton variant="primary" icon="arrow-clockwise" :loading="busy" @click="redeliver(shown)">{{ __('Send again') }}</KButton>
        </template>
    </KModal>

    <SecretModal :secret="secret" rotated @done="secret = null" />
</template>

<style scoped>
.k-back {
    margin: 0 0 8px;
    font-size: 13px;
}
.k-hook {
    display: grid;
    grid-template-columns: minmax(0, 2fr) minmax(0, 1fr);
    gap: 16px;
    align-items: start;
}
@media (max-width: 960px) {
    .k-hook {
        grid-template-columns: 1fr;
    }
}
.k-hook__alert {
    margin: 0 0 8px;
}
.k-hook__filter {
    width: auto;
    min-width: 140px;
}
.k-hook__facts {
    display: grid;
    grid-template-columns: max-content 1fr;
    gap: 6px 16px;
    margin: 0 0 12px;
    font-size: 14px;
}
.k-hook__facts dt {
    color: var(--k-ink-2);
}
.k-hook__facts dd {
    margin: 0;
    overflow-wrap: anywhere;
}
.k-hook__h {
    margin: 14px 0 6px;
    font-size: 13px;
    font-weight: 600;
}
.k-hook__pre {
    max-height: 280px;
}
</style>
