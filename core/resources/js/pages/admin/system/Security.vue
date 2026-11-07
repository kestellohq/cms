<script setup>
import { computed } from 'vue';
import { Head, Link } from '@kestello/app';
import { confirm, notify, useApi, useResource, __ } from '@kestello/sdk';
import { KAlert, KBadge, KButton, KCard, KPageHeader, KStat, KTable, KTime } from '@kestello/ui';
import { deviceLabel } from '../../../components/account/device.js';
import { AUDIT_WARN, auditLabel } from '../../../components/audit/events.js';

defineOptions({ layout: 'Admin' });

/**
 * System → Security: who uses two-step sign-in, failed sign-ins, who is
 * signed in where, every access token, and recent account events. Each
 * list ends sessions or tokens in one step; the audit log records it.
 */
const api = useApi();
const overview = useResource('/security');
const sessions = useResource('/security/sessions');
const tokens = useResource('/security/tokens');

const two = computed(() => overview.data.value?.two_factor ?? null);
const failed = computed(() => overview.data.value?.failed_sign_ins ?? null);
const roleLabel = (name) => overview.data.value?.role_labels?.[name] ?? name;
const roleList = computed(() => (two.value?.roles ?? []).map(roleLabel).join(', '));
// (in the script: an ICU plural's closing braces would end a {{ }} in the template)
const people = computed(() => __('{n, plural, one {# person} other {# people}}', { n: new Set((sessions.data.value ?? []).map((s) => s.user.id)).size }));

const twoStat = computed(() => {
    const t = two.value;
    if (!t) return { value: '…', hint: '', tone: 'neutral' };
    if (t.required === 0) return { value: `${t.everyone.covered} / ${t.everyone.total}`, hint: __('people use it'), tone: 'neutral' };
    return {
        value: `${t.covered} / ${t.required}`,
        hint: __('expected to use it have it on'),
        tone: t.covered === t.required ? 'success' : 'warning',
    };
});

// Why a sign-in failed, in words. "credentials" names an account only when it exists.
const reason = (row) =>
    ({
        credentials: row.user ? __('Wrong password') : __('No such account'),
        throttled: __('Too many attempts'),
        two_factor: __('Wrong two-step code'),
        inactive: __('Account switched off'),
        external: __('Sign-in provider failed'),
        external_refused: __('Sign-in provider not allowed'),
    })[row.reason] ?? row.reason;

async function signOut(row) {
    const ok = await confirm({
        title: __('Sign out this session?'),
        message: __('{name} is signed out on {device} and must sign in again there.', { name: row.user.username, device: deviceLabel(row.user_agent, __) }),
        confirmLabel: __('Sign out'),
        danger: true,
    });
    if (!ok) return;
    try {
        await api.delete(`/security/sessions/${row.user.id}/${row.id}`);
        sessions.reload({ silent: true });
        overview.reload({ silent: true });
        notify.success(__('Signed out.'));
    } catch (e) {
        notify.error(e);
    }
}
async function signOutEverywhere(row) {
    const ok = await confirm({
        title: __('Sign {name} out everywhere?', { name: row.user.username }),
        message: __('Every browser and device signed in to this account is signed out. Their access tokens keep working: revoke those below.'),
        confirmLabel: __('Sign out everywhere'),
        danger: true,
    });
    if (!ok) return;
    try {
        const { data } = await api.delete(`/security/sessions/${row.user.id}`);
        sessions.reload({ silent: true });
        overview.reload({ silent: true });
        notify.success(__('{n, plural, one {# session ended.} other {# sessions ended.}}', { n: data.signed_out }));
    } catch (e) {
        notify.error(e);
    }
}
async function revoke(token) {
    const ok = await confirm({
        title: __('Revoke “{name}”?', { name: token.name }),
        message: __('Whatever uses this token of {person} stops working at once. This cannot be undone.', { person: token.user.username ?? '?' }),
        confirmLabel: __('Revoke'),
        danger: true,
    });
    if (!ok) return;
    try {
        await api.delete(`/security/tokens/${token.id}`);
        tokens.reload({ silent: true });
        overview.reload({ silent: true });
        notify.success(__('Token revoked.'));
    } catch (e) {
        notify.error(e);
    }
}
</script>

