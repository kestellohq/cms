<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Head } from '@kestello/app';
import { ApiError, confirm, notify, useApi, useResource, __ } from '@kestello/sdk';
import { KAlert, KBadge, KButton, KCard, KField, KInput, KModal, KPageHeader, KSelect } from '@kestello/ui';

defineOptions({ layout: 'Admin' });

const api = useApi();
const roles = useResource('/roles');
const caps = useResource('/capabilities');

const selected = ref(null);
const role = computed(() => (roles.data.value ?? []).find((r) => r.name === selected.value) ?? null);
watch(
    () => roles.data.value,
    (list) => {
        if (list?.length && !list.some((r) => r.name === selected.value)) selected.value = list.find((r) => !r.administrator)?.name ?? list[0].name;
    },
    { immediate: true },
);

// ── the form ─────────────────────────────────────────────────────────
const form = reactive({ label: '', description: '', capabilities: new Set() });
const errors = ref({});
const saving = ref(false);
function fill(r) {
    if (!r) return;
    Object.assign(form, { label: r.label, description: r.description ?? '', capabilities: new Set(r.capabilities) });
    errors.value = {};
}
watch(role, fill, { immediate: true });
const dirty = computed(() => {
    const r = role.value;
    if (!r || r.administrator) return false;
    const a = [...form.capabilities].sort().join(',');
    return form.label !== r.label || form.description !== (r.description ?? '') || a !== [...r.capabilities].sort().join(',');
});
async function pick(name) {
    if (name === selected.value) return;
    if (
        dirty.value &&
        !(await confirm({
            title: __('Discard unsaved changes?'),
            message: __('Your changes to {role} are not saved.', { role: role.value.label }),
            confirmLabel: __('Discard'),
        }))
    )
        return;
    selected.value = name;
}
function toggle(cap, on) {
    const next = new Set(form.capabilities);
    on ? next.add(cap) : next.delete(cap);
    form.capabilities = next;
}

async function save() {
    saving.value = true;
    errors.value = {};
    try {
        await api.patch(`/roles/${role.value.name}`, { label: form.label, description: form.description || null, capabilities: [...form.capabilities] });
        notify.success(__('{role} saved. People with this role get the change on their next click.', { role: form.label }));
        await roles.reload({ silent: true });
    } catch (e) {
        if (e instanceof ApiError && e.status === 422) errors.value = e.errors;
        else notify.error(e);
    } finally {
        saving.value = false;
    }
}
async function reset() {
    const ok = await confirm({
        title: __('Reset {role}?', { role: role.value.label }),
        message: __('Undo the changes made here and use the capabilities Kestello and your plugins give it.'),
        confirmLabel: __('Reset role'),
    });
    if (!ok) return;
    try {
        await api.delete(`/roles/${role.value.name}`);
        notify.success(__('{role} is back to its defaults.', { role: role.value.label }));
        await roles.reload({ silent: true });
    } catch (e) {
        notify.error(e);
    }
}
const reassign = ref('user');
async function removeRole() {
    const r = role.value;
    const ok = await confirm({
        title: __('Delete the {role} role?', { role: r.label }),
        message: r.users ? __('{n} people have it; they become {target}.', { n: r.users, target: roleLabel(reassign.value) }) : __('Nobody has it.'),
        confirmLabel: __('Delete role'),
        danger: true,
    });
    if (!ok) return;
    try {
        await api.delete(`/roles/${r.name}`, { query: { reassign: reassign.value } });
        notify.success(__('Deleted {role}.', { role: r.label }));
        selected.value = null;
        await roles.reload({ silent: true });
    } catch (e) {
        notify.error(e);
    }
}
const roleLabel = (name) => (roles.data.value ?? []).find((r) => r.name === name)?.label ?? name;
const reassignOptions = computed(() =>
    (roles.data.value ?? []).filter((r) => r.name !== selected.value && r.assignable).map((r) => ({ value: r.name, label: r.label })),
);

