<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Head, Link, usePage, useRouter } from '@kestello/app';
import { ApiError, confirm, notify, useApi, useCan, useResource, useUser, __, useExtensions } from '@kestello/sdk';
import { KAlert, KButton, KCard, KField, KInput, KPageHeader, KSelect, KSwitch, KTime, KSlot } from '@kestello/ui';

defineOptions({ layout: 'Admin' });

// Plugins add panels with $cms->admin->slot('users.edit.panels', …); context: { user }.
const panels = useExtensions('slots', 'users.edit.panels', () => ({ user: person.data.value }));

const page = usePage();
const api = useApi();
const can = useCan();
const me = useUser();
const router = useRouter();
const id = computed(() => Number(page.props.params?.id ?? 0));
const person = useResource(() => `/users/${id.value}`);
const roles = useResource('/roles');

const manage = computed(() => can('users.manage'));
const self = computed(() => me.value?.id === id.value);
const form = reactive({ username: '', email: '', role: '', is_active: true, bio: '' });
const errors = ref({});
const saving = ref(false);

watch(
    () => person.data.value,
    (u) => {
        if (u) Object.assign(form, { username: u.username, email: u.email, role: u.role, is_active: u.is_active, bio: u.bio ?? '' });
    },
    { immediate: true },
);

const roleInfo = computed(() => (roles.data.value ?? []).find((r) => r.name === form.role) ?? null);
const roleOptions = computed(() =>
    (roles.data.value ?? []).map((r) => ({ value: r.name, label: r.label, disabled: !r.assignable && r.name !== person.data.value?.role })),
);
const locked = computed(
    () => !manage.value || (person.data.value && roles.data.value && !(roles.data.value.find((r) => r.name === person.data.value.role)?.assignable ?? true)),
);
const dirty = computed(() => {
    const u = person.data.value;
    return (
        !!u && (form.username !== u.username || form.email !== u.email || form.role !== u.role || form.is_active !== u.is_active || form.bio !== (u.bio ?? ''))
    );
});

async function save() {
    const u = person.data.value;
    const changes = {};
    for (const key of ['username', 'email', 'role', 'is_active', 'bio']) {
        if (form[key] !== (key === 'bio' ? (u.bio ?? '') : u[key])) changes[key] = form[key];
    }
    if (!Object.keys(changes).length) return;
    saving.value = true;
    errors.value = {};
    try {
        await api.patch(`/users/${id.value}`, changes);
        notify.success(changes.is_active === false ? __('{name} was deactivated and signed out everywhere.', { name: form.username }) : __('Changes saved.'));
        await person.reload({ silent: true });
    } catch (e) {
        if (e instanceof ApiError && e.status === 422) errors.value = e.fieldErrors();
        else notify.error(e);
    } finally {
        saving.value = false;
    }
}

// ── password ─────────────────────────────────────────────────────────
const password = ref('');
const passwordError = ref('');
const settingPassword = ref(false);

async function setPassword() {
    settingPassword.value = true;
    passwordError.value = '';
    try {
        await api.patch(`/users/${id.value}`, { password: password.value });
        password.value = '';
        notify.success(__('Password changed. {name} was signed out on every device.', { name: form.username }));
    } catch (e) {
        if (e instanceof ApiError && e.status === 422) passwordError.value = e.fieldErrors().password ?? e.message;
        else notify.error(e);
    } finally {
        settingPassword.value = false;
    }
}

async function remove() {
    const name = person.data.value?.username ?? '';
    if (
        !(await confirm({
            title: __('Delete {name}?', { name }),
            message: __(
                'Their account, sessions and access tokens are removed. Content they created stays. This cannot be undone; deactivating keeps the account instead.',
            ),
            confirmLabel: __('Delete account'),
            danger: true,
        }))
    )
        return;
    try {
        await api.delete(`/users/${id.value}`);
        notify.success(__('{name} was deleted.', { name }));
        router.visit('/admin/users');
    } catch (e) {
        notify.error(e);
    }
}
</script>

