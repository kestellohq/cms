<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, Link, useRouter } from '@kestello/app';
import { ApiError, notify, useApi, useResource, __ } from '@kestello/sdk';
import { KAlert, KBadge, KButton, KCard, KField, KInput, KModal, KPageHeader, KTable, KTime } from '@kestello/ui';

defineOptions({ layout: 'Admin' });

/** Content → Forms: the site's forms and what came in. */
const api = useApi();
const router = useRouter();
const list = useResource('/forms');
const forms = computed(() => list.data.value ?? []);

const columns = [
    { key: 'title', label: __('Form') },
    { key: 'status', label: __('State') },
    { key: 'submissions', label: __('Received'), align: 'end' },
    { key: 'updated_at', label: __('Changed') },
    { key: 'actions', label: '', align: 'end' },
];

// ── create ───────────────────────────────────────────────────────────
const STARTERS = {
    contact: () => [
        { name: 'name', type: 'text', label: __('Your name'), required: true, width: 'half', options: { max: 100 } },
        { name: 'email', type: 'email', label: __('Email'), required: true, width: 'half', options: {} },
        { name: 'message', type: 'textarea', label: __('Message'), required: true, options: { rows: 6, max: 5000 } },
    ],
    blank: () => [{ name: 'name', type: 'text', label: __('Your name'), required: true, options: {} }],
};
const creating = ref(false);
const form = reactive({ title: '', starter: 'contact' });
const errors = ref({});
const saving = ref(false);

async function create() {
    saving.value = true;
    errors.value = {};
    const fields = STARTERS[form.starter]();
    const definition = { fields, notifications: [] };
    try {
        const { data } = await api.post('/forms', { title: form.title.trim(), definition });
        creating.value = false;
        router.visit(`/admin/forms/${data.id}`);
    } catch (e) {
        if (e instanceof ApiError && e.status === 422) errors.value = e.fieldErrors();
        else notify.error(e);
    } finally {
        saving.value = false;
    }
}
// (an ICU plural's closing braces would end a {{ }} in the template)
const fieldsText = (n) => __('{n, plural, one {# field} other {# fields}}', { n });
const newText = (n) => __('{n, plural, one {# new} other {# new}}', { n });
</script>

<template>
    <Head :title="__('Forms')" />
    <KPageHeader
        :title="__('Forms')"
        :description="
            __('Contact forms, sign-ups and applications. Put one on any page with the Form block; what people send arrives here and, if you like, by email.')
        "
    >
        <template #actions>
            <KButton
                variant="primary"
                icon="plus"
                @click="
                    form.title = '';
                    form.starter = 'contact';
                    errors = {};
                    creating = true;
                "
                >{{ __('New form') }}</KButton
            >
        </template>
    </KPageHeader>

    <KAlert v-if="list.error.value" tone="danger">{{ list.error.value.message }}</KAlert>

    <KCard coord="F1" :title="__('Forms')" flush>
        <KTable
            :columns="columns"
            :rows="forms"
            :loading="list.loading.value && !list.data.value"
            :empty="__('No forms yet')"
            :empty-text="__('Start with a contact form; you can change every field.')"
        >
            <template #cell-title="{ row }">
                <Link :href="`/admin/forms/${row.id}`" class="k-link k-forms__title">{{ row.title }}</Link>
                <div class="k-muted k-small">
                    {{ fieldsText(row.definition.fields.length)
                    }}<template v-if="row.definition.notifications.length">
                        ·
                        {{
                            __('emails {to}', {
                                to: row.definition.notifications
                                    .flatMap((n) => n.to)
                                    .slice(0, 2)
                                    .join(', '),
                            })
                        }}</template
                    >
                </div>
            </template>
            <template #cell-status="{ row }"
                ><KBadge :tone="row.status === 'open' ? 'success' : undefined">{{ row.status === 'open' ? __('Open') : __('Closed') }}</KBadge></template
            >
            <template #cell-submissions="{ row }">
                <Link :href="`/admin/forms/${row.id}/submissions`" class="k-link k-num">{{ row.submissions.total.toLocaleString() }}</Link>
                <KBadge v-if="row.submissions.new" tone="info" class="k-forms__new">{{ newText(row.submissions.new) }}</KBadge>
            </template>
            <template #cell-updated_at="{ row }"><KTime v-if="row.updated_at" :value="row.updated_at" /></template>
            <template #cell-actions="{ row }">
                <div class="k-row-actions">
                    <KButton size="sm" variant="ghost" icon="tray" :href="`/admin/forms/${row.id}/submissions`">{{ __('Submissions') }}</KButton>
                    <KButton size="sm" variant="ghost" icon="pencil" :href="`/admin/forms/${row.id}`">{{ __('Edit') }}</KButton>
                </div>
            </template>
        </KTable>
    </KCard>

    <KModal :open="creating" :title="__('New form')" @close="creating = false">
        <form id="k-form-create" class="k-form" @submit.prevent="create">
            <KField
                v-slot="{ id, describedBy, invalid }"
                :label="__('Name')"
                :help="__('For you and in notification emails. Visitors don\'t see it.')"
                :error="errors.title ?? ''"
                required
            >
                <KInput :id="id" v-model="form.title" maxlength="200" :invalid="invalid" :aria-describedby="describedBy" />
            </KField>
            <fieldset class="k-forms__starters">
                <legend>{{ __('Start with') }}</legend>
                <label class="k-forms__starter"
                    ><input v-model="form.starter" type="radio" value="contact" />
                    <span
                        ><strong>{{ __('A contact form') }}</strong
                        ><span class="k-muted k-small">{{ __('Name, email and message') }}</span></span
                    ></label
                >
                <label class="k-forms__starter"
                    ><input v-model="form.starter" type="radio" value="blank" />
                    <span
                        ><strong>{{ __('Almost nothing') }}</strong
                        ><span class="k-muted k-small">{{ __('One field to build on') }}</span></span
                    ></label
                >
            </fieldset>
        </form>
        <template #footer>
            <KButton @click="creating = false">{{ __('Cancel') }}</KButton>
            <KButton variant="primary" type="submit" form="k-form-create" :loading="saving">{{ __('Create') }}</KButton>
        </template>
    </KModal>
</template>

<style scoped>
.k-forms__title {
    font-weight: 600;
}
.k-forms__new {
    margin-left: 8px;
}
.k-forms__starters {
    border: 0;
    margin: 0;
    padding: 0;
    display: grid;
    gap: 8px;
}
.k-forms__starters legend {
    font-size: 13px;
    font-weight: 600;
    margin-bottom: 6px;
    padding: 0;
}
.k-forms__starter {
    display: flex;
    gap: 10px;
    align-items: flex-start;
    padding: 10px 12px;
    border: 1px solid var(--k-rule);
    border-radius: var(--k-radius);
    cursor: pointer;
}
.k-forms__starter:has(input:checked) {
    border-color: var(--k-ink);
    background: var(--k-sheet-2);
}
.k-forms__starter input {
    margin-top: 3px;
    accent-color: var(--k-mark);
}
.k-forms__starter > span {
    display: grid;
    gap: 2px;
}
.k-num {
    font-variant-numeric: tabular-nums;
}
</style>