// ── capabilities, grouped ────────────────────────────────────────────
const SECTIONS = {
    read: 'general',
    profile: 'general',
    admin: 'general',
    users: 'people',
    roles: 'people',
    security: 'people',
    menus: 'content',
    content: 'content',
    taxonomy: 'content',
    media: 'content',
    forms: 'content',
    redirects: 'content',
    theme: 'appearance',
    snippets: 'appearance',
    settings: 'site',
    extensions: 'site',
    system: 'site',
    audit: 'site',
    backups: 'site',
    plugins: 'site',
    core: 'site',
    content_models: 'site',
    import: 'site',
    webhooks: 'site',
    fleet: 'site',
};
const SECTION_LABELS = { general: __('Signing in'), content: __('Content'), people: __('People'), appearance: __('Appearance'), site: __('Site and system') };
const groups = computed(() => {
    const out = new Map();
    for (const c of caps.data.value ?? []) {
        const [first, second] = c.name.split('.');
        let key;
        let label;
        if (c.owner !== 'core') {
            key = `owner:${c.owner}`;
            label = __('From {owner}', { owner: c.owner.replace(/^(plugin|theme):/, '') });
        } else if ((first === 'content' && second && second !== 'access') || first === 'taxonomy') {
            key = `${first}.${second}`;
            label = second.replace(/[-_]/g, ' ').replace(/^\w/, (x) => x.toUpperCase());
        } else {
            key = SECTIONS[first] ?? 'general';
            label = SECTION_LABELS[key];
        }
        if (!out.has(key)) out.set(key, { key, label, items: [] });
        out.get(key).items.push(c);
    }
    const order = (g) =>
        ['general', 'content'].indexOf(g.key) + 1 ||
        (g.key.startsWith('content.') ? 3 : g.key.startsWith('taxonomy.') ? 4 : g.key === 'people' ? 5 : g.key === 'appearance' ? 6 : g.key === 'site' ? 7 : 8);
    return [...out.values()].sort((a, b) => order(a) - order(b));
});
const state = (cap) => {
    const r = role.value;
    if (!r) return '';
    const on = form.capabilities.has(cap);
    const def = r.defaults.includes(cap);
    return on && !def ? 'added' : !on && def ? 'removed' : '';
};
const locked = computed(() => !role.value || role.value.administrator || !role.value.editable);
// capabilities as good as administrator access, being added by this change
const sensitiveAdded = computed(() => (caps.data.value ?? []).filter((c) => c.sensitive && state(c.name) === 'added').map((c) => c.label));
const sensitiveText = (labels) => __('Whoever has this role will be able to act as an administrator: {list}.', { list: labels.join(', ') });

// ── new role ─────────────────────────────────────────────────────────
const creating = ref(false);
const draft = reactive({ label: '', name: '', from: '' });
const draftErrors = ref({});
watch(
    () => draft.label,
    (v, old) => {
        const slug = (s) =>
            s
                .toLowerCase()
                .normalize('NFKD')
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '')
                .replace(/^[^a-z]+/, '')
                .slice(0, 20);
        if (!draft.name || draft.name === slug(old ?? '')) draft.name = slug(v);
    },
);
const copyOptions = computed(() => [
    { value: '', label: __('Nothing (start empty)') },
    ...(roles.data.value ?? []).filter((r) => !r.administrator).map((r) => ({ value: r.name, label: r.label })),
]);
const creatingBusy = ref(false);
async function create() {
    if (creatingBusy.value) return; // a double click sends one request
    creatingBusy.value = true;
    draftErrors.value = {};
    const from = (roles.data.value ?? []).find((r) => r.name === draft.from);
    const grantable = new Set((caps.data.value ?? []).filter((c) => c.grantable).map((c) => c.name));
    try {
        const { data } = await api.post('/roles', {
            name: draft.name,
            label: draft.label,
            capabilities: (from?.capabilities ?? []).filter((c) => grantable.has(c)),
        });
        creating.value = false;
        Object.assign(draft, { label: '', name: '', from: '' });
        await roles.reload({ silent: true });
        selected.value = data.name;
        notify.success(__('Created {role}. Pick what it may do, then save.', { role: data.label }));
    } catch (e) {
        if (e instanceof ApiError && e.status === 422) draftErrors.value = e.fieldErrors();
        else notify.error(e);
    } finally {
        creatingBusy.value = false;
    }
}
const err = (k) => (Array.isArray(errors.value[k]) ? errors.value[k].join(' ') : (errors.value[k] ?? ''));
</script>

