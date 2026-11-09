<script setup>
/**
 * Site → Fleet (app.fleet.enabled): the other Kestello sites this one watches. Each row is the
 * last report that site gave: its version and any newer Kestello, plugin and theme updates,
 * license problems, Site Health and its last backup. Sites are asked every hour, or now.
 */
import { reactive, ref } from 'vue';
import { ApiError, confirm, notify, useApi, useResource, __ } from '@kestello/sdk';
import { KBadge, KButton, KCard, KEmpty, KField, KInput, KModal, KPageHeader, KTable, KTime } from '@kestello/ui';

defineOptions({ layout: 'Admin' });

const api = useApi();
const fleet = useResource('/fleet/sites');

const adding = ref(false);
const saving = ref(false);
const checking = ref(new Set());
const errors = ref({});
const form = reactive({ name: '', url: '', token: '' });

function open() {
    Object.assign(form, { name: '', url: '', token: '' });
    errors.value = {};
    adding.value = true;
}
async function add() {
    saving.value = true;
    errors.value = {};
    try {
        const { data } = await api.post('/fleet/sites', { ...form });
        adding.value = false;
        fleet.reload({ silent: true });
        if (data.error) notify.error(__('{name} was added, but could not be checked: {error}', { name: data.name, error: data.error }));
        else notify.success(__('{name} was added.', { name: data.name }));
    } catch (e) {
        if (e instanceof ApiError && e.status === 422) errors.value = e.fieldErrors();
        else notify.error(e);
    } finally {
        saving.value = false;
    }
}
async function check(row) {
    checking.value = new Set([...checking.value, row.id]);
    try {
        const { data } = await api.post(`/fleet/sites/${row.id}/check`);
        fleet.reload({ silent: true });
        if (data.error) notify.error(data.error);
    } catch (e) {
        notify.error(e);
    } finally {
        checking.value = new Set([...checking.value].filter((id) => id !== row.id));
    }
}
async function remove(row) {
    const ok = await confirm({
        title: __('Remove {name} from the fleet?', { name: row.name }),
        message: __('Its token keeps working on that site until you revoke it there, under Your profile → Access tokens or System → Security.'),
        confirmLabel: __('Remove'),
        danger: true,
    });
    if (!ok) return;
    try {
        await api.delete(`/fleet/sites/${row.id}`);
        fleet.reload({ silent: true });
    } catch (e) {
        notify.error(e);
    }
}

const HEALTH = {
    good: ['success', __('Good')],
    recommended: ['warning', __('Could be better')],
    critical: ['danger', __('Needs attention')],
};
const host = (url) => {
    try {
        const u = new URL(url);
        return u.host + (u.pathname === '/' ? '' : u.pathname);
    } catch {
        return url;
    }
};
const WEEK = 7 * 86400;
const staleBackup = (r) => !r.backups?.last_at || Date.now() / 1000 - r.backups.last_at > WEEK;
const names = (list) => list.map((p) => p.name).join(', ');
const updateCount = (p) => __('{n, plural, one {# update} other {# updates}}', { n: p.updates.length });
const licenseCount = (p) => __('{n, plural, one {# license problem} other {# license problems}}', { n: p.licenses.length });
const upToDate = (p) => __('{n, plural, one {# installed, up to date} other {# installed, up to date}}', { n: p.installed });
const problemList = (h) => h.problems.map((c) => c.label).join('\n');
</script>

