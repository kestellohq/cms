<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Head } from '@kestello/app';
import { ApiError, confirm, notify, useApi, useExtensions, useResource, __ } from '@kestello/sdk';
import { KAlert, KBadge, KButton, KCard, KEmpty, KField, KInput, KModal, KPageHeader, KSelect, KSwitch, KTable, KTabs, KTime, KExtension } from '@kestello/ui';
import { deviceLabel } from '../../components/account/device.js';

defineOptions({ layout: 'Admin' });

/** Your own account: details, sign-in security, tokens, linked sign-ins, notifications. */
const api = useApi();
// Plugins add tabs with $cms->admin->slot('profile.tabs', …, label: '…'); context: { user }.
const profileTabs = useExtensions('slots', 'profile.tabs');
const pluginTabs = computed(() => profileTabs.value.map((e) => ({ id: `x-${e.id.replace(/[^A-Za-z0-9-]/g, '-')}`, label: e.label, ext: e })));
const pluginTab = computed(() => pluginTabs.value.find((t) => t.id === tab.value) ?? null);
const TABS = ['account', 'security', 'tokens', 'connections', 'notifications'];
const initial = typeof window !== 'undefined' ? new URLSearchParams(window.location.search).get('tab') : null;
const tab = ref(initial && (TABS.includes(initial) || initial.startsWith('x-')) ? initial : 'account');
watch(tab, (value) => {
    const url = new URL(window.location.href);
    url.searchParams.set('tab', value);
    window.history.replaceState(window.history.state, '', url);
});

const me = useResource('/me');
const ROLE_LABELS = { admin: __('Administrator'), user: __('User') };
const roleLabel = computed(() => ROLE_LABELS[me.data.value?.role] ?? me.data.value?.role ?? '—');
const fieldErrors = (e) => (e instanceof ApiError && e.status === 422 ? e.fieldErrors() : null);

// ── account ──────────────────────────────────────────────────────────
const account = reactive({ email: '', bio: '', locale: '' });
const accountErrors = ref({});
const savingAccount = ref(false);
watch(
    () => me.data.value,
    (u) => {
        if (u) Object.assign(account, { email: u.email, bio: u.bio ?? '', locale: u.locale ?? '' });
    },
    { immediate: true },
);
const accountDirty = computed(
    () =>
        me.data.value &&
        (account.email !== me.data.value.email || account.bio !== (me.data.value.bio ?? '') || account.locale !== (me.data.value.locale ?? '')),
);
// the site's languages; with only one there is nothing to choose
const languageOptions = computed(() => [
    { value: '', label: __("The site's language") },
    ...(me.data.value?.languages ?? []).map((l) => ({ value: l.code, label: l.name })),
]);

async function saveAccount() {
    savingAccount.value = true;
    accountErrors.value = {};
    try {
        const email = account.email.trim();
        const changed = email !== me.data.value?.email;
        await api.patch('/me', { email, bio: account.bio, locale: account.locale });
        // a new address is only used once its link is opened: say where to look
        notify.success(changed ? __('Check {email} for a link to confirm it.', { email }) : __('Your profile was saved.'));
        await me.reload({ silent: true });
    } catch (e) {
        accountErrors.value = fieldErrors(e) ?? {};
        if (!fieldErrors(e)) notify.error(e);
    } finally {
        savingAccount.value = false;
    }
}

// ── password ─────────────────────────────────────────────────────────
const pw = reactive({ current_password: '', password: '' });
const pwErrors = ref({});
const savingPw = ref(false);

async function changePassword() {
    savingPw.value = true;
    pwErrors.value = {};
    try {
        const { data } = await api.put('/me/password', { ...pw });
        Object.assign(pw, { current_password: '', password: '' });
        notify.success(
            data.sessions_revoked || data.tokens_revoked
                ? __(
                      'Password changed. {s, plural, one {# other session was signed out} other {# other sessions were signed out}} and {t, plural, one {# token revoked} other {# tokens revoked}}.',
                      { s: data.sessions_revoked, t: data.tokens_revoked },
                  )
                : __('Password changed.'),
        );
        sessions.reload({ silent: true });
    } catch (e) {
        pwErrors.value = fieldErrors(e) ?? {};
        if (!fieldErrors(e)) notify.error(e);
    } finally {
        savingPw.value = false;
    }
}