<template>
    <Head :title="__('Roles')" />
    <KPageHeader :title="__('Roles')" :description="__('What each kind of account may do. Give people a role on their page under People.')">
        <template #actions>
            <KButton icon="plus" @click="((draftErrors = {}), (creating = true))">{{ __('New role') }}</KButton>
        </template>
    </KPageHeader>

    <KAlert v-if="roles.error.value" tone="danger">{{ roles.error.value.message }}</KAlert>

    <div v-if="roles.data.value" class="k-cells k-roles" style="--k-cols: 3">
        <KCard coord="A1" :title="__('Roles')" flush>
            <ul class="k-role-list">
                <li v-for="r in roles.data.value" :key="r.name">
                    <button type="button" class="k-role-list__item" :aria-current="r.name === selected ? 'true' : undefined" @click="pick(r.name)">
                        <span class="k-role-list__name">{{ r.label }}</span>
                        <span class="k-role-list__meta">
                            <KBadge v-if="r.administrator" tone="accent">{{ __('Everything') }}</KBadge>
                            <KBadge v-else-if="r.custom">{{ __('Custom') }}</KBadge>
                            <KBadge v-else-if="r.customized" tone="info">{{ __('Changed') }}</KBadge>
                            <span class="k-muted k-small k-num">{{ r.users === 1 ? __('1 person') : __('{n} people', { n: r.users }) }}</span>
                        </span>
                    </button>
                </li>
            </ul>
        </KCard>

        <KCard
            v-if="role"
            coord="B1"
            class="k-span-2"
            :title="role.label"
            :description="role.administrator ? __('Administrators can do everything, including things plugins add later. This role cannot be changed.') : ''"
        >
            <KAlert v-if="!role.administrator && !role.editable" tone="info">{{
                __('This is your own role, so you cannot change it. Ask an administrator.')
            }}</KAlert>
            <form v-if="!role.administrator" id="k-role-form" class="k-form" @submit.prevent="save">
                <div class="k-form__row">
                    <KField v-slot="{ id, invalid }" :label="__('Name')" :error="err('label')">
                        <KInput :id="id" v-model="form.label" maxlength="60" :disabled="locked" :invalid="invalid" />
                    </KField>
                    <KField v-slot="{ id }" :label="__('Description')" :help="__('Helps whoever assigns roles.')" :error="err('description')">
                        <KInput :id="id" v-model="form.description" maxlength="255" :disabled="locked" />
                    </KField>
                </div>
                <p v-if="err('capabilities')" class="k-field__error">{{ err('capabilities') }}</p>
                <KAlert v-if="!locked && sensitiveAdded.length" tone="warning">{{ sensitiveText(sensitiveAdded) }}</KAlert>
                <div class="k-capgroups">
                    <fieldset v-for="g in groups" :key="g.key" class="k-capgroup">
                        <legend class="k-capgroup__title">{{ g.label }}</legend>
                        <label
                            v-for="c in g.items"
                            :key="c.name"
                            class="k-cap"
                            :class="state(c.name) ? `is-${state(c.name)}` : ''"
                            :title="c.grantable ? c.name : __('You can only grant what you have yourself.')"
                        >
                            <input
                                type="checkbox"
                                :checked="form.capabilities.has(c.name)"
                                :disabled="locked || (!c.grantable && !form.capabilities.has(c.name))"
                                @change="toggle(c.name, $event.target.checked)"
                            />
                            <span class="k-cap__label">
                                {{ c.label }}
                                <span v-if="c.sensitive" class="k-cap__note">{{ __('Same as administrator access') }}</span>
                            </span>
                            <span v-if="state(c.name) === 'added'" class="k-cap__diff">{{ __('added') }}</span>
                            <span v-else-if="state(c.name) === 'removed'" class="k-cap__diff">{{ __('removed') }}</span>
                        </label>
                    </fieldset>
                </div>
            </form>
            <template v-if="!locked" #footer>
                <div class="k-form__actions">
                    <KButton v-if="role.customized" variant="ghost" icon="arrow-counter-clockwise" @click="reset">{{ __('Reset to defaults') }}</KButton>
                    <template v-if="role.custom">
                        <KSelect
                            v-if="role.users"
                            v-model="reassign"
                            :options="reassignOptions"
                            :aria-label="__('Move people with this role to')"
                            class="k-role-reassign"
                        />
                        <KButton variant="ghost" icon="trash" @click="removeRole">{{ __('Delete role') }}</KButton>
                    </template>
                    <span v-if="dirty" class="k-muted k-small k-role-dirty">{{ __('Unsaved changes') }}</span>
                    <KButton type="submit" form="k-role-form" variant="primary" :loading="saving" :disabled="!dirty">{{ __('Save role') }}</KButton>
                </div>
            </template>
        </KCard>
    </div>

    <KModal :open="creating" :title="__('New role')" @close="creating = false">
        <form id="k-new-role" class="k-form" @submit.prevent="create">
            <KField v-slot="{ id, invalid }" :label="__('Name')" :help="__('What people see, e.g. “Reviewer”.')" :error="draftErrors.label ?? ''" required>
                <KInput :id="id" v-model="draft.label" maxlength="60" autocomplete="off" :invalid="invalid" />
            </KField>
            <KField
                v-slot="{ id, describedBy, invalid }"
                :label="__('Id')"
                :help="__('Lowercase letters, digits, dashes. It cannot change later.')"
                :error="draftErrors.name ?? ''"
                required
            >
                <KInput
                    :id="id"
                    v-model="draft.name"
                    maxlength="20"
                    spellcheck="false"
                    autocomplete="off"
                    class="k-mono-input"
                    :invalid="invalid"
                    :aria-describedby="describedBy"
                />
            </KField>
            <KField v-slot="{ id }" :label="__('Start with the capabilities of')">
                <KSelect :id="id" v-model="draft.from" :options="copyOptions" />
            </KField>
        </form>
        <template #footer>
            <KButton @click="creating = false">{{ __('Cancel') }}</KButton>
            <KButton type="submit" form="k-new-role" variant="primary" :loading="creatingBusy" :disabled="!draft.label.trim() || !draft.name">{{
                __('Create role')
            }}</KButton>
        </template>
    </KModal>
</template>
