<script setup>
import { computed, ref, watch } from 'vue';
import { ApiError, confirm, notify, useApi, useResource, __ } from '@kestello/sdk';
import { KAlert, KBadge, KButton, KCard, KField, KIcon, KModal, KPageHeader, KSchemaForm, KSelect } from '@kestello/ui';

defineOptions({ layout: 'Admin' });

const api = useApi();
const list = useResource('/system/platform');
const SOURCE = {
    env: __('an environment variable'),
    file: __('config/platform.php'),
    admin: __('the admin'),
    default: __('the defaults'),
    core: __('the defaults'),
};

// ── editor ──────────────────────────────────────────────────────────
const editing = ref(null); // subsystem description
const driver = ref('');
const options = ref({});
const errors = ref({});
const test = ref(null); // { ok, message, ms }
const testing = ref(false);
const saving = ref(false);

const current = computed(() => editing.value?.drivers.find((d) => d.name === driver.value) ?? null);
const driverOptions = computed(() =>
    (editing.value?.drivers ?? []).map((d) => ({
        value: d.name,
        label: d.available ? `${d.label}${d.owner !== 'core' ? ` (${d.owner})` : ''}` : `${d.label}: ${d.unavailableReason}`,
        disabled: !d.available,
    })),
);
const locked = computed(() => {
    const out = {};
    for (const key of editing.value?.lockedKeys ?? []) out[key] = SOURCE[editing.value.sources?.[key]] ?? SOURCE.env;
    return out;
});
const secretsSet = computed(() => {
    const out = {};
    if (editing.value && driver.value === editing.value.driver) {
        for (const [key, value] of Object.entries(editing.value.options)) if (value && typeof value === 'object' && 'set' in value) out[key] = value.set;
    }
    return out;
});

function open(subsystem) {
    editing.value = subsystem;
    driver.value = subsystem.driver;
    options.value = initialOptions(subsystem, subsystem.driver);
    errors.value = {};
    test.value = null;
}

function initialOptions(subsystem, name) {
    if (name !== subsystem.driver) return {};
    const out = {};
    for (const [key, value] of Object.entries(subsystem.options)) {
        if (value === null || typeof value !== 'object') out[key] = value;
    }
    return out;
}

watch(driver, (name, previous) => {
    if (!editing.value || previous === '' || name === previous) return;
    options.value = initialOptions(editing.value, name);
    errors.value = {};
    test.value = null;
});

function payload() {
    const clean = {};
    const secrets = current.value?.secrets ?? [];
    for (const [key, value] of Object.entries(options.value)) {
        if (value === undefined || locked.value[key] || !(key in (current.value?.schema?.properties ?? {}))) continue;
        if (value === '' && (secrets.includes(key) || current.value?.schema?.properties?.[key]?.default !== undefined)) continue;
        clean[key] = value;
    }
    return { driver: driver.value, options: clean };
}

function showErrors(error) {
    errors.value = {};
    for (const [field, message] of Object.entries(error.fieldErrors?.() ?? {})) {
        errors.value[field.replace(/^options\./, '')] = message;
    }
}

async function runTest() {
    testing.value = true;
    test.value = null;
    try {
        test.value = (await api.post(`/system/platform/${editing.value.name}/test`, payload())).data;
        errors.value = {};
        for (const [field, messages] of Object.entries(test.value.errors ?? {})) errors.value[field] = messages[0];
    } catch (e) {
        test.value = { ok: false, message: e.message };
        if (e instanceof ApiError) showErrors(e);
    } finally {
        testing.value = false;
    }
}

async function save() {
    saving.value = true;
    try {
        await api.put(`/system/platform/${editing.value.name}`, payload());
        notify.success(
            __('{label} now uses {driver}. Administrators were notified.', { label: editing.value.label, driver: current.value?.label ?? driver.value }),
        );
        editing.value = null;
        await list.reload();
    } catch (e) {
        if (e instanceof ApiError && e.status === 422) {
            showErrors(e);
            test.value = { ok: false, message: e.detail || e.message };
        } else {
            notify.error(e);
        }
    } finally {
        saving.value = false;
    }
}

