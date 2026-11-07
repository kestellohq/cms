<script setup>
import { computed, ref, watch } from 'vue';
import { usePage } from '@kestello/app';
import { notify, useApi, __ } from '@kestello/sdk';
import { KBadge, KButton, KCard, KField, KModal, KPageHeader, KSelect, KTable, KTime } from '@kestello/ui';
import { AUDIT_WARN, auditLabel } from '../../../components/audit/events.js';

defineOptions({ layout: 'Admin' });

const api = useApi();

// One filter per area; the API takes a single event prefix.
const AREAS = [
    { value: '', label: __('Everything') },
    { value: 'auth.*', label: __('Sign-in and accounts') },
    { value: 'users.*', label: __('People') },
    { value: 'role.*', label: __('Roles') },
    { value: 'settings.*', label: __('Settings') },
    { value: 'plugin.*', label: __('Plugins') },
    { value: 'theme.*', label: __('Themes and design') },
    { value: 'backup.*', label: __('Backups') },
    { value: 'core.*', label: __('Kestello updates') },
    { value: 'media.*', label: __('Media') },
    { value: 'menu.*', label: __('Menus') },
    { value: 'form.*', label: __('Forms') },
    { value: 'redirect.*', label: __('Redirects') },
    { value: 'content.*', label: __('Content') },
    { value: 'webhook.*', label: __('Webhooks') },
    { value: 'content_model.*', label: __('Content types') },
    { value: 'platform.*', label: __('Infrastructure') },
];

// ?area=auth.* opens the log filtered (links from System → Security)
const fromUrl = new URLSearchParams(usePage().url.split('?')[1] ?? '').get('area') ?? '';
const area = ref(AREAS.some((x) => x.value === fromUrl) ? fromUrl : '');
const actor = ref(null); // { id, username } when filtered to one person
const rows = ref([]);
const nextBefore = ref(null);
const loading = ref(false);
const shown = ref(null);

async function load(more = false) {
    loading.value = true;
    try {
        const body = await api.get('/audit', {
            limit: 50,
            event: area.value || undefined,
            actor_id: actor.value?.id,
            before_id: more ? nextBefore.value : undefined,
        });
        rows.value = more ? [...rows.value, ...body.data] : body.data;
        nextBefore.value = body.meta.next_before_id;
    } catch (e) {
        notify.error(e);
    } finally {
        loading.value = false;
    }
}
watch([area, actor], () => load());
load();

const label = (row) => auditLabel(row.event);
const subject = (row) => (row.subject_type ? `${row.subject_type} ${row.subject_id}` : '');
const who = (row) =>
    row.actor ? (row.actor.username ?? __('Deleted person #{id}', { id: row.actor.id })) : row.owner !== 'core' ? row.owner : __('The system');
const details = computed(() => (shown.value ? Object.entries(shown.value.data ?? {}) : []));
const show = (value) => (typeof value === 'string' ? value : JSON.stringify(value));
</script>

<template>
    <KPageHeader
        :title="__('Audit log')"
        :description="
            __(
                'Who changed what, and when: sign-ins, people and roles, settings, plugins, themes, backups and updates. Passwords and other secrets are never recorded.',
            )
        "
    />

    <div class="k-toolbar" role="search">
        <KField v-slot="{ id }" :label="__('Show')">
            <KSelect :id="id" v-model="area" :options="AREAS" />
        </KField>
        <KButton v-if="actor" variant="ghost" icon="x" :aria-label="__('Only {name}: show everyone again', { name: actor.username })" @click="actor = null">{{
            __('Only {name}', { name: actor.username })
        }}</KButton>
    </div>

    <KCard flush>
        <KTable
            :columns="[
                { key: 'occurred_at', label: __('When'), width: '1%' },
                { key: 'who', label: __('Who') },
                { key: 'event', label: __('What') },
                { key: 'ip', label: __('From') },
                { key: 'actions', label: '', align: 'end', width: '1%' },
            ]"
            :rows="rows"
            :loading="loading"
            :empty="__('Nothing recorded yet.')"
        >
            <template #cell-occurred_at="{ row }"><KTime :value="row.occurred_at" class="k-nowrap" /></template>
            <template #cell-who="{ row }">
                <button v-if="row.actor && row.actor.username" type="button" class="k-link-btn" :title="__('Show only this person')" @click="actor = row.actor">
                    {{ row.actor.username }}
                </button>
                <span v-else class="k-muted">{{ who(row) }}</span>
            </template>
            <template #cell-event="{ row }">
                <KBadge v-if="AUDIT_WARN.has(row.event)" tone="warning">{{ label(row) }}</KBadge>
                <template v-else>{{ label(row) }}</template>
                <div v-if="subject(row)" class="k-muted k-small">
                    <code>{{ subject(row) }}</code>
                </div>
            </template>
            <template #cell-ip="{ row }"
                ><span class="k-muted k-small">{{ row.ip ?? '' }}</span></template
            >
            <template #cell-actions="{ row }">
                <KButton size="sm" variant="ghost" icon="info" :aria-label="__('Details')" @click="shown = row" />
            </template>
        </KTable>
        <template v-if="nextBefore" #footer
            ><KButton size="sm" :loading="loading" @click="load(true)">{{ __('Load more') }}</KButton></template
        >
    </KCard>

    <KModal :open="!!shown" :title="shown ? label(shown) : ''" size="lg" @close="shown = null">
        <template v-if="shown">
            <dl class="k-kv">
                <dt>{{ __('When') }}</dt>
                <dd><KTime :value="shown.occurred_at" /></dd>
                <dt>{{ __('Who') }}</dt>
                <dd>{{ who(shown) }}</dd>
                <dt>{{ __('Event') }}</dt>
                <dd>
                    <code>{{ shown.event }}</code>
                </dd>
                <template v-if="subject(shown)"
                    ><dt>{{ __('About') }}</dt>
                    <dd>
                        <code>{{ subject(shown) }}</code>
                    </dd></template
                >
                <template v-if="shown.ip"
                    ><dt>{{ __('Address') }}</dt>
                    <dd>{{ shown.ip }}</dd></template
                >
                <template v-if="shown.user_agent"
                    ><dt>{{ __('Browser') }}</dt>
                    <dd class="k-small">{{ shown.user_agent }}</dd></template
                >
                <template v-if="shown.owner !== 'core'"
                    ><dt>{{ __('Recorded by') }}</dt>
                    <dd>
                        <code>{{ shown.owner }}</code>
                    </dd></template
                >
                <template v-if="shown.request_id"
                    ><dt>{{ __('Request') }}</dt>
                    <dd>
                        <code>{{ shown.request_id }}</code>
                    </dd></template
                >
            </dl>
            <template v-if="details.length">
                <h3 class="k-subheading">{{ __('Details') }}</h3>
                <dl class="k-kv">
                    <template v-for="[key, value] in details" :key="key"
                        ><dt>
                            <code>{{ key }}</code>
                        </dt>
                        <dd>{{ show(value) }}</dd></template
                    >
                </dl>
            </template>
        </template>
    </KModal>
</template>
