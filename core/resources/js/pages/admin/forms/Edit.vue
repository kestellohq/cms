<script setup>
import { computed, ref } from 'vue';
import { Head, Link, usePage, useRouter } from '@kestello/app';
import { ApiError, clone, confirm, notify, useApi, useLeaveGuard, __ } from '@kestello/sdk';
import { KAlert, KButton, KCard, KField, KIcon, KInput, KSelect, KSwitch } from '@kestello/ui';
import FormBuilder from '../../../components/forms/FormBuilder.vue';
import { newId } from '../../../components/content-types/fields.js';

defineOptions({ layout: 'Admin' });

/** One form, built visually (FormBuilder): its fields (with conditions), and in the Form tab its messages, notifications, storage and spam settings. */
const api = useApi();
const page = usePage();
const router = useRouter();
const id = computed(() => String(page.props.params?.id ?? ''));

const form = ref(null);
const meta = ref(null);
const loadError = ref(null);
const errors = ref({});
const saving = ref(false);

const toRows = (fields) =>
    fields.map((f) => ({
        ...f,
        id: f.id || newId(),
        options: {
            ...f.options,
            ...(f.options?.choices && !Array.isArray(f.options.choices)
                ? { choices: Object.entries(f.options.choices).map(([value, label]) => ({ value, label })) }
                : {}),
        },
        children: [],
    }));
const clean = (fields) =>
    fields.map(({ _new, _namedByHand, children, ...f }) => ({
        ...f,
        options: { ...f.options, ...(Array.isArray(f.options?.choices) ? { choices: f.options.choices.map(({ value, label }) => ({ value, label })) } : {}) },
    }));

async function load() {
    try {
        const body = await api.get(`/forms/${id.value}`);
        const d = clone(body.data.definition);
        d.fields = toRows(d.fields);
        d.notifications = d.notifications.map((n) => ({ ...n, to: n.to.join(', '), reply_to: n.reply_to ?? '' }));
        form.value = { ...body.data, definition: d };
        saved.value = snapshot();
        meta.value = body.meta;
    } catch (e) {
        loadError.value = e;
    }
}
load();

const def = computed(() => form.value?.definition);
// what Save would send, as last loaded: anything else is unsaved, and leaving asks first
const snapshot = () => (form.value ? JSON.stringify([form.value.title, form.value.status, form.value.definition]) : '');
const saved = ref('');
const dirty = computed(() => form.value !== null && snapshot() !== saved.value);
useLeaveGuard(dirty);
const emailFields = computed(() => (def.value?.fields ?? []).filter((f) => f.type === 'email' && f.name));
const err = (key) => errors.value[key] ?? '';

async function save() {
    saving.value = true;
    errors.value = {};
    const definition = {
        ...def.value,
        fields: clean(def.value.fields),
        notifications: def.value.notifications.map((n) => ({ ...n, reply_to: n.reply_to || null })),
    };
    try {
        await api.patch(`/forms/${id.value}`, { title: form.value.title, status: form.value.status, definition });
        notify.success(__('Saved. Pages showing the form use it straight away.'));
        await load();
    } catch (e) {
        if (e instanceof ApiError && e.status === 422) {
            errors.value = Object.fromEntries(Object.entries(e.fieldErrors()).map(([k, v]) => [k.replace(/^definition\./, ''), v]));
            notify.error(__('Some settings need attention.'));
        } else {
            notify.error(e);
        }
    } finally {
        saving.value = false;
    }
}

async function remove() {
    if (
        !(await confirm({
            title: __('Delete {name}?', { name: form.value.title }),
            message: __("Every submission and uploaded file goes with it, and pages showing it show nothing. This can't be undone."),
            confirmLabel: __('Delete the form'),
            danger: true,
        }))
    )
        return;
    try {
        await api.delete(`/forms/${id.value}`);
        notify.success(__('Form deleted.'));
        router.visit('/admin/forms', { discardUnsaved: true });
    } catch (e) {
        notify.error(e);
    }
}
</script>