async function reset(subsystem) {
    if (
        !(await confirm({
            title: __('Reset {label}?', { label: subsystem.label }),
            message: __('The settings saved in the admin are removed. The config file, environment or defaults apply again.'),
            confirmLabel: __('Reset'),
        }))
    )
        return;
    try {
        await api.delete(`/system/platform/${subsystem.name}`);
        notify.success(__('{label} was reset.', { label: subsystem.label }));
        await list.reload();
    } catch (e) {
        notify.error(e);
    }
}

function driverLabel(subsystem) {
    return subsystem.drivers.find((d) => d.name === subsystem.driver)?.label ?? subsystem.driver;
}
</script>

<template>
    <KPageHeader
        :title="__('Infrastructure')"
        :description="__('Where Kestello keeps its cache, sessions, jobs and other services. Changes are tested before they are saved.')"
    />

    <KAlert v-if="list.error.value" tone="danger">{{ list.error.value.message }}</KAlert>
    <KAlert v-if="list.data.value?.[0]?.locked" tone="info" :title="__('Managed by your host')">
        {{ __('Infrastructure settings are locked (config/platform.php or KESTELLO_PLATFORM_LOCKED). You can review them here but not change them.') }}
    </KAlert>

    <div class="k-grid k-grid--2">
        <KCard v-for="subsystem in list.data.value ?? []" :key="subsystem.name" :title="subsystem.label" :description="subsystem.description">
            <template #actions>
                <KButton v-if="subsystem.driverSource === 'admin' && !subsystem.locked" size="sm" variant="ghost" @click="reset(subsystem)">{{
                    __('Reset')
                }}</KButton>
                <KButton size="sm" :disabled="subsystem.locked" icon="sliders" @click="open(subsystem)">{{ __('Change') }}</KButton>
            </template>
            <div class="k-driver">
                <KIcon name="hard-drives" />
                <div>
                    <strong>{{ driverLabel(subsystem) }}</strong>
                    <p class="k-muted k-small">{{ __('Set by {source}', { source: SOURCE[subsystem.driverSource] ?? subsystem.driverSource }) }}</p>
                </div>
                <KBadge v-if="subsystem.degraded" tone="danger" dot>{{ __('Fallback active') }}</KBadge>
                <KBadge v-else tone="success" dot>{{ __('Working') }}</KBadge>
            </div>
            <KAlert v-if="subsystem.degraded" tone="danger">
                {{
                    __('The {driver} driver failed ({error}), so a fallback is serving requests.', {
                        driver: subsystem.degraded.driver,
                        error: subsystem.degraded.error,
                    })
                }}
            </KAlert>
        </KCard>
    </div>

    <KModal :open="!!editing" :title="editing ? __('Change {label}', { label: editing.label }) : ''" size="lg" @close="editing = null">
        <template v-if="editing">
            <KField v-slot="{ id }" :label="__('Driver')" :help="current?.description ?? ''" :error="errors.driver ?? ''">
                <KSelect :id="id" v-model="driver" :options="driverOptions" :disabled="!!locked.driver" />
            </KField>
            <KSchemaForm
                v-if="current"
                v-model="options"
                :schema="current.schema"
                :errors="errors"
                :secrets="current.secrets"
                :secrets-set="secretsSet"
                :locked="locked"
            />
            <KAlert v-if="test" :tone="test.ok ? 'success' : 'danger'" :title="test.ok ? __('Connection test passed') : __('Connection test failed')">
                {{ test.message }}<span v-if="test.ms !== undefined" class="k-muted"> ({{ test.ms }} ms)</span>
            </KAlert>
        </template>
        <template #footer>
            <KButton @click="editing = null">{{ __('Cancel') }}</KButton>
            <KButton icon="plug" :loading="testing" @click="runTest">{{ __('Test connection') }}</KButton>
            <KButton variant="primary" :loading="saving" @click="save">{{ __('Test and save') }}</KButton>
        </template>
    </KModal>
</template>