// ── two-factor ───────────────────────────────────────────────────────
const twoFactor = useResource('/me/2fa');
const enrolment = ref(null); // { secret, otpauth_uri }
const code = ref('');
const codeError = ref('');
const recoveryCodes = ref(null);
const busy2fa = ref(false);
const groupedSecret = computed(() => (enrolment.value?.secret ?? '').replace(/(.{4})/g, '$1 ').trim());

async function beginTwoFactor() {
    busy2fa.value = true;
    try {
        enrolment.value = (await api.post('/me/2fa/totp', {})).data;
        code.value = '';
        codeError.value = '';
    } catch (e) {
        notify.error(e);
    } finally {
        busy2fa.value = false;
    }
}

async function confirmTwoFactor() {
    busy2fa.value = true;
    codeError.value = '';
    try {
        const { data } = await api.post('/me/2fa/totp/confirm', { code: code.value.replace(/\s/g, '') });
        enrolment.value = null;
        recoveryCodes.value = data.recovery_codes;
        await Promise.all([twoFactor.reload({ silent: true }), me.reload({ silent: true })]);
    } catch (e) {
        codeError.value = fieldErrors(e)?.code ?? e.message;
    } finally {
        busy2fa.value = false;
    }
}

async function disableTwoFactor() {
    if (
        !(await confirm({
            title: __('Turn off two-step sign-in?'),
            message: __('Signing in will need only your password. Your recovery codes stop working.'),
            confirmLabel: __('Turn off'),
            danger: true,
        }))
    )
        return;
    try {
        await api.delete('/me/2fa');
        notify.success(__('Two-step sign-in is off.'));
        await Promise.all([twoFactor.reload({ silent: true }), me.reload({ silent: true })]);
    } catch (e) {
        notify.error(e);
    }
}

async function newRecoveryCodes() {
    if (!(await confirm({ title: __('Make new recovery codes?'), message: __('Your current codes stop working.'), confirmLabel: __('Make new codes') })))
        return;
    try {
        recoveryCodes.value = (await api.post('/me/2fa/recovery-codes', {})).data.recovery_codes;
        await twoFactor.reload({ silent: true });
    } catch (e) {
        notify.error(e);
    }
}

function copyCodes() {
    navigator.clipboard?.writeText(recoveryCodes.value.join('\n')).then(
        () => notify.success(__('Copied.')),
        () => {},
    );
}

// ── sessions ─────────────────────────────────────────────────────────
const sessions = useResource('/me/sessions');

const device = (agent) => deviceLabel(agent, __);

async function revokeSession(row) {
    try {
        await api.delete(`/me/sessions/${row.id}`);
        notify.success(__('That device was signed out.'));
        await sessions.reload({ silent: true });
    } catch (e) {
        notify.error(e);
    }
}

async function revokeOthers() {
    if (
        !(await confirm({
            title: __('Sign out everywhere else?'),
            message: __('Every other browser and device signed in to your account is signed out. This one stays signed in.'),
            confirmLabel: __('Sign out others'),
        }))
    )
        return;
    try {
        await api.delete('/me/sessions');
        notify.success(__('Signed out everywhere else.'));
        await sessions.reload({ silent: true });
    } catch (e) {
        notify.error(e);
    }
}

// ── tokens ───────────────────────────────────────────────────────────
const tokens = useResource('/me/tokens');
const creatingToken = ref(false);
const tokenForm = reactive({ name: '', scopes: [], expires_in_days: '90' });
const tokenErrors = ref({});
const newToken = ref(null);
const savingToken = ref(false);
const EXPIRY = [
    { value: '7', label: __('7 days') },
    { value: '30', label: __('30 days') },
    { value: '90', label: __('90 days') },
    { value: '365', label: __('One year') },
    { value: 'never', label: __('Never') },
];

