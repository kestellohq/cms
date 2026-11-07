<script setup>
import { computed, ref, watch } from 'vue';
import { Head } from '@kestello/app';
import { ApiError, notify, useApi, useResource, useUser, __ } from '@kestello/sdk';
import { KAlert, KButton, KCard, KExtension, KField, KInput, KPageHeader, KSchemaForm, KTabs } from '@kestello/ui';

defineOptions({ layout: 'Admin' });

/**
 * Site settings. Every tab is a settings group the API describes with a
 * schema (GET /settings → meta.groups); the schema form renders it, so a
 * new group needs no new screen. Plugin tabs ($cms->admin->settings()) come
 * the same way; a tab with a component renders that component instead.
 */
const api = useApi();
const me = useUser();
const settings = useResource('/settings');
const groups = computed(() => settings.meta.value?.groups ?? []);
const tabs = computed(() => groups.value.map((g) => ({ id: g.id, label: g.label })));
const fieldCount = computed(() => Object.keys(group.value?.schema?.properties ?? {}).length);
const tab = ref(typeof window !== 'undefined' ? (new URLSearchParams(window.location.search).get('tab') ?? 'general') : 'general');
const drafts = ref({});
const errors = ref({});
const saving = ref(false);

const group = computed(() => groups.value.find((g) => g.id === tab.value) ?? groups.value[0] ?? null);
// ?tab= naming a group this person can't see falls back to the first: select that tab too,
// or no tab is focusable and the keyboard can't reach the tab bar
watch(group, (g) => {
    if (g && g.id !== tab.value) tab.value = g.id;
});
const stored = computed(() => settings.data.value?.[group.value?.id] ?? {});
const secretsSet = computed(() => Object.fromEntries((group.value?.secrets ?? []).map((k) => [k, !!stored.value[`${k}_set`]])));
const changes = computed(() => {
    if (!group.value) return {};
    const draft = drafts.value[group.value.id] ?? {};
    return Object.fromEntries(
        Object.entries(draft).filter(([k, v]) => (group.value.secrets.includes(k) ? v !== '' && v !== undefined : v !== stored.value[k])),
    );
});

watch(
    () => settings.data.value,
    (data) => {
        if (!data) return;
        drafts.value = Object.fromEntries(
            groups.value.map((g) => [g.id, Object.fromEntries(Object.entries(data[g.id] ?? {}).filter(([k]) => !k.endsWith('_set')))]),
        );
    },
);
watch(tab, (value) => {
    errors.value = {};
    const url = new URL(window.location.href);
    url.searchParams.set('tab', value);
    window.history.replaceState(window.history.state, '', url);
});

async function save() {
    saving.value = true;
    errors.value = {};
    try {
        await api.patch('/settings', { [group.value.id]: changes.value });
        notify.success(__('{group} settings saved.', { group: group.value.label }));
        await settings.reload({ silent: true });
    } catch (e) {
        if (e instanceof ApiError && e.status === 422) {
            const prefix = `${group.value.id}.`;
            errors.value = Object.fromEntries(Object.entries(e.fieldErrors()).map(([k, v]) => [k.startsWith(prefix) ? k.slice(prefix.length) : k, v]));
        } else {
            notify.error(e);
        }
    } finally {
        saving.value = false;
    }
}

function revert() {
    drafts.value = { ...drafts.value, [group.value.id]: Object.fromEntries(Object.entries(stored.value).filter(([k]) => !k.endsWith('_set'))) };
    errors.value = {};
}

// ── test email (Email tab) ───────────────────────────────────────────
const testTo = ref('');
const testing = ref(false);
const testResult = ref(null);
watch(
    me,
    (u) => {
        if (u && !testTo.value) testTo.value = u.email ?? '';
    },
    { immediate: true },
);

