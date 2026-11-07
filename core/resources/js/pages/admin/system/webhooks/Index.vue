<script setup>
import { reactive, ref } from 'vue';
import { Link, useRouter } from '@kestello/app';
import { ApiError, notify, useApi, useResource, __ } from '@kestello/sdk';
import { KBadge, KButton, KCard, KEmpty, KField, KInput, KModal, KPageHeader, KTable, KTime } from '@kestello/ui';
import EventPicker from '../../../../components/webhooks/EventPicker.vue';
import SecretModal from '../../../../components/webhooks/SecretModal.vue';

defineOptions({ layout: 'Admin' });

const api = useApi();
const router = useRouter();
const hooks = useResource('/webhooks');
const catalog = useResource('/webhooks/events');

const creating = ref(false);
const saving = ref(false);
const errors = ref({});
const form = reactive({ name: '', url: '', events: [] });
const made = ref(null);

function open() {
    Object.assign(form, { name: '', url: '', events: [] });
    errors.value = {};
    creating.value = true;
}
async function create() {
    saving.value = true;
    errors.value = {};
    try {
        const { data } = await api.post('/webhooks', { ...form });
        creating.value = false;
        made.value = data;
    } catch (e) {
        if (e instanceof ApiError && e.status === 422) errors.value = e.fieldErrors();
        else notify.error(e);
    } finally {
        saving.value = false;
    }
}
function stored() {
    const id = made.value.id;
    made.value = null;
    router.visit(`/admin/system/webhooks/${id}`);
}

const STATUS = {
    succeeded: ['success', __('Delivered')],
    retrying: ['warning', __('Retrying')],
    failed: ['danger', __('Failed')],
    pending: ['neutral', __('Waiting')],
    sending: ['info', __('Sending')],
};
const host = (url) => {
    try {
        return new URL(url).host;
    } catch {
        return url;
    }
};
const eventCount = (w) => (w.events.includes('*') ? __('Every event') : __('{n, plural, one {# event} other {# events}}', { n: w.events.length }));
</script>

<template>
    <KPageHeader
        :title="__('Webhooks')"
        :description="
            __(
                'Tell other services when something happens on the site: a post goes live, a form is sent, someone signs up. Each message is signed so the receiver can check it came from here.',
            )
        "
    >
        <template #actions
            ><KButton variant="primary" icon="plus" @click="open">{{ __('Add webhook') }}</KButton></template
        >
    </KPageHeader>

    <KCard flush>
        <KTable
            v-if="hooks.loading.value || hooks.data.value?.length"
            :columns="[
                { key: 'name', label: __('Webhook') },
                { key: 'events', label: __('Sends') },
                { key: 'last', label: __('Last delivery') },
                { key: 'state', label: __('State'), align: 'end' },
            ]"
            :rows="hooks.data.value ?? []"
            :loading="hooks.loading.value"
        >
            <template #cell-name="{ row }">
                <Link :href="`/admin/system/webhooks/${row.id}`" class="k-link"
                    ><strong>{{ row.name }}</strong></Link
                >
                <div class="k-muted k-small k-truncate">{{ host(row.url) }}</div>
            </template>
            <template #cell-events="{ row }"
                ><span class="k-small">{{ eventCount(row) }}</span></template
            >
            <template #cell-last="{ row }">
                <template v-if="row.last_delivery">
                    <KBadge :tone="(STATUS[row.last_delivery.status] ?? ['neutral'])[0]" dot>{{
                        (STATUS[row.last_delivery.status] ?? [null, row.last_delivery.status])[1]
                    }}</KBadge>
                    <div class="k-muted k-small">
                        <code>{{ row.last_delivery.event }}</code> · <KTime :value="row.last_delivery.created_at" />
                    </div>
                </template>
                <span v-else class="k-muted k-small">{{ __('Nothing sent yet') }}</span>
            </template>
            <template #cell-state="{ row }">
                <KBadge v-if="row.active" tone="success">{{ __('On') }}</KBadge>
                <KBadge v-else :tone="row.disabled_reason ? 'danger' : 'neutral'" :title="row.disabled_reason ?? ''">{{
                    row.disabled_reason ? __('Turned off after failures') : __('Off')
                }}</KBadge>
            </template>
        </KTable>
        <KEmpty v-else icon="webhooks-logo" :title="__('No webhooks yet')">
            {{
                __(
                    'Add one with the address of the service that should hear about changes, such as a build hook that redeploys a static site, or an automation tool.',
                )
            }}
        </KEmpty>
    </KCard>

    <KModal :open="creating" :title="__('Add a webhook')" size="lg" @close="creating = false">
        <form id="k-hook-create" class="k-form" @submit.prevent="create">
            <KField
                v-slot="{ id, invalid }"
                :label="__('Name')"
                :help="__('So you recognise it later, such as \'Netlify rebuild\'.')"
                :error="errors.name ?? ''"
                required
            >
                <KInput :id="id" v-model="form.name" maxlength="100" autocomplete="off" :invalid="invalid" />
            </KField>
            <KField
                v-slot="{ id, invalid }"
                :label="__('Address')"
                :help="__('The https:// URL the receiving service gave you.')"
                :error="errors.url ?? ''"
                required
            >
                <KInput :id="id" v-model="form.url" type="url" inputmode="url" autocomplete="off" spellcheck="false" :invalid="invalid" />
            </KField>
            <EventPicker v-model="form.events" :events="catalog.data.value ?? []" :error="errors.events ?? ''" />
        </form>
        <template #footer>
            <KButton @click="creating = false">{{ __('Cancel') }}</KButton>
            <KButton
                type="submit"
                form="k-hook-create"
                variant="primary"
                :loading="saving"
                :disabled="!form.name.trim() || !form.url.trim() || !form.events.length"
                >{{ __('Add webhook') }}</KButton
            >
        </template>
    </KModal>

    <SecretModal :secret="made?.secret ?? null" @done="stored" />
</template>