function openToken() {
    Object.assign(tokenForm, { name: '', scopes: [], expires_in_days: '90' });
    tokenErrors.value = {};
    creatingToken.value = true;
}

function toggleScope(name, on) {
    tokenForm.scopes = on ? [...tokenForm.scopes, name] : tokenForm.scopes.filter((s) => s !== name);
}

async function createToken() {
    savingToken.value = true;
    tokenErrors.value = {};
    try {
        const { data } = await api.post('/me/tokens', {
            name: tokenForm.name.trim(),
            scopes: tokenForm.scopes,
            expires_in_days: tokenForm.expires_in_days === 'never' ? null : Number(tokenForm.expires_in_days),
        });
        creatingToken.value = false;
        newToken.value = data;
        await tokens.reload({ silent: true });
    } catch (e) {
        tokenErrors.value = fieldErrors(e) ?? {};
        if (!fieldErrors(e)) notify.error(e);
    } finally {
        savingToken.value = false;
    }
}

async function revokeToken(row) {
    if (
        !(await confirm({
            title: __('Revoke {name}?', { name: row.name }),
            message: __('Anything using this token stops working immediately.'),
            confirmLabel: __('Revoke'),
            danger: true,
        }))
    )
        return;
    try {
        await api.delete(`/me/tokens/${row.id}`);
        notify.success(__('Token revoked.'));
        await tokens.reload({ silent: true });
    } catch (e) {
        notify.error(e);
    }
}

function copyToken() {
    navigator.clipboard?.writeText(newToken.value.token).then(
        () => notify.success(__('Copied.')),
        () => {},
    );
}

// ── connected sign-ins ───────────────────────────────────────────────
const identities = useResource('/me/identities');
// Core signs in with email and password only; this tab appears once a plugin adds a provider (or an account is still linked).
const hasConnections = computed(() => !!(identities.data.value?.length || identities.meta.value?.available?.length));
watch(identities.data, () => {
    if (tab.value === 'connections' && !hasConnections.value) tab.value = 'account';
});

async function unlink(row) {
    if (
        !(await confirm({
            title: __('Disconnect {provider}?', { provider: row.label }),
            message: __('You will no longer be able to sign in with {provider}.', { provider: row.label }),
            confirmLabel: __('Disconnect'),
        }))
    )
        return;
    try {
        await api.delete(`/me/identities/${row.id}`);
        notify.success(__('{provider} was disconnected.', { provider: row.label }));
        await identities.reload({ silent: true });
    } catch (e) {
        notify.error(e);
    }
}

// ── notifications ────────────────────────────────────────────────────
const prefs = useResource('/me/notification-preferences');
const channels = computed(() => [...new Set((prefs.data.value ?? []).flatMap((t) => t.channels.map((c) => c.channel)))]);
const CHANNEL_LABELS = { database: __('In the admin'), mail: __('Email') };

async function setPref(type, channel, enabled) {
    try {
        await api.put('/me/notification-preferences', { preferences: [{ type, channel, enabled }] });
        await prefs.reload({ silent: true });
    } catch (e) {
        notify.error(e);
    }
}
// ICU plurals live in the script: their closing braces would end a {{ }} in the template
const codesLeft = (n) => __('{n, plural, one {# recovery code left} other {# recovery codes left}}', { n });
</script>

