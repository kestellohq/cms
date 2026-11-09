<script setup>
import { computed } from 'vue';
import { Head, Link } from '@kestello/app';
import { useAdmin, useCan, useResource, useUser, __, useExtensions } from '@kestello/sdk';
import { KBadge, KButton, KCard, KEmpty, KRevision, KStat, KStatusBadge, KTable, KTime, KExtension } from '@kestello/ui';
import { auditLabel } from '../../components/audit/events.js';

defineOptions({ layout: 'Admin' });

const can = useCan();
const user = useUser();
const admin = useAdmin();
const system = can('system.manage');

const health = system ? useResource('/system/health') : null;
const queue = system ? useResource('/system/queue', { poll: 30 }) : null;
const schedule = system ? useResource('/system/schedule') : null;
const users = can('users.read') ? useResource('/users', { query: { limit: 5 } }) : null;
const audit = can('audit.read') ? useResource('/audit', { query: { limit: 7 } }) : null;
const notifications = useResource('/me/notifications', { query: { limit: 5 } });
const waiting = can('content.access') ? useResource('/content/waiting', { query: { limit: 8 } }) : null;

// Entries waiting on you: yours sent back, assigned to you, in review for you.
const waitingRows = computed(() => waiting?.data.value ?? []);
const WHY = {
    changes_requested: { label: __('Changes requested'), tone: 'warning' },
    assigned: { label: __('Assigned to you'), tone: 'info' },
    review: { label: __('Waiting for review'), tone: 'accent' },
};
// The card takes a row of its own when something waits, and the rows below move down one.
const off = computed(() => (waitingRows.value.length ? 1 : 0));
const at = (col, row) => `${col}${row + off.value}`;

const problems = computed(() =>
    (health?.data.value?.checks ?? []).filter((c) => c.status !== 'good').sort((a, b) => (a.status === 'critical' ? 0 : 1) - (b.status === 'critical' ? 0 : 1)),
);
const lead = computed(() => problems.value[0] ?? null);
const pending = computed(() => (queue?.data.value?.queues ?? []).reduce((sum, q) => sum + q.pending, 0));
const heartbeat = computed(() => schedule?.meta.value?.heartbeat ?? null);
const name = computed(() => user.value?.username ?? '');
const greeting = computed(() => {
    const hour = new Date().getHours();
    return hour < 12
        ? __('Good morning, {name}', { name: name.value })
        : hour < 18
          ? __('Good afternoon, {name}', { name: name.value })
          : __('Good evening, {name}', { name: name.value });
});

// One sentence that answers "is my site OK?" before anything else.
const status = computed(() => {
    if (!system) return { text: __('Here is what changed since you were last here.'), mark: '' };
    const h = health.data.value;
    if (!h) return { text: __('Checking your site…'), mark: '' };
    if (h.counts.critical)
        return {
            text: __('Your site has a problem.'),
            mark: h.counts.critical === 1 ? __('One thing needs you now.') : __('{n} things need you now.', { n: h.counts.critical }),
        };
    if (h.counts.recommended)
        return {
            text: __('Your site is running.'),
            mark: h.counts.recommended === 1 ? __('One improvement is recommended.') : __('{n} improvements are recommended.', { n: h.counts.recommended }),
        };
    return { text: __('Your site is running well. Nothing needs you.'), mark: '' };
});

const healthTone = computed(() => ({ critical: 'danger', recommended: 'warning', good: 'success' })[health?.data.value?.status] ?? 'neutral');
const healthValue = computed(() => {
    const h = health?.data.value;
    if (!h) return '…';
    if (h.status === 'critical') return __('{n} critical', { n: h.counts.critical });
    if (h.status === 'recommended') return __('{n} to review', { n: h.counts.recommended });
    return __('All clear');
});
// The lead revision already sits above the sheet; the list holds the rest.
const rest = computed(() => problems.value.slice(1, 6));

