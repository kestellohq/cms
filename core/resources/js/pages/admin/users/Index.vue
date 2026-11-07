<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Head, Link, useRouter } from '@kestello/app';
import { ApiError, notify, useApi, useCan, useExtensions, useResource, __ } from '@kestello/sdk';
import { KAvatar, KButton, KCard, KExtension, KField, KInput, KModal, KPageHeader, KSelect, KStatusBadge, KTable, KTime, KBadge } from '@kestello/ui';

defineOptions({ layout: 'Admin' });

const api = useApi();
const can = useCan();
const router = useRouter();
const roles = useResource('/roles');

const filters = reactive({ search: '', role: '', active: '' });
const rows = ref([]);
const cursor = ref(null);
const loading = ref(false);
let seq = 0;

// Plugins add columns with $cms->admin->column('users.list', …); they sit before "Joined".
const extColumns = useExtensions('columns', 'users.list');
const columns = computed(() => [
    { key: 'username', label: __('Name') },
    { key: 'role', label: __('Role') },
    { key: 'is_active', label: __('Status') },
    { key: 'last_login_at', label: __('Last signed in') },
    ...extColumns.value.map((c) => ({ key: c.key, label: c.label, align: c.align })),
    { key: 'created_at', label: __('Joined'), align: 'end' },
]);

const roleLabel = (name) => roles.data.value?.find((r) => r.name === name)?.label ?? name;
const roleOptions = computed(() => [{ value: '', label: __('Every role') }, ...(roles.data.value ?? []).map((r) => ({ value: r.name, label: r.label }))]);
const statusOptions = [
    { value: '', label: __('Active and deactivated') },
    { value: '1', label: __('Active') },
    { value: '0', label: __('Deactivated') },
];

async function load(more = false) {
    const mine = ++seq;
    loading.value = true;
    try {
        const query = { limit: 25, cursor: more ? cursor.value : undefined, search: filters.search.trim() || undefined };
        if (filters.role) query['filter[role]'] = filters.role;
        if (filters.active !== '') query['filter[active]'] = filters.active;
        const body = await api.get('/users', query);
        if (mine !== seq) return;
        rows.value = more ? [...rows.value, ...body.data] : body.data;
        cursor.value = body.meta.next_cursor;
    } catch (e) {
        notify.error(e);
    } finally {
        if (mine === seq) loading.value = false;
    }
}
load();

let debounce = null;
watch(
    () => filters.search,
    () => {
        clearTimeout(debounce);
        debounce = setTimeout(() => load(), 250);
    },
);
watch(
    () => [filters.role, filters.active],
    () => load(),
);

// ── add a person ─────────────────────────────────────────────────────
const adding = ref(false);
const saving = ref(false);
const form = reactive({ username: '', email: '', role: 'user', password: '' });
const errors = ref({});
const assignable = computed(() => (roles.data.value ?? []).filter((r) => r.assignable).map((r) => ({ value: r.name, label: r.label })));

function openAdd() {
    Object.assign(form, {
        username: '',
        email: '',
        role: assignable.value.some((r) => r.value === 'user') ? 'user' : (assignable.value[0]?.value ?? ''),
        password: '',
    });
    errors.value = {};
    adding.value = true;
}

function generate() {
    const alphabet = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    const bytes = crypto.getRandomValues(new Uint8Array(20));
    form.password = Array.from(bytes, (b) => alphabet[b % alphabet.length])
        .join('')
        .replace(/(.{5})(?!$)/g, '$1-');
}