<template>
    <Head :title="person.data.value?.username ?? (person.error.value ? __('Person not found') : __('Person'))" />
    <KPageHeader :title="person.data.value?.username ?? (person.error.value ? __('Person not found') : '…')" :description="person.data.value?.email ?? ''">
        <template #actions
            ><Link href="/admin/users" class="k-link">{{ __('All people') }}</Link></template
        >
    </KPageHeader>

    <KAlert v-if="person.error.value" tone="danger">{{ person.error.value.message }}</KAlert>
    <KAlert v-else-if="self" tone="info"
        >{{ __('This is your own account. Change your password, email and sign-in security from your profile.') }}
        <Link href="/admin/profile" class="k-link">{{ __('Open your profile') }}</Link></KAlert
    >

    <div v-if="person.data.value" class="k-cells" style="--k-cols: 3">
        <KCard coord="A1" class="k-span-2" :title="__('Details')">
            <form id="k-person" class="k-form" @submit.prevent="save">
                <div class="k-form__row">
                    <KField v-slot="{ id: fid, invalid }" :label="__('Username')" :error="errors.username ?? ''">
                        <KInput :id="fid" v-model="form.username" autocomplete="off" :disabled="locked" :invalid="invalid" />
                    </KField>
                    <KField v-slot="{ id: fid, invalid }" :label="__('Email')" :error="errors.email ?? ''">
                        <KInput :id="fid" v-model="form.email" type="email" autocomplete="off" :disabled="locked" :invalid="invalid" />
                    </KField>
                </div>
                <KField v-slot="{ id: fid, invalid }" :label="__('Role')" :help="roleInfo ? roleInfo.summary.join(' · ') : ''" :error="errors.role ?? ''">
                    <KSelect :id="fid" v-model="form.role" :options="roleOptions" :disabled="locked || self" :invalid="invalid" />
                </KField>
                <KField v-slot="{ id: fid }" :label="__('Note')" :help="__('Visible to people who manage accounts.')" :error="errors.bio ?? ''">
                    <textarea :id="fid" v-model="form.bio" class="k-input k-textarea" rows="3" maxlength="2000" :disabled="locked"></textarea>
                </KField>
                <KField
                    v-slot="{ id: fid }"
                    :label="__('Can sign in')"
                    :help="form.is_active ? __('Turn off to block sign-in without deleting the account.') : __('Signed out everywhere; sign-in is blocked.')"
                    :error="errors.is_active ?? ''"
                >
                    <KSwitch :id="fid" v-model="form.is_active" :label="__('Can sign in')" hide-label :disabled="locked || self" />
                </KField>
            </form>
            <template v-if="!locked" #footer>
                <div class="k-form__actions">
                    <KButton type="submit" form="k-person" variant="primary" :disabled="!dirty" :loading="saving">{{ __('Save changes') }}</KButton>
                </div>
            </template>
        </KCard>

        <KCard coord="C1" :title="__('Record')">
            <dl class="k-facts">
                <div>
                    <dt>{{ __('Last signed in') }}</dt>
                    <dd><KTime :value="person.data.value.last_login_at" /></dd>
                </div>
                <div>
                    <dt>{{ __('Joined') }}</dt>
                    <dd>{{ String(person.data.value.created_at ?? '').slice(0, 10) || '—' }}</dd>
                </div>
                <div>
                    <dt>{{ __('Status') }}</dt>
                    <dd>{{ person.data.value.is_active ? __('Can sign in') : __('Deactivated') }}</dd>
                </div>
            </dl>
        </KCard>

        <KCard
            v-if="!locked && !self"
            coord="A2"
            class="k-span-2"
            :title="__('Set a new password')"
            :description="__('They are signed out on every device and their access tokens stop working.')"
        >
            <form class="k-input-row" @submit.prevent="setPassword">
                <KField v-slot="{ id: fid, invalid }" :label="__('New password')" :error="passwordError" class="k-toolbar__grow">
                    <KInput :id="fid" v-model="password" type="password" autocomplete="new-password" :invalid="invalid" />
                </KField>
                <KButton type="submit" :disabled="!password" :loading="settingPassword">{{ __('Set password') }}</KButton>
            </form>
        </KCard>

        <KCard
            v-if="!locked && !self"
            coord="C2"
            :title="__('Delete account')"
            :description="__('Removes the account for good. To pause access instead, turn off Can sign in.')"
        >
            <div>
                <KButton variant="danger" icon="trash" @click="remove">{{ __('Delete {name}', { name: person.data.value.username }) }}</KButton>
            </div>
        </KCard>
    </div>

    <section v-if="person.data.value && panels.length" class="k-cells" style="--k-cols: 3" :aria-label="__('From plugins')">
        <KSlot point="users.edit.panels" :context="{ user: person.data.value }" />
    </section>
</template>