async function sendTest() {
    testing.value = true;
    testResult.value = null;
    try {
        const { data } = await api.post('/settings/mail/test', { to: testTo.value.trim() });
        testResult.value = {
            ok: true,
            message:
                data.via === 'smtp'
                    ? __('Sent through your SMTP server. Check the inbox (and spam folder) of {to}.', { to: testTo.value })
                    : __('Handed to PHP mail(). If it does not arrive within a few minutes, configure SMTP.'),
        };
    } catch (e) {
        testResult.value = { ok: false, message: e instanceof ApiError && e.status === 422 ? (e.fieldErrors().to ?? e.message) : e.message };
    } finally {
        testing.value = false;
    }
}
// (an ICU plural's closing braces would end a {{ }} in the template)
const unsavedText = (n) => __('{n, plural, one {# unsaved change} other {# unsaved changes}}', { n });
</script>

<template>
    <Head :title="__('Settings')" />
    <KPageHeader :title="__('Settings')" :description="__('How your site introduces itself and how it reaches people.')" />

    <KAlert v-if="settings.error.value" tone="danger">{{ settings.error.value.message }}</KAlert>

    <template v-if="group">
        <KTabs v-model="tab" :tabs="tabs" :label="__('Settings groups')" />

        <div class="k-cells" style="--k-cols: 3">
            <KCard v-if="group.component && !fieldCount" coord="A1" class="k-span-all" :title="group.label" :description="group.description">
                <KExtension :key="group.id" :name="group.component" :owner="group.source ?? ''" :context="{ group: group.id }" />
            </KCard>

            <template v-else>
                <KCard coord="A1" class="k-span-2" :title="group.label" :description="group.description">
                    <form :id="`k-settings-${group.id}`" class="k-form" @submit.prevent="save">
                        <KSchemaForm
                            v-if="drafts[group.id]"
                            v-model="drafts[group.id]"
                            :schema="group.schema"
                            :labels="group.labels"
                            :secrets="group.secrets"
                            :secrets-set="secretsSet"
                            :errors="errors"
                        />
                    </form>
                    <template #footer>
                        <div class="k-form__actions">
                            <span v-if="Object.keys(changes).length" class="k-muted k-small">{{ unsavedText(Object.keys(changes).length) }}</span>
                            <KButton v-if="Object.keys(changes).length" variant="ghost" @click="revert">{{ __('Discard') }}</KButton>
                            <KButton
                                type="submit"
                                :form="`k-settings-${group.id}`"
                                variant="primary"
                                :disabled="!Object.keys(changes).length"
                                :loading="saving"
                                >{{ __('Save {group}', { group: group.label.toLowerCase() }) }}</KButton
                            >
                        </div>
                    </template>
                </KCard>

                <KCard v-if="group.id === 'mail'" coord="C1" :title="__('Send a test email')" :description="__('Uses the saved settings, so save first.')">
                    <form class="k-form" @submit.prevent="sendTest">
                        <KField v-slot="{ id }" :label="__('Send to')">
                            <KInput :id="id" v-model="testTo" type="email" autocomplete="email" />
                        </KField>
                        <div>
                            <KButton type="submit" icon="paper-plane-tilt" :disabled="!testTo || Object.keys(changes).length > 0" :loading="testing">{{
                                __('Send test')
                            }}</KButton>
                        </div>
                    </form>
                    <KAlert v-if="testResult" :tone="testResult.ok ? 'success' : 'danger'">{{ testResult.message }}</KAlert>
                </KCard>
                <KCard v-else-if="group.source" coord="C1" :title="__('From a plugin')">
                    <p class="k-source">{{ group.source }}</p>
                    <p class="k-muted">
                        {{ __('These settings belong to the {plugin} plugin and are saved with the rest of the site settings.', { plugin: group.source }) }}
                    </p>
                    <p v-if="group.secrets.length" class="k-muted">
                        {{ __('Secret fields are stored encrypted and never shown again. Leave one blank to keep what is saved.') }}
                    </p>
                    <KExtension
                        v-if="group.component"
                        :key="group.id"
                        :name="group.component"
                        :owner="group.source"
                        :context="{ group: group.id, values: stored }"
                    />
                </KCard>
                <KCard v-else coord="C1" :title="__('Where this shows')">
                    <p class="k-muted">
                        {{
                            __(
                                'The site name and tagline appear in the browser tab, in emails and wherever your theme prints them. The timezone sets the clock for dates and scheduled tasks.',
                            )
                        }}
                    </p>
                </KCard>
            </template>
        </div>
    </template>
</template>
