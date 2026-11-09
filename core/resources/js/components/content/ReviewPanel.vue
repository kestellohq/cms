<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { confirm, formatDateTime, notify, useApi, useResource, useUser, __ } from '@kestello/sdk';
import { KButton, KCard, KField, KSelect, KTime } from '@kestello/ui';
import { insertMention, matchPeople, mentionAt, splitMentions } from './mentions.js';

/**
 * The review side of an entry: who it is assigned to, and its editorial
 * timeline (comments, with the review steps between them). Only people
 * in the admin see any of it. Typing @ suggests people who may edit
 * entries of this type; the ones named are told about the comment.
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
const people = useResource(() => `${props.typeBase}/assignees`);
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
// ── @mentions ────────────────────────────────────────────────────
const box = ref(null);
const typing = ref(null); // { start, query } while an @name is being typed
const active = ref(0);
const suggestions = computed(() => (typing.value ? matchPeople(people.data.value ?? [], typing.value.query) : []));
const open = computed(() => suggestions.value.length > 0);

function track() {
    const el = box.value;
    typing.value = el && el.selectionStart === el.selectionEnd ? mentionAt(draft.value, el.selectionStart) : null;
    active.value = 0;
}
async function pick(person) {
    const el = box.value;
    const next = insertMention(draft.value, el.selectionStart, person.username);
    draft.value = next.text;
    typing.value = null;
    await nextTick();
    el.focus();
    el.setSelectionRange(next.caret, next.caret);
}
function onKey(event) {
    if (open.value) {
        const n = suggestions.value.length;
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            active.value = (active.value + (event.key === 'ArrowDown' ? 1 : n - 1)) % n;
            return;
        }
        if ((event.key === 'Enter' && !event.metaKey && !event.ctrlKey) || event.key === 'Tab') {
            event.preventDefault();
            pick(suggestions.value[active.value]);
            return;
        }
        if (event.key === 'Escape') {
            event.preventDefault();
            typing.value = null;
            return;
        }
    }
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
                <p v-if="c.body" class="k-review__body">
                    <template v-for="(part, i) in splitMentions(c.body)" :key="i"
                        ><strong v-if="part.mention" class="k-review__mention">{{ part.text }}</strong
                        ><template v-else>{{ part.text }}</template></template
                    >
                </p>
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
            <div class="k-review__compose">
                <textarea
                    id="k-review-new"
                    ref="box"
                    v-model="draft"
                    class="k-input k-textarea"
                    rows="3"
                    maxlength="5000"
                    role="combobox"
                    aria-autocomplete="list"
                    aria-controls="k-review-people"
                    :aria-expanded="open ? 'true' : 'false'"
                    :aria-activedescendant="open ? `k-review-person-${active}` : undefined"
                    aria-describedby="k-review-hint"
                    @input="track"
                    @click="track"
                    @keyup.left="track"
                    @keyup.right="track"
                    @keydown="onKey"
                    @blur="typing = null"
                ></textarea>
                <ul v-show="open" id="k-review-people" class="k-review__people" role="listbox" :aria-label="__('People to mention')">
                    <li
                        v-for="(person, i) in suggestions"
                        :id="`k-review-person-${i}`"
                        :key="person.id"
                        role="option"
                        class="k-review__person"
                        :aria-selected="i === active ? 'true' : 'false'"
                        @mousedown.prevent="pick(person)"
                    >
                        @{{ person.username }}
                    </li>
                </ul>
            </div>
            <p id="k-review-hint" class="k-muted k-small k-review__hint">{{ __('Type @ and a name to tell someone.') }}</p>
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
    width: 100%;
}
.k-review__compose {
    position: relative;
}
.k-review__people {
    position: absolute;
    z-index: 5;
    left: 0;
    top: 100%;
    min-width: 12rem;
    max-width: 100%;
    margin: 4px 0 0;
    padding: 4px;
    list-style: none;
    background: var(--k-sheet);
    border: 1px solid var(--k-rule);
    border-radius: var(--k-radius);
    box-shadow: var(--k-shadow-lg);
}
.k-review__person {
    padding: 6px 8px;
    border-radius: calc(var(--k-radius) - 2px);
    font-size: 14px;
    cursor: pointer;
}
.k-review__person[aria-selected='true'],
.k-review__person:hover {
    background: var(--k-mark-soft);
}
.k-review__hint {
    margin: 0;
}
.k-review__mention {
    font-weight: 600;
    color: var(--k-ink);
}
</style>