<template>
    <Head :title="__('Security')" />
    <KPageHeader
        :title="__('Security')"
        :description="__('Who can get in, and how: two-step sign-in, failed attempts, who is signed in where, and access tokens.')"
    />

    <KAlert v-if="overview.error.value" tone="danger">{{ overview.error.value.message }}</KAlert>

    <div class="k-grid k-grid--4">
        <KStat :label="__('Two-step sign-in')" icon="fingerprint" :value="twoStat.value" :tone="twoStat.tone">{{ twoStat.hint }}</KStat>
        <KStat
            :label="__('Failed sign-ins today')"
            icon="shield-warning"
            :value="failed ? failed.day : '…'"
            :tone="failed && failed.day >= 10 ? 'warning' : 'neutral'"
        >
            <template v-if="failed">{{ __('{n} in the last 7 days', { n: failed.week }) }}</template>
        </KStat>
        <KStat :label="__('Signed-in sessions')" icon="devices" :value="sessions.data.value ? sessions.data.value.length : '…'">
            <template v-if="sessions.data.value">{{ people }}</template>
        </KStat>
        <KStat :label="__('Access tokens')" icon="key" :value="tokens.data.value ? tokens.data.value.length : '…'" />
    </div>

    <KCard v-if="two" :title="__('Two-step sign-in')">
        <template v-if="two.required === 0">
            <p class="k-muted">
                {{
                    __('No role is expected to use two-step sign-in. {n} of {total} people use it anyway.', {
                        n: two.everyone.covered,
                        total: two.everyone.total,
                    })
                }}
            </p>
        </template>
        <template v-else-if="two.missing.length">
            <p>
                {{
                    __('People with the role {roles} are expected to use two-step sign-in. These have not turned it on yet. They can, under Your profile.', {
                        roles: roleList,
                    })
                }}
            </p>
            <ul class="k-security-people">
                <li v-for="p in two.missing" :key="p.id">
                    <Link :href="`/admin/users/${p.id}`">{{ p.username }}</Link> <span class="k-muted k-small">{{ roleLabel(p.role) }}</span>
                </li>
            </ul>
        </template>
        <p v-else class="k-muted">{{ __('Everyone with the role {roles} uses two-step sign-in.', { roles: roleList }) }}</p>
    </KCard>

    <KCard v-if="failed" :title="__('Failed sign-ins')" flush>
        <template #actions>
            <Link href="/admin/system/audit?area=auth.*" class="k-small">{{ __('Open the audit log') }}</Link>
        </template>
        <div class="k-security-failed">
            <section v-if="failed.top_ips.length" class="k-security-failed__ips" :aria-label="__('Where they came from')">
                <h3 class="k-security-sub">{{ __('Most attempts, last 7 days') }}</h3>
                <ol>
                    <li v-for="ip in failed.top_ips" :key="ip.ip">
                        <span class="k-mono-input">{{ ip.ip }}</span> <span class="k-muted k-num">{{ ip.count }}</span>
                    </li>
                </ol>
            </section>
            <KTable
                class="k-security-failed__list"
                :columns="[
                    { key: 'occurred_at', label: __('When') },
                    { key: 'user', label: __('Account') },
                    { key: 'reason', label: __('Why') },
                    { key: 'ip', label: __('Address') },
                ]"
                :rows="failed.recent"
                :empty="__('No failed sign-ins')"
            >
                <template #cell-occurred_at="{ row }"><KTime :value="row.occurred_at" /></template>
                <template #cell-user="{ row }">
                    <Link v-if="row.user" :href="`/admin/users/${row.user.id}`">{{ row.user.username }}</Link>
                    <span v-else class="k-muted">{{ __('Unknown') }}</span>
                </template>
                <template #cell-reason="{ row }">{{ reason(row) }}</template>
                <template #cell-ip="{ row }"
                    ><span class="k-mono-input">{{ row.ip ?? '—' }}</span></template
                >
            </KTable>
        </div>
    </KCard>

    <KCard :title="__('Signed-in sessions')" flush>
        <KAlert v-if="sessions.meta.value?.supported === false" tone="info">
            {{
                __(
                    'This server keeps sessions in files, which cannot be listed. Switch sessions to the database or Redis under Infrastructure to see who is signed in.',
                )
            }}
        </KAlert>
        <KTable
            v-else
            :columns="[
                { key: 'user', label: __('Person') },
                { key: 'device', label: __('Device') },
                { key: 'ip', label: __('Address') },
                { key: 'last_active_at', label: __('Last active') },
                { key: 'created_at', label: __('Signed in') },
                { key: 'actions', label: __('Actions'), align: 'end' },
            ]"
            :rows="sessions.data.value ?? []"
            :loading="sessions.loading.value"
            :empty="__('Nobody is signed in.')"
        >
            <template #cell-user="{ row }"
                ><Link :href="`/admin/users/${row.user.id}`">{{ row.user.username }}</Link></template
            >
            <template #cell-device="{ row }"
                >{{ deviceLabel(row.user_agent, __) }} <KBadge v-if="row.current" tone="accent">{{ __('This session') }}</KBadge></template
            >
            <template #cell-ip="{ row }"
                ><span class="k-mono-input">{{ row.ip || '—' }}</span></template
            >
            <template #cell-last_active_at="{ row }"><KTime :value="row.last_active_at" /></template>
            <template #cell-created_at="{ row }"><KTime :value="row.created_at" /></template>
            <template #cell-actions="{ row }">
                <span v-if="!row.current" class="k-security-actions">
                    <KButton size="sm" variant="ghost" icon="sign-out" @click="signOut(row)">{{ __('Sign out') }}</KButton>
                    <KButton size="sm" variant="ghost" @click="signOutEverywhere(row)">{{ __('Everywhere') }}</KButton>
                </span>
            </template>
        </KTable>
    </KCard>

    <KCard :title="__('Access tokens')" flush>
        <KTable
            :columns="[
                { key: 'name', label: __('Name') },
                { key: 'user', label: __('Person') },
                { key: 'scopes', label: __('Can') },
                { key: 'last_used_at', label: __('Last used') },
                { key: 'expires_at', label: __('Expires') },
                { key: 'actions', label: __('Actions'), align: 'end' },
            ]"
            :rows="tokens.data.value ?? []"
            :loading="tokens.loading.value"
            :empty="__('Nobody has made an access token.')"
        >
            <template #cell-name="{ row }"
                ><strong>{{ row.name }}</strong></template
            >
            <template #cell-user="{ row }"
                ><Link :href="`/admin/users/${row.user.id}`">{{ row.user.username ?? '?' }}</Link></template
            >
            <template #cell-scopes="{ row }">
                <span class="k-security-scopes"
                    ><KBadge v-for="s in row.scopes" :key="s" :tone="s === 'admin' ? 'warning' : 'neutral'">{{ s }}</KBadge></span
                >
            </template>
            <template #cell-last_used_at="{ row }">
                <template v-if="row.last_used_at"
                    ><KTime :value="row.last_used_at" /> <span class="k-muted k-small k-mono-input">{{ row.last_used_ip }}</span></template
                >
                <span v-else class="k-muted">{{ __('Never') }}</span>
            </template>
            <template #cell-expires_at="{ row }">
                <KBadge v-if="row.expired" tone="neutral">{{ __('Expired') }}</KBadge>
                <KTime v-else-if="row.expires_at" :value="row.expires_at" />
                <span v-else class="k-muted">{{ __('Never') }}</span>
            </template>
            <template #cell-actions="{ row }">
                <KButton size="sm" variant="ghost" icon="trash" @click="revoke(row)">{{ __('Revoke') }}</KButton>
            </template>
        </KTable>
    </KCard>

    <KCard v-if="overview.data.value" :title="__('Recent account events')" flush>
        <template #actions>
            <Link href="/admin/system/audit?area=auth.*" class="k-small">{{ __('Open the audit log') }}</Link>
        </template>
        <KTable
            :columns="[
                { key: 'occurred_at', label: __('When') },
                { key: 'actor', label: __('Who') },
                { key: 'event', label: __('What') },
                { key: 'ip', label: __('Address') },
            ]"
            :rows="overview.data.value.events"
            :empty="__('Nothing yet.')"
        >
            <template #cell-occurred_at="{ row }"><KTime :value="row.occurred_at" /></template>
            <template #cell-actor="{ row }">
                <Link v-if="row.actor?.username" :href="`/admin/users/${row.actor.id}`">{{ row.actor.username }}</Link>
                <span v-else-if="row.actor" class="k-muted">{{ __('A deleted account') }}</span>
                <span v-else class="k-muted">{{ __('The system') }}</span>
            </template>
            <template #cell-event="{ row }">
                <KBadge v-if="AUDIT_WARN.has(row.event)" tone="warning">{{ auditLabel(row.event) }}</KBadge>
                <template v-else>{{ auditLabel(row.event) }}</template>
            </template>
            <template #cell-ip="{ row }"
                ><span class="k-mono-input">{{ row.ip ?? '—' }}</span></template
            >
        </KTable>
    </KCard>
</template>