<template>
    <Head :title="form ? form.title : __('Form')" />
    <KAlert v-if="loadError" tone="danger">{{ loadError.message }}</KAlert>

    <!-- novalidate: the builder draws the form's real (required) fields; the server checks what is saved -->
    <form v-if="form" novalidate @submit.prevent="save">
        <div class="k-editor-head">
            <Link href="/admin/forms" class="k-link k-editor-head__back"><KIcon name="arrow-left" /> {{ __('Forms') }}</Link>
            <span class="k-editor-head__state">
                <KSwitch
                    :model-value="form.status === 'open'"
                    :label="form.status === 'open' ? __('Open: taking submissions') : __('Closed')"
                    @update:model-value="(v) => (form.status = v ? 'open' : 'closed')"
                />
            </span>
            <KButton icon="tray" :href="`/admin/forms/${form.id}/submissions`">{{ __('Submissions') }}</KButton>
            <span v-if="dirty" class="k-muted k-small">{{ __('Unsaved changes') }}</span>
            <KButton variant="primary" type="submit" icon="check" :loading="saving" :disabled="!dirty">{{ __('Save') }}</KButton>
        </div>

        <div class="k-form-title" :class="{ 'has-error': err('title') }">
            <label class="k-sr-only" for="k-form-title">{{ __('Name of the form') }}</label>
            <input
                id="k-form-title"
                v-model="form.title"
                class="k-editor__title-input"
                maxlength="200"
                autocomplete="off"
                data-lpignore="true"
                data-1p-ignore
            />
            <p v-if="err('title')" class="k-field__error" role="alert">{{ err('title') }}</p>
            <p class="k-muted k-ff-lead">{{ __('Put the form on a page with the Form block.') }}</p>
        </div>
        <p v-if="err('fields')" class="k-field__error" role="alert">{{ err('fields') }}</p>

        <FormBuilder v-model:fields="def.fields" :meta="meta" :errors="errors" :submit-label="def.submit_label">
            <template #form>
                <KCard :title="__('After sending')">
                    <KField v-slot="{ id: fid, invalid }" :label="__('Button text')" :error="err('submit_label')">
                        <KInput :id="fid" v-model="def.submit_label" maxlength="60" :invalid="invalid" />
                    </KField>
                    <KField v-slot="{ id: fid, invalid }" :label="__('Thank-you message')" :error="err('success_message')">
                        <textarea
                            :id="fid"
                            v-model="def.success_message"
                            class="k-input k-ff-textarea"
                            rows="3"
                            maxlength="1000"
                            :aria-invalid="invalid ? 'true' : undefined"
                        ></textarea>
                    </KField>
                    <KField
                        v-slot="{ id: fid, invalid }"
                        :label="__('Or go to a page')"
                        :help="__('Such as /thanks. Replaces the message.')"
                        :error="err('redirect')"
                    >
                        <KInput
                            :id="fid"
                            :model-value="def.redirect ?? ''"
                            class="k-mono-input"
                            maxlength="1000"
                            spellcheck="false"
                            :invalid="invalid"
                            @update:model-value="(v) => (def.redirect = v.trim() || null)"
                        />
                    </KField>
                    <KField v-slot="{ id: fid }" :label="__('When the form is closed')">
                        <KInput :id="fid" v-model="def.closed_message" maxlength="500" />
                    </KField>
                </KCard>

                <KCard :title="__('Email')">
                    <p v-if="!def.notifications.length" class="k-muted k-small k-ff-note">
                        {{ __('Nobody is emailed. Submissions wait on the Submissions screen.') }}
                    </p>
                    <div v-for="(n, i) in def.notifications" :key="i" class="k-ff-mail">
                        <KField
                            v-slot="{ id: fid, invalid }"
                            :label="__('Send to')"
                            :help="__('One or more addresses, separated by commas.')"
                            :error="err(`notifications.${i}.to`)"
                        >
                            <KInput :id="fid" v-model="n.to" type="text" autocomplete="off" spellcheck="false" :invalid="invalid" />
                        </KField>
                        <KField v-slot="{ id: fid }" :label="__('Subject')" :help="__('Empty: New submission: {name}', { name: form.title })">
                            <KInput :id="fid" v-model="n.subject" maxlength="200" />
                        </KField>
                        <KField
                            v-if="emailFields.length"
                            v-slot="{ id: fid, invalid }"
                            :label="__('Replies go to')"
                            :error="err(`notifications.${i}.reply_to`)"
                        >
                            <KSelect
                                :id="fid"
                                v-model="n.reply_to"
                                :invalid="invalid"
                                :options="[
                                    { value: '', label: __('The site\'s address') },
                                    ...emailFields.map((f) => ({ value: f.name, label: __('The address in {field}', { field: f.label || f.name }) })),
                                ]"
                            />
                        </KField>
                        <KButton size="sm" variant="ghost" icon="trash" @click="def.notifications.splice(i, 1)">{{ __('Stop this email') }}</KButton>
                    </div>
                    <KButton
                        v-if="def.notifications.length < 5"
                        size="sm"
                        icon="plus"
                        @click="def.notifications.push({ to: '', subject: '', reply_to: emailFields[0]?.name ?? '' })"
                        >{{ __('Email each submission') }}</KButton
                    >
                </KCard>

                <KCard :title="__('Keeping and spam')">
                    <div class="k-ff-switches">
                        <KSwitch v-model="def.store" :label="__('Keep submissions here')" />
                        <p v-if="err('store')" class="k-field__error" role="alert">{{ err('store') }}</p>
                    </div>
                    <div class="k-ff-pair">
                        <KField v-if="def.store" v-slot="{ id: fid }" :label="__('Delete after (days)')" :help="__('0 keeps them until you delete them.')">
                            <KInput :id="fid" v-model.number="def.retention_days" type="number" min="0" max="3650" />
                        </KField>
                        <KField v-slot="{ id: fid }" :label="__('Per visitor and hour')" :help="__('More are refused.')">
                            <KInput :id="fid" v-model.number="def.per_ip_hour" type="number" min="1" max="1000" />
                        </KField>
                    </div>
                    <KSwitch v-model="def.captcha" :disabled="!meta.captcha && !def.captcha" :label="__('Ask for a captcha')" />
                    <p class="k-muted k-small k-ff-note">
                        {{
                            meta.captcha
                                ? __('Shown by the captcha plugin you installed.')
                                : __('Needs a captcha plugin. A hidden trap field, a minimum fill time and the limit above already stop most spam.')
                        }}
                    </p>
                    <p v-if="err('captcha')" class="k-field__error" role="alert">{{ err('captcha') }}</p>
                </KCard>

                <div class="k-editor__trash">
                    <KButton variant="ghost" icon="trash" @click="remove">{{ __('Delete this form') }}</KButton>
                </div>
            </template>
        </FormBuilder>
    </form>
</template>

<style scoped>
.k-form-title {
    max-width: 72ch;
    margin-bottom: 14px;
}
.k-ff-lead {
    margin: 4px 0 0;
    font-size: 14px;
}
.k-ff-textarea {
    width: 100%;
    resize: vertical;
    padding: 7px 10px;
    font: inherit;
}
.k-ff-note {
    margin: 4px 0 10px;
}
.k-ff-mail {
    padding-bottom: 12px;
    margin-bottom: 12px;
    border-bottom: 1px dashed var(--k-rule);
}
.k-ff-switches {
    margin-bottom: 12px;
}
.k-ff-pair {
    display: grid;
    grid-template-columns: 1fr 1fr;
    align-items: start;
    gap: 0 12px;
}
.k-ff-pair > .k-field + .k-field {
    margin-top: 0; /* side by side, not stacked */
}
:deep(.k-fbld__panel .k-card) {
    margin-bottom: 14px;
}
.k-editor__trash {
    margin-top: 8px;
}
</style>
