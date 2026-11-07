<script setup>
import { computed, ref, watch } from 'vue';
import { confirm, formatDateTime, notify, useApi, useResource, useUser, __ } from '@kestello/sdk';
import { KButton, KCard, KField, KSelect, KTime } from '@kestello/ui';

/**
 * The review side of an entry: who it is assigned to, and its editorial
 * timeline (comments, with the review steps between them). Only people
 * in the admin see any of it.
 */
const props = defineProps({
    base: { type: String, required: true }, // …/content/{type}/entries/{id}
    typeBase: { type: String, required: true }, // …/content/{type}
    entry: { type: Object, required: true },
    canEdit: { type: Boolean, default: false },
    canModerate: { type: Boolean, default: false }, // may delete anyone's comments
});
const emit = defineEmits(['assigned']);
const api = useApi();
const user = useUser();
const timeline = useResource(() => `${props.base}/comments`);
const people = useResource(() => `${props.typeBase}/assignees`, { immediate: props.canEdit });
const draft = ref('');
const posting = ref(false);
const assignee = ref(props.entry.assignee?.id ?? '');
watch(
    () => props.entry.assignee?.id,
    (id) => {
        assignee.value = id ?? '';
    },
);

const assigneeOptions = computed(() => [
    { value: '', label: __('Nobody') },
    ...(people.data.value ?? []).map((p) => ({ value: p.id, label: p.id === user.value?.id ? __('{name} (you)', { name: p.username }) : p.username })),
]);
const STEP = {
    submitted: (who) => __('{who} submitted it for review', { who }),
    changes_requested: (who) => __('{who} asked for changes', { who }),
    approved: (who) => __('{who} approved it', { who }),
};
const name = (c) => c.user?.username ?? __('Someone');

async function assign() {
    try {
        const { data } = await api.put(`${props.base}/assignee`, { user_id: assignee.value === '' ? null : Number(assignee.value) });
        emit('assigned', data);
        notify.success(data.assignee ? __('Assigned to {name}.', { name: data.assignee.username }) : __('No longer assigned.'));
    } catch (e) {
        assignee.value = props.entry.assignee?.id ?? '';
        notify.error(e);
    }
}
async function post() {
    if (!draft.value.trim()) return;
    posting.value = true;
    try {
        await api.post(`${props.base}/comments`, { body: draft.value });
        draft.value = '';
        timeline.reload({ silent: true });
    } catch (e) {
        notify.error(e);
    } finally {
        posting.value = false;
    }
}
async function remove(c) {
    if (!(await confirm({ title: __('Delete this comment?'), confirmLabel: __('Delete'), danger: true }))) return;
    try {
        await api.delete(`${props.base}/comments/${c.id}`);
        timeline.reload({ silent: true });
    } catch (e) {
        notify.error(e);
    }
}
function onKey(event) {
    if (event.key === 'Enter' && (event.metaKey || event.ctrlKey)) post();
}
defineExpose({ reload: () => timeline.reload({ silent: true }) });
</script>

<template>
    <KCard :title="__('Review')">
        <div class="k-form">
            <KField v-if="canEdit" v-slot="{ id }" :label="__('Assigned to')" :help="__('They are told, and a submission goes only to them.')">
                <KSelect :id="id" v-model="assignee" :options="assigneeOptions" @update:model-value="assign" />
            </KField>
            <p v-else-if="entry.assignee" class="k-small">{{ __('Assigned to {name}', { name: entry.assignee.username }) }}</p>
        </div>

        <ol v-if="timeline.data.value?.length" class="k-review__list" :aria-label="__('Comments')">
            <li v-for="c in timeline.data.value" :key="c.id" class="k-review__item" :class="{ 'is-step': c.kind !== 'comment', [`is-${c.kind}`]: true }">
                <p class="k-review__meta k-small">
                    <span class="k-review__who">{{ c.kind === 'comment' ? name(c) : (STEP[c.kind]?.(name(c)) ?? name(c)) }}</span>
                    <KTime :value="c.created_at" class="k-muted" />
                </p>
                <p v-if="c.body" class="k-review__body">{{ c.body }}</p>
                <button
                    v-if="c.kind === 'comment' && (c.user?.id === user?.id || canModerate)"
                    type="button"
                    class="k-review__delete k-small"
                    :aria-label="__('Delete the comment from {when}', { when: formatDateTime(c.created_at) })"
                    @click="remove(c)"
                >
                    {{ __('Delete') }}
                </button>
            </li>
        </ol>
        <p v-else-if="timeline.error.value" class="k-small k-review__empty" role="alert">{{ timeline.error.value.message }}</p>
        <p v-else-if="!timeline.loading.value" class="k-muted k-small k-review__empty">{{ __('No comments yet. Only people in the admin see them.') }}</p>

        <form class="k-review__form" @submit.prevent="post">
            <label class="k-review__label k-small" for="k-review-new">{{ __('Add a comment') }}</label>
            <textarea id="k-review-new" v-model="draft" class="k-input k-textarea" rows="3" maxlength="5000" @keydown="onKey"></textarea>
            <KButton type="submit" size="sm" :loading="posting" :disabled="!draft.trim()">{{ __('Comment') }}</KButton>
        </form>
    </KCard>
</template>

<style scoped>
.k-review__list {
    list-style: none;
    margin: 16px 0 0;
    padding: 0;
    border-top: 1px solid var(--k-rule);
}
.k-review__item {
    position: relative;
    padding: 10px 0;
    border-bottom: 1px solid var(--k-rule-soft);
}
.k-review__meta {
    display: flex;
    flex-wrap: wrap;
    gap: 4px 8px;
    align-items: baseline;
    margin: 0;
}
.k-review__who {
    font-weight: 600;
}
.k-review__item.is-step .k-review__who {
    font-weight: 500;
    color: var(--k-ink-2);
}
.k-review__item.is-changes_requested .k-review__who {
    color: var(--k-ink);
    font-weight: 600;
}
.k-review__body {
    margin: 4px 0 0;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    font-size: 14px;
    line-height: 1.5;
}
.k-review__item.is-changes_requested .k-review__body {
    padding: 8px 10px;
    background: var(--k-warning-soft);
    border-radius: var(--k-radius);
}
.k-review__delete {
    margin-top: 4px;
    padding: 0;
    border: 0;
    background: none;
    color: var(--k-ink-3);
    font: inherit;
    cursor: pointer;
}
.k-review__delete:hover,
.k-review__delete:focus-visible {
    color: var(--k-danger);
    text-decoration: underline;
}
.k-review__empty {
    margin: 14px 0 0;
}
.k-review__form {
    display: grid;
    gap: 6px;
    margin-top: 14px;
}
.k-review__form > .k-btn {
    justify-self: end;
}
.k-review__label {
    font-weight: 600;
}
.k-review__form .k-textarea {
    min-height: 72px;
}
</style>