<template>
    <KPageHeader
        :title="__('Fleet')"
        :description="
            __(
                'The other Kestello sites you look after, in one list: which version each runs, what needs updating, Site Health and the last backup. Each site is asked every hour with a token made on it that can read this and nothing else.',
            )
        "
    >
        <template #actions
            ><KButton variant="primary" icon="plus" @click="open">{{ __('Add site') }}</KButton></template
        >
    </KPageHeader>

    <KCard flush>
        <KTable
            v-if="fleet.loading.value || fleet.data.value?.sites.length"
            :columns="[
                { key: 'name', label: __('Site') },
                { key: 'kestello', label: __('Kestello') },
                { key: 'packages', label: __('Plugins and themes') },
                { key: 'health', label: __('Site Health') },
                { key: 'backup', label: __('Last backup') },
                { key: 'actions', label: __('Checked'), align: 'end' },
            ]"
            :rows="fleet.data.value?.sites ?? []"
            :loading="fleet.loading.value"
        >
            <template #cell-name="{ row }">
                <a :href="row.url" class="k-link" target="_blank" rel="noopener noreferrer"
                    ><strong>{{ row.name }}</strong></a
                >
                <div class="k-muted k-small k-truncate">{{ host(row.url) }}</div>
                <div v-if="row.error" class="k-fleet__error k-small" role="note">{{ row.error }}</div>
            </template>
            <template #cell-kestello="{ row }">
                <template v-if="row.report">
                    {{ row.report.kestello.version }}
                    <div v-if="row.report.kestello.update" class="k-small">
                        <KBadge tone="info">{{ __('{version} is out', { version: row.report.kestello.update.version }) }}</KBadge>
                    </div>
                </template>
                <span v-else class="k-muted">—</span>
            </template>
            <template #cell-packages="{ row }">
                <template v-if="row.report?.packages">
                    <div v-if="row.report.packages.updates.length" :title="names(row.report.packages.updates)">
                        <KBadge tone="info">{{ updateCount(row.report.packages) }}</KBadge>
                    </div>
                    <div v-if="row.report.packages.licenses.length" :title="names(row.report.packages.licenses)">
                        <KBadge tone="warning">{{ licenseCount(row.report.packages) }}</KBadge>
                    </div>
                    <span v-if="!row.report.packages.updates.length && !row.report.packages.licenses.length" class="k-muted k-small">{{
                        upToDate(row.report.packages)
                    }}</span>
                </template>
                <span v-else class="k-muted">—</span>
            </template>
            <template #cell-health="{ row }">
                <template v-if="row.report?.health">
                    <KBadge :tone="HEALTH[row.report.health.status][0]" dot>{{ HEALTH[row.report.health.status][1] }}</KBadge>
                    <div v-if="row.report.health.problems.length" class="k-muted k-small k-truncate" :title="problemList(row.report.health)">
                        {{ row.report.health.problems[0].label
                        }}<template v-if="row.report.health.problems.length > 1">
                            {{ __('and {n} more', { n: row.report.health.problems.length - 1 }) }}</template
                        >
                    </div>
                </template>
                <span v-else class="k-muted">—</span>
            </template>
            <template #cell-backup="{ row }">
                <template v-if="row.report?.backups">
                    <KBadge v-if="!row.report.backups.last_at" tone="danger">{{ __('Never') }}</KBadge>
                    <template v-else>
                        <KTime :value="row.report.backups.last_at" />
                        <div v-if="staleBackup(row.report)" class="k-small">
                            <KBadge tone="warning">{{ __('Over a week old') }}</KBadge>
                        </div>
                    </template>
                </template>
                <span v-else class="k-muted">—</span>
            </template>
            <template #cell-actions="{ row }">
                <div class="k-fleet__actions">
                    <span class="k-muted k-small"
                        ><KTime v-if="row.checked_at" :value="row.checked_at" /><template v-else>{{ __('Not yet') }}</template></span
                    >
                    <KButton size="sm" variant="ghost" icon="arrows-clockwise" :loading="checking.has(row.id)" @click="check(row)">{{
                        __('Check now')
                    }}</KButton>
                    <KButton size="sm" variant="ghost" icon="trash" :aria-label="__('Remove {name}', { name: row.name })" @click="remove(row)" />
                </div>
            </template>
        </KTable>
        <KEmpty v-else icon="squares-four" :title="__('No sites in the fleet yet')">
            {{
                __(
                    'On each site you look after, sign in as an administrator and create a token under Your profile → Access tokens, with only the fleet permission ticked. Then add the site here with its address and that token.',
                )
            }}
        </KEmpty>
    </KCard>

    <KModal :open="adding" :title="__('Add a site')" @close="adding = false">
        <form id="k-fleet-add" class="k-form" @submit.prevent="add">
            <KField v-slot="{ id, invalid }" :label="__('Name')" :error="errors.name ?? ''" required>
                <KInput :id="id" v-model="form.name" maxlength="120" autocomplete="off" :invalid="invalid" />
            </KField>
            <KField
                v-slot="{ id, invalid }"
                :label="__('Address')"
                :help="__('The site\'s home page, starting with https://.')"
                :error="errors.url ?? ''"
                required
            >
                <KInput :id="id" v-model="form.url" type="url" inputmode="url" autocomplete="off" spellcheck="false" :invalid="invalid" />
            </KField>
            <KField
                v-slot="{ id, invalid }"
                :label="__('Fleet token')"
                :help="
                    __(
                        'Made on that site by an administrator under Your profile → Access tokens, with only the fleet permission. It is kept encrypted here and never shown again.',
                    )
                "
                :error="errors.token ?? ''"
                required
            >
                <KInput :id="id" v-model="form.token" type="password" autocomplete="off" spellcheck="false" :invalid="invalid" />
            </KField>
        </form>
        <template #footer>
            <KButton @click="adding = false">{{ __('Cancel') }}</KButton>
            <KButton
                type="submit"
                form="k-fleet-add"
                variant="primary"
                :loading="saving"
                :disabled="!form.name.trim() || !form.url.trim() || !form.token.trim()"
                >{{ __('Add site') }}</KButton
            >
        </template>
    </KModal>
</template>

<style scoped>
.k-fleet__error {
    color: var(--k-danger);
    max-width: 32ch;
}
.k-fleet__actions {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
</style>