// Audit events in plain language.
const SUBSYSTEMS = { cache: __('the cache'), session: __('sign-in sessions'), queue: __('background jobs'), mail: __('email'), storage: __('file storage') };
function describe(row) {
    const who = row.actor ? (row.actor.username ?? __('A deleted account')) : __('Kestello');
    const d = row.data ?? {};
    switch (row.event) {
        case 'auth.login':
            return __('{who} signed in', { who });
        case 'auth.logout':
            return __('{who} signed out', { who });
        case 'auth.registered':
            return __('{who} created an account', { who });
        case 'auth.password_changed':
            return __('{who} changed their password', { who });
        case 'auth.password_reset_requested':
            return __('A password reset was requested');
        case 'auth.2fa_enabled':
            return __('{who} turned on two-step sign-in', { who });
        case 'auth.2fa_disabled':
            return __('{who} turned off two-step sign-in', { who });
        case 'auth.session_revoked':
            return __('{who} signed out a device', { who });
        case 'auth.token_created':
            return __('{who} created an API token', { who });
        case 'auth.token_revoked':
            return __('{who} revoked an API token', { who });
        case 'auth.identity_linked':
            return __('{who} linked a sign-in account', { who });
        case 'auth.identity_unlinked':
            return __('{who} unlinked a sign-in account', { who });
        case 'platform.changed':
            return d.reset
                ? __('{who} reset {what} to its defaults', { who, what: SUBSYSTEMS[row.subject_id] ?? row.subject_id })
                : __('{who} switched {what} to {to}', { who, what: SUBSYSTEMS[row.subject_id] ?? row.subject_id, to: d.to });
        case 'queue.retried':
            return __('{who} retried failed jobs', { who });
        case 'queue.forgotten':
            return __('{who} deleted failed jobs', { who });
        case 'schedule.run':
            return __('{who} ran a scheduled task', { who });
        case 'settings.updated':
            return __('{who} changed the site settings', { who });
        case 'users.created':
            return __('{who} added {name}', { who, name: d.username ?? __('a person') });
        case 'users.updated':
            return __('{who} updated an account', { who });
        case 'users.deleted':
            return __('{who} deleted the account of {name}', { who, name: d.username ?? __('a person') });
        case 'profile.updated':
            return __('{who} updated their profile', { who });
        // Never show a raw event key to a client.
        default:
            return __('{who}: {what}', { who, what: auditLabel(row.event) });
    }
}

// ── plugin widgets ───────────────────────────────────────────────────
// $cms->admin->widget('dashboard', …) — each one gets a cell on the sheet,
// with its grid reference continuing below the core rows.
const SPAN = { quarter: 1, half: 2, full: 4 };
const widgetList = useExtensions('widgets', 'dashboard');
const widgets = computed(() => {
    let row = 4 + off.value;
    let col = 0;
    return widgetList.value.map((w) => {
        const span = SPAN[w.size] ?? 2;
        if (col + span > 4) {
            row += 1;
            col = 0;
        }
        const coord = `${'ABCD'[col]}${row}`;
        col += span;
        return { ...w, span, coord };
    });
});
</script>