<template>
    <Head :title="__('Your profile')" />
    <KPageHeader
        :title="me.data.value?.username ?? __('Your profile')"
        :description="__('Your account, how you sign in, and what Kestello tells you about.')"
    />

    <KTabs
        v-model="tab"
        :label="__('Profile sections')"
        :tabs="[
            { id: 'account', label: __('Account') },
            { id: 'security', label: __('Sign-in & security') },
            { id: 'tokens', label: __('Access tokens'), count: tokens.data.value?.length || undefined },
            ...(hasConnections ? [{ id: 'connections', label: __('Connected sign-ins') }] : []),
            { id: 'notifications', label: __('Notifications') },
            ...pluginTabs.map(({ id, label }) => ({ id, label })),
        ]"
    />

    <!-- account -->
    <div v-if="tab === 'account'" class="k-cells" style="--k-cols: 3">
        <KCard coord="A1" class="k-span-2" :title="__('Details')">
            <form id="k-account" class="k-form" @submit.prevent="saveAccount">
                <KField v-slot="{ id }" :label="__('Username')" :help="__('You sign in with it. Ask an administrator to change it.')">
                    <KInput :id="id" :model-value="me.data.value?.username ?? ''" disabled />
                </KField>
                <KField
                    v-slot="{ id, invalid }"
                    :label="__('Email')"
                    :help="__('Password resets go here. Changing it asks for your password, and the new address must confirm it.')"
                    :error="accountErrors.email ?? ''"
                >
                    <KInput :id="id" v-model="account.email" type="email" autocomplete="email" :invalid="invalid" />
                    <p v-if="me.data.value?.pending_email" class="k-muted">
                        {{ __('Waiting for {email} to be confirmed: open the link sent there.', { email: me.data.value.pending_email }) }}
                    </p>
                </KField>
                <KField
                    v-slot="{ id }"
                    :label="__('About you')"
                    :help="__('Optional. Themes may show it next to what you publish.')"
                    :error="accountErrors.bio ?? ''"
                >
                    <textarea :id="id" v-model="account.bio" class="k-input k-textarea" rows="3" maxlength="2000"></textarea>
                </KField>
                <KField
                    v-if="(me.data.value?.languages ?? []).length > 1"
                    v-slot="{ id }"
                    :label="__('Language')"
                    :help="__('The emails the site sends you are written in it.')"
                    :error="accountErrors.locale ?? ''"
                >
                    <KSelect :id="id" v-model="account.locale" :options="languageOptions" />
                </KField>
            </form>
            <template #footer>
                <div class="k-form__actions">
                    <KButton type="submit" form="k-account" variant="primary" :disabled="!accountDirty" :loading="savingAccount">{{
                        __('Save profile')
                    }}</KButton>
                </div>
            </template>
        </KCard>
        <KCard coord="C1" :title="__('Your access')">
            <dl class="k-facts">
                <div>
                    <dt>{{ __('Role') }}</dt>
                    <dd>{{ roleLabel }}</dd>
                </div>
                <div>
                    <dt>{{ __('Two-step sign-in') }}</dt>
                    <dd>{{ me.data.value?.auth.two_factor.enabled ? __('On') : __('Off') }}</dd>
                </div>
                <div>
                    <dt>{{ __('Last signed in') }}</dt>
                    <dd><KTime :value="me.data.value?.last_login_at ?? null" /></dd>
                </div>
            </dl>
        </KCard>
    </div>

    <!-- security -->
    <div v-else-if="tab === 'security'" class="k-cells" style="--k-cols: 3">
        <KCard
            coord="A1"
            class="k-span-2"
            :title="__('Two-step sign-in')"
            :description="__('After your password, enter a code from an authenticator app. A stolen password alone is then not enough.')"
        >
            <KAlert v-if="me.data.value?.auth.two_factor.expected && !twoFactor.data.value?.enabled" tone="warning">{{
                __('Your role is expected to use two-step sign-in. Turn it on now.')
            }}</KAlert>

            <template v-if="twoFactor.data.value?.enabled">
                <p class="k-status-row">
                    <KBadge tone="success" dot>{{ __('On') }}</KBadge>
                    <span class="k-muted"
                        >{{ __('Since') }} <KTime :value="twoFactor.data.value.enabled_at" /> ·
                        {{ codesLeft(twoFactor.data.value.recovery_codes_remaining) }}</span
                    >
                </p>
                <div class="k-button-row">
                    <KButton icon="key" @click="newRecoveryCodes">{{ __('New recovery codes') }}</KButton>
                    <KButton variant="danger" @click="disableTwoFactor">{{ __('Turn off') }}</KButton>
                </div>
            </template>
            <template v-else-if="enrolment">
                <ol class="k-steps">
                    <li>{{ __('Open your authenticator app (1Password, Google Authenticator, Authy…) and add an account.') }}</li>
                    <li>
                        {{ __('Enter this setup key, or on a phone tap Open in app:') }}
                        <div class="k-secret">
                            <code>{{ groupedSecret }}</code> <a :href="enrolment.otpauth_uri" class="k-link">{{ __('Open in app') }}</a>
                        </div>
                    </li>
                    <li>{{ __('Type the six-digit code the app shows.') }}</li>
                </ol>
                <form class="k-input-row" @submit.prevent="confirmTwoFactor">
                    <KField v-slot="{ id, invalid }" :label="__('Code')" :error="codeError">
                        <KInput
                            :id="id"
                            v-model="code"
                            inputmode="numeric"
                            autocomplete="one-time-code"
                            maxlength="7"
                            class="k-code-input"
                            :invalid="invalid"
                        />
                    </KField>
                    <KButton type="submit" variant="primary" :disabled="code.replace(/\s/g, '').length < 6" :loading="busy2fa">{{ __('Turn on') }}</KButton>
                    <KButton variant="ghost" @click="enrolment = null">{{ __('Cancel') }}</KButton>
                </form>
            </template>
            <div v-else-if="twoFactor.data.value">
                <KButton variant="primary" icon="shield-check" :loading="busy2fa" @click="beginTwoFactor">{{ __('Set up two-step sign-in') }}</KButton>
            </div>
        </KCard>

        <KCard coord="C1" :title="__('Password')">
            <form id="k-password" class="k-form" @submit.prevent="changePassword">
                <KField v-slot="{ id, invalid }" :label="__('Current password')" :error="pwErrors.current_password ?? ''">
                    <KInput :id="id" v-model="pw.current_password" type="password" autocomplete="current-password" :invalid="invalid" />
                </KField>
                <KField
                    v-slot="{ id, invalid }"
                    :label="__('New password')"
                    :help="__('Long beats clever: three or four unrelated words work well.')"
                    :error="pwErrors.password ?? ''"
                >
                    <KInput :id="id" v-model="pw.password" type="password" autocomplete="new-password" :invalid="invalid" />
                </KField>
                <div>
                    <KButton type="submit" :disabled="!pw.current_password || !pw.password" :loading="savingPw">{{ __('Change password') }}</KButton>
                </div>
            </form>
        </KCard>

        <KCard coord="A2" class="k-span-all" :title="__('Where you are signed in')" flush>
            <template v-if="(sessions.data.value?.length ?? 0) > 1" #actions
                ><KButton size="sm" @click="revokeOthers">{{ __('Sign out everywhere else') }}</KButton></template
            >
            <KTable
                v-if="sessions.meta.value?.supported !== false"
                :columns="[
                    { key: 'device', label: __('Device') },
                    { key: 'ip', label: __('Network address') },
                    { key: 'last_active_at', label: __('Last active') },
                    { key: 'actions', label: '', align: 'end', width: '1%' },
                ]"
                :rows="sessions.data.value ?? []"
                :loading="sessions.loading.value"
                :empty="__('No sessions.')"
            >
                <template #cell-device="{ row }"
                    ><strong>{{ device(row.user_agent) }}</strong> <KBadge v-if="row.current" tone="accent">{{ __('This device') }}</KBadge></template
                >
                <template #cell-ip="{ row }"
                    ><code>{{ row.ip || '—' }}</code></template
                >
                <template #cell-last_active_at="{ row }"><KTime :value="row.last_active_at" /></template>
                <template #cell-actions="{ row }"
                    ><KButton v-if="!row.current" size="sm" variant="ghost" @click="revokeSession(row)">{{ __('Sign out') }}</KButton
                    ><span v-else class="k-sr-only">{{ __('Current session') }}</span></template
                >
            </KTable>
            <div v-else class="k-card__body">
                <p class="k-muted">
                    {{
                        __(
                            'This server keeps sessions in files, which cannot be listed. Switch sessions to the database or Redis under Infrastructure to see your devices here.',
                        )
                    }}
                </p>
            </div>
        </KCard>
    </div>

    <!-- tokens -->
    <div v-else-if="tab === 'tokens'" class="k-cells" style="--k-cols: 1">
        <KCard
            coord="A1"
            :title="__('Access tokens')"
            :description="__('Let scripts and other apps use the API as you, limited to the permissions you pick. Treat a token like a password.')"
            flush
        >
            <template #actions
                ><KButton size="sm" variant="primary" icon="plus" @click="openToken">{{ __('Create token') }}</KButton></template
            >
            <KTable
                :columns="[
                    { key: 'name', label: __('Name') },
                    { key: 'scopes', label: __('Permissions') },
                    { key: 'last_used_at', label: __('Last used') },
                    { key: 'expires_at', label: __('Expires') },
                    { key: 'actions', label: '', align: 'end', width: '1%' },
                ]"
                :rows="tokens.data.value ?? []"
                :loading="tokens.loading.value"
                :empty="__('You have no access tokens.')"
            >
                <template #cell-name="{ row }"
                    ><strong>{{ row.name }}</strong>
                    <div class="k-muted k-small">{{ __('Created') }} {{ String(row.created_at ?? '').slice(0, 10) }}</div></template
                >
                <template #cell-scopes="{ row }"
                    ><span class="k-chips"
                        ><code v-for="s in row.scopes" :key="s">{{ s }}</code></span
                    ></template
                >
                <template #cell-last_used_at="{ row }"><KTime :value="row.last_used_at" /></template>
                <template #cell-expires_at="{ row }"
                    ><span v-if="row.expires_at">{{ String(row.expires_at).slice(0, 10) }}</span
                    ><span v-else class="k-muted">{{ __('Never') }}</span></template
                >
                <template #cell-actions="{ row }"
                    ><KButton size="sm" variant="ghost" @click="revokeToken(row)">{{ __('Revoke') }}</KButton></template
                >
            </KTable>
        </KCard>
    </div>

    <!-- connected sign-ins -->
    <div v-else-if="tab === 'connections'" class="k-cells" style="--k-cols: 1">
        <KCard coord="A1" :title="__('Connected sign-ins')" :description="__('Other accounts you can sign in with instead of your password.')">
            <ul v-if="identities.data.value?.length" class="k-list">
                <li v-for="row in identities.data.value" :key="row.id" class="k-list__item">
                    <div class="k-list__main">
                        <strong>{{ row.label }}</strong>
                        <p class="k-muted">{{ row.email ?? '' }} · {{ __('last used') }} <KTime :value="row.last_used_at" /></p>
                    </div>
                    <KButton
                        size="sm"
                        :disabled="identities.data.value.length === 1 && !identities.meta.value?.has_password"
                        :title="
                            identities.data.value.length === 1 && !identities.meta.value?.has_password
                                ? __('Set a password first, or you could not sign in.')
                                : ''
                        "
                        @click="unlink(row)"
                        >{{ __('Disconnect') }}</KButton
                    >
                </li>
            </ul>
            <KEmpty v-else-if="identities.data.value" icon="link" :title="__('No connected sign-ins.')" />
            <div v-if="identities.meta.value?.available?.length" class="k-button-row">
                <KButton v-for="p in identities.meta.value.available" :key="p.id" :href="p.connect_url" icon="link">{{
                    __('Connect {provider}', { provider: p.label })
                }}</KButton>
            </div>
        </KCard>
    </div>

    <!-- notifications -->
    <div v-else-if="tab === 'notifications'" class="k-cells" style="--k-cols: 1">
        <KCard coord="A1" :title="__('What Kestello tells you about')" :description="__('Choose where each kind of message reaches you.')" flush>
            <KTable
                :columns="[
                    { key: 'label', label: __('Message') },
                    ...channels.map((c) => ({ key: `ch-${c}`, label: CHANNEL_LABELS[c] ?? c, align: 'end', width: '1%' })),
                ]"
                :rows="prefs.data.value ?? []"
                row-key="type"
                :loading="prefs.loading.value"
                :empty="__('There are no kinds of notification yet.')"
            >
                <template #cell-label="{ row }"
                    ><strong>{{ row.label }}</strong>
                    <div v-if="row.description" class="k-muted k-small">{{ row.description }}</div></template
                >
                <template v-for="c in channels" :key="c" #[`cell-ch-${c}`]="{ row }">
                    <KSwitch
                        :model-value="row.channels.find((x) => x.channel === c)?.enabled ?? false"
                        :label="`${row.label}: ${CHANNEL_LABELS[c] ?? c}`"
                        hide-label
                        @update:model-value="setPref(row.type, c, $event)"
                    />
                </template>
            </KTable>
        </KCard>
    </div>
    <!-- plugin tabs -->
    <KExtension
        v-else-if="pluginTab && me.data.value"
        :key="pluginTab.id"
        :name="pluginTab.ext.component"
        :owner="pluginTab.ext.owner"
        :context="{ user: me.data.value }"
    />

    <!-- create token -->
    <KModal :open="creatingToken" :title="__('Create an access token')" size="lg" @close="creatingToken = false">
        <form id="k-token" class="k-form" @submit.prevent="createToken">
            <KField
                v-slot="{ id, invalid }"
                :label="__('Name')"
                :help="__('What will use it, so you recognise it later.')"
                :error="tokenErrors.name ?? ''"
                required
            >
                <KInput :id="id" v-model="tokenForm.name" autocomplete="off" :invalid="invalid" />
            </KField>
            <KField v-slot="{ id }" :label="__('Expires')">
                <KSelect :id="id" v-model="tokenForm.expires_in_days" :options="EXPIRY" />
            </KField>
            <fieldset class="k-fieldset">
                <legend class="k-field__label">{{ __('Permissions') }}</legend>
                <p v-if="tokenErrors.scopes" class="k-field__error">{{ tokenErrors.scopes }}</p>
                <label v-for="s in tokens.meta.value?.scopes ?? []" :key="s.name" class="k-option">
                    <input type="checkbox" :checked="tokenForm.scopes.includes(s.name)" @change="toggleScope(s.name, $event.target.checked)" />
                    <span
                        ><code>{{ s.name }}</code> {{ s.description }}</span
                    >
                </label>
            </fieldset>
        </form>
        <template #footer>
            <KButton @click="creatingToken = false">{{ __('Cancel') }}</KButton>
            <KButton type="submit" form="k-token" variant="primary" :disabled="!tokenForm.name.trim() || !tokenForm.scopes.length" :loading="savingToken">{{
                __('Create token')
            }}</KButton>
        </template>
    </KModal>

    <!-- shown once -->
    <KModal :open="!!newToken" :title="__('Copy your token now')" :dismissible="false" @close="newToken = null">
        <template v-if="newToken">
            <p>{{ __('This is the only time it is shown. Store it somewhere safe, such as a password manager.') }}</p>
            <div class="k-secret k-secret--block">
                <code>{{ newToken.token }}</code>
            </div>
        </template>
        <template #footer>
            <KButton icon="clipboard" @click="copyToken">{{ __('Copy') }}</KButton>
            <KButton variant="primary" @click="newToken = null">{{ __('I have stored it') }}</KButton>
        </template>
    </KModal>

    <KModal :open="!!recoveryCodes" :title="__('Save your recovery codes')" :dismissible="false" @close="recoveryCodes = null">
        <template v-if="recoveryCodes">
            <p>{{ __('If you lose your phone, each code signs you in once. Keep them somewhere other than your phone. They are not shown again.') }}</p>
            <ul class="k-recovery">
                <li v-for="c in recoveryCodes" :key="c">
                    <code>{{ c }}</code>
                </li>
            </ul>
        </template>
        <template #footer>
            <KButton icon="clipboard" @click="copyCodes">{{ __('Copy') }}</KButton>
            <KButton variant="primary" @click="recoveryCodes = null">{{ __('I have saved them') }}</KButton>
        </template>
    </KModal>
</template>