async function create() {
    saving.value = true;
    errors.value = {};
    try {
        const { data } = await api.post('/users', { ...form, email: form.email.trim() });
        adding.value = false;
        notify.success(__('{name} can now sign in.', { name: data.username }));
        router.visit(`/admin/users/${data.id}`);
    } catch (e) {
        if (e instanceof ApiError && e.status === 422) errors.value = e.fieldErrors();
        else notify.error(e);
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Head :title="__('People')" />
    <KPageHeader :title="__('People')" :description="__('Everyone who can sign in to this site, and what each of them may do.')">
        <template v-if="can('users.manage')" #actions>
            <KButton variant="primary" icon="user-plus" @click="openAdd">{{ __('Add a person') }}</KButton>
        </template>
    </KPageHeader>

    <div class="k-toolbar" role="search">
        <KField v-slot="{ id }" :label="__('Search')" class="k-toolbar__grow">
            <KInput :id="id" v-model="filters.search" type="search" autocomplete="off" />
        </KField>
        <KField v-slot="{ id }" :label="__('Role')">
            <KSelect :id="id" v-model="filters.role" :options="roleOptions" />
        </KField>
        <KField v-slot="{ id }" :label="__('Status')">
            <KSelect :id="id" v-model="filters.active" :options="statusOptions" />
        </KField>
    </div>

    <KCard coord="A1" :title="__('People')" flush>
        <KTable
            :columns="columns"
            :rows="rows"
            :loading="loading && !rows.length"
            :empty="filters.search || filters.role || filters.active !== '' ? __('Nobody matches these filters.') : __('Nobody has an account yet.')"
        >
            <template #cell-username="{ row }">
                <div class="k-person">
                    <KAvatar :user="row" :size="28" />
                    <div>
                        <Link :href="`/admin/users/${row.id}`" class="k-link">{{ row.username }}</Link>
                        <div class="k-muted k-small">{{ row.email }}</div>
                    </div>
                </div>
            </template>
            <template v-for="col in extColumns" :key="col.id" #[`cell-${col.key}`]="{ row }">
                <KExtension v-if="col.component" :name="col.component" :owner="col.owner" inline :row="row" :value="row[col.key]" />
                <span v-else>{{ row[col.key] ?? '—' }}</span>
            </template>
            <template #cell-role="{ row }"
                ><KBadge>{{ roleLabel(row.role) }}</KBadge></template
            >
            <template #cell-is_active="{ row }"
                ><KStatusBadge :status="row.is_active ? 'good' : 'disabled'">{{ row.is_active ? __('Active') : __('Deactivated') }}</KStatusBadge></template
            >
            <template #cell-last_login_at="{ row }"><KTime :value="row.last_login_at" /></template>
            <template #cell-created_at="{ row }"
                ><span class="k-muted">{{ String(row.created_at ?? '').slice(0, 10) || '—' }}</span></template
            >
        </KTable>
        <template v-if="cursor" #footer
            ><KButton size="sm" :loading="loading" @click="load(true)">{{ __('Show more') }}</KButton></template
        >
    </KCard>

    <KModal :open="adding" :title="__('Add a person')" @close="adding = false">
        <form id="k-add-person" class="k-form" @submit.prevent="create">
            <KField
                v-slot="{ id, describedBy, invalid }"
                :label="__('Username')"
                :help="__('Letters, numbers, dashes and underscores. They can sign in with it.')"
                :error="errors.username ?? ''"
                required
            >
                <KInput :id="id" v-model="form.username" autocomplete="off" :invalid="invalid" :aria-describedby="describedBy" />
            </KField>
            <KField
                v-slot="{ id, describedBy, invalid }"
                :label="__('Email')"
                :help="__('Password resets and notifications go here.')"
                :error="errors.email ?? ''"
                required
            >
                <KInput :id="id" v-model="form.email" type="email" autocomplete="off" :invalid="invalid" :aria-describedby="describedBy" />
            </KField>
            <KField v-slot="{ id, invalid }" :label="__('Role')" :error="errors.role ?? ''" required>
                <KSelect :id="id" v-model="form.role" :options="assignable" :invalid="invalid" />
            </KField>
            <KField
                v-slot="{ id, describedBy, invalid }"
                :label="__('Password')"
                :help="__('Share it with them privately; they can change it from their profile.')"
                :error="errors.password ?? ''"
                required
            >
                <div class="k-input-row">
                    <KInput
                        :id="id"
                        v-model="form.password"
                        type="text"
                        autocomplete="new-password"
                        spellcheck="false"
                        class="k-mono-input"
                        :invalid="invalid"
                        :aria-describedby="describedBy"
                    />
                    <KButton icon="shuffle" @click="generate">{{ __('Generate') }}</KButton>
                </div>
            </KField>
        </form>
        <template #footer>
            <KButton @click="adding = false">{{ __('Cancel') }}</KButton>
            <KButton variant="primary" type="submit" form="k-add-person" :loading="saving">{{ __('Add person') }}</KButton>
        </template>
    </KModal>
</template>