<template>
    <Head :title="__('Overview')" />
    <header class="k-page-header">
        <div>
            <h1 class="k-page-header__title">{{ greeting }}</h1>
            <p class="k-status-line">
                {{ status.text }}
                <template v-if="status.mark"
                    ><Link v-if="system" href="/admin/system/health" class="k-status-line__mark">{{ status.mark }}</Link
                    ><strong v-else>{{ status.mark }}</strong></template
                >
            </p>
        </div>
        <div class="k-page-header__actions">
            <KButton href="/" icon="arrow-square-out">{{ __('View site') }}</KButton>
        </div>
    </header>

    <KRevision v-if="lead" :number="1">
        <strong>{{ lead.label }}</strong>
        <span>{{ lead.message }}</span>
        <template #action
            ><KButton :variant="lead.status === 'critical' ? 'primary' : 'secondary'" href="/admin/system/health">{{ __('Review') }}</KButton></template
        >
    </KRevision>

    <div v-if="system" class="k-cells" style="--k-cols: 4">
        <KStat coord="A1" :label="__('Site health')" :tone="healthTone" :value="healthValue">
            {{ health.data.value ? __('{good} passed · {recommended} recommended · {critical} critical', health.data.value.counts) : __('Checking…') }}
        </KStat>
        <KStat
            coord="B1"
            :label="__('Background jobs')"
            :tone="queue.data.value?.failed ? 'warning' : 'success'"
            :value="queue.data.value ? (pending === 0 ? __('Idle') : __('{n} waiting', { n: pending })) : '…'"
        >
            {{ queue.data.value ? (queue.data.value.sync ? __('Run right away (no queue)') : __('{count} failed', { count: queue.data.value.failed })) : '' }}
        </KStat>
        <KStat coord="C1" :label="__('Scheduler')" :value="heartbeat ? __('Running') : __('Not running')" :tone="heartbeat ? 'success' : 'danger'">
            <template v-if="heartbeat">{{ __('Last run') }} <KTime :value="heartbeat.at" /></template>
            <template v-else>{{ __('Set up cron or the web runner') }}</template>
        </KStat>
        <KStat coord="D1" :label="__('Kestello')" :value="admin.version">{{ __('Core version') }}</KStat>
    </div>

    <div v-if="waitingRows.length" class="k-cells" style="--k-cols: 4">
        <KCard coord="A2" class="k-span-all" :title="__('Waiting on you')">
            <ul class="k-list">
                <li v-for="row in waitingRows" :key="row.id" class="k-list__item">
                    <div class="k-list__main">
                        <Link :href="`/admin/content/${row.type}/${row.id}`" class="k-link"
                            ><strong>{{ row.title || __('(untitled)') }}</strong></Link
                        >
                        <p class="k-muted k-small">{{ row.type_label }}</p>
                    </div>
                    <KBadge :tone="WHY[row.reason]?.tone ?? 'neutral'">{{ WHY[row.reason]?.label ?? row.reason }}</KBadge>
                    <KTime v-if="row.since" :value="row.since" class="k-list__aside" />
                </li>
            </ul>
        </KCard>
    </div>

    <div class="k-cells" style="--k-cols: 4">
        <KCard v-if="system" :coord="at('A', 2)" class="k-span-2" :title="__('Needs attention')">
            <template #actions
                ><Link href="/admin/system/health" class="k-link">{{ __('Site health') }}</Link></template
            >
            <ul v-if="rest.length" class="k-list">
                <li v-for="check in rest" :key="check.id" class="k-list__item">
                    <div class="k-list__main">
                        <strong>{{ check.label }}</strong>
                        <p class="k-muted">{{ check.message }}</p>
                    </div>
                    <KStatusBadge :status="check.status" />
                </li>
            </ul>
            <KEmpty v-else-if="health.data.value" icon="check-circle" :title="lead ? __('Nothing else needs you.') : __('Nothing needs you.')" />
            <p v-else class="k-muted">{{ __('Running checks…') }}</p>
        </KCard>

        <KCard :coord="system ? at('C', 2) : at('A', 2)" class="k-span-2" :title="audit ? __('Recent activity') : __('Notifications')">
            <ul v-if="audit && audit.data.value?.length" class="k-list">
                <li v-for="row in audit.data.value" :key="row.id" class="k-list__item">
                    <div class="k-list__main">{{ describe(row) }}</div>
                    <KTime :value="row.occurred_at" class="k-list__aside" />
                </li>
            </ul>
            <ul v-else-if="!audit && notifications.data.value?.length" class="k-list">
                <li v-for="item in notifications.data.value" :key="item.id" class="k-list__item">
                    <div class="k-list__main">
                        <strong>{{ item.data?.title ?? item.type }}</strong>
                        <p v-if="item.data?.body" class="k-muted">{{ item.data.body }}</p>
                    </div>
                    <KTime :value="item.created_at" class="k-list__aside" />
                </li>
            </ul>
            <KEmpty v-else icon="clock-counter-clockwise" :title="__('Nothing has happened yet.')" />
        </KCard>

        <KCard v-if="users" :coord="at('A', 3)" class="k-span-2" :title="__('Newest people')" flush>
            <template #actions
                ><Link href="/admin/users" class="k-link">{{ __('All people') }}</Link></template
            >
            <KTable
                :columns="[
                    { key: 'username', label: __('Name') },
                    { key: 'role', label: __('Role') },
                    { key: 'created_at', label: __('Joined'), align: 'end' },
                ]"
                :rows="users.data.value ?? []"
                :loading="users.loading.value"
                :empty="__('No one yet.')"
            >
                <template #cell-username="{ row }"
                    ><strong>{{ row.username }}</strong>
                    <div class="k-muted k-small">{{ row.email }}</div></template
                >
                <template #cell-role="{ row }"
                    ><KBadge>{{ row.role_label ?? row.role }}</KBadge></template
                >
                <template #cell-created_at="{ row }"
                    ><span class="k-muted">{{ row.created_at ? String(row.created_at).slice(0, 10) : '—' }}</span></template
                >
            </KTable>
        </KCard>

        <KCard v-if="audit" :coord="at('C', 3)" class="k-span-2" :title="__('Notifications')">
            <ul v-if="notifications.data.value?.length" class="k-list">
                <li v-for="item in notifications.data.value" :key="item.id" class="k-list__item">
                    <div class="k-list__main">
                        <strong>{{ item.data?.title ?? item.type }}</strong>
                        <p v-if="item.data?.body" class="k-muted">{{ item.data.body }}</p>
                    </div>
                    <KTime :value="item.created_at" class="k-list__aside" />
                </li>
            </ul>
            <KEmpty v-else icon="bell-slash" :title="__('No notifications yet.')" />
        </KCard>
    </div>

    <div v-if="widgets.length" class="k-cells" style="--k-cols: 4">
        <KCard v-for="w in widgets" :key="w.id" :coord="w.coord" :title="w.title" :class="w.span === 4 ? 'k-span-all' : w.span === 2 ? 'k-span-2' : null">
            <KExtension :name="w.component" :owner="w.owner" :context="{}" />
        </KCard>
    </div>
</template>
