<script setup>
import { computed, nextTick, ref } from 'vue';
import { __, formatDateTime } from '@kestello/sdk';
import { whenRow } from './timetable.js';

/**
 * The visual editor's Page tab opens with this: a small timetable of when the page goes
 * live, its address, and its history, read at a glance. Changing the time or the address
 * opens its field in place. The form editor keeps its cards (Edit.vue).
 */
const publishAt = defineModel('publishAt', { type: String, default: '' }); // datetime-local value
const slug = defineModel('slug', { type: String, default: '' });
const props = defineProps({
    live: Boolean,
    future: Boolean,
    publishedAt: { type: String, default: null }, // ISO
    zone: { type: String, required: true },
    canPublish: Boolean,
    readOnly: Boolean,
    prefix: { type: String, default: null }, // null: the type has no addresses
    shownSlug: { type: String, default: '' }, // the slug as it will be (follows the title until set)
    slugError: { type: String, default: '' },
    createdAt: { type: String, default: null },
    version: { type: Number, default: null },
});

const when = computed(() => whenRow(props, __, formatDateTime));
const editingWhen = ref(false);
const editingSlug = ref(false);
const root = ref(null);
const open = (which) => {
    (which === 'when' ? editingWhen : editingSlug).value = true;
    nextTick(() => root.value?.querySelector(which === 'when' ? '#k-tt-when' : '#k-tt-slug')?.focus());
};
const clearTime = () => {
    publishAt.value = '';
    editingWhen.value = false;
};
</script>

<template>
    <div ref="root" class="k-tt">
        <dl class="k-tt__rows">
            <div class="k-tt__row">
                <dt>{{ when.label }}</dt>
                <dd>
                    <span :class="{ 'k-tt__sched': when.scheduled }">{{ when.value }}</span>
                    <button v-if="canPublish && !readOnly && !editingWhen" type="button" class="k-link-btn k-tt__change" @click="open('when')">
                        {{ publishedAt ? __('Change') : __('Set a time') }}
                    </button>
                </dd>
                <div v-if="editingWhen" class="k-tt__edit">
                    <label class="k-tt__label" for="k-tt-when">{{ live ? __('Published on') : __('Publish on') }}</label>
                    <input id="k-tt-when" v-model="publishAt" type="datetime-local" class="k-input" aria-describedby="k-tt-when-help" />
                    <p id="k-tt-when-help" class="k-tt__help">{{ __('Times are in {zone}. A time still to come makes Publish schedule it.', { zone }) }}</p>
                    <div class="k-tt__actions">
                        <button type="button" class="k-link-btn" @click="editingWhen = false">{{ __('Done') }}</button>
                        <button v-if="publishAt && !live" type="button" class="k-link-btn" @click="clearTime">{{ __('Publish when I choose Publish') }}</button>
                    </div>
                </div>
            </div>
            <div v-if="prefix !== null" class="k-tt__row">
                <dt>{{ __('Address') }}</dt>
                <dd>
                    <span class="k-tt__path">{{ prefix }}{{ shownSlug || '…' }}</span>
                    <button v-if="!readOnly && !editingSlug" type="button" class="k-link-btn k-tt__change" @click="open('slug')">{{ __('Change') }}</button>
                </dd>
                <p v-if="!editingSlug && !live && !slug" class="k-tt__note">{{ __('Follows the title until it is published.') }}</p>
                <div v-if="editingSlug" class="k-tt__edit">
                    <label class="k-tt__label" for="k-tt-slug">{{ __('Last part of the address') }}</label>
                    <input
                        id="k-tt-slug"
                        v-model="slug"
                        class="k-input"
                        maxlength="191"
                        spellcheck="false"
                        autocomplete="off"
                        data-lpignore="true"
                        data-1p-ignore
                        :aria-invalid="slugError ? 'true' : undefined"
                        :aria-describedby="slugError ? 'k-tt-slug-error' : undefined"
                        @keydown.enter.prevent="editingSlug = false"
                    />
                    <div class="k-tt__actions">
                        <button type="button" class="k-link-btn" @click="editingSlug = false">{{ __('Done') }}</button>
                    </div>
                </div>
                <p v-if="slugError" id="k-tt-slug-error" class="k-field__error">{{ slugError }}</p>
            </div>
            <div v-if="createdAt" class="k-tt__row">
                <dt>{{ __('Created') }}</dt>
                <dd>{{ formatDateTime(createdAt) }}</dd>
            </div>
            <div v-if="version" class="k-tt__row">
                <dt>{{ __('Version') }}</dt>
                <dd>{{ version }}</dd>
            </div>
        </dl>
        <slot />
    </div>
</template>

<style>
/* a timetable: labels left, values bold and right-aligned in tabular figures */
.k-tt {
    display: grid;
    gap: 12px;
}
.k-tt__rows {
    margin: 0;
    border-top: 1px solid var(--k-rule-soft);
}
.k-tt__row {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr);
    gap: 4px 12px;
    align-items: baseline;
    padding: 9px 0;
    border-bottom: 1px solid var(--k-rule-soft);
}
.k-tt__row dt {
    color: var(--k-ink-2);
    font-size: 12.5px;
}
.k-tt__row dd {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    align-items: baseline;
    gap: 2px 8px;
    margin: 0;
    min-width: 0;
    text-align: right;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
    color: var(--k-ink);
}
.k-tt__path {
    overflow-wrap: anywhere;
    font-weight: 600;
}
.k-tt__sched {
    padding: 0 5px;
    border-radius: 2px;
    background: var(--k-sched);
    color: #111827;
}
.k-tt__change {
    font-size: 12.5px;
    font-weight: 400;
}
.k-tt__note,
.k-tt__help {
    grid-column: 1 / -1;
    margin: 0;
    color: var(--k-ink-3);
    font-size: 12.5px;
    text-align: right;
}
.k-tt__help {
    text-align: left;
}
.k-tt__edit {
    grid-column: 1 / -1;
    display: grid;
    gap: 6px;
    margin-top: 4px;
}
.k-tt__label {
    font-size: 12.5px;
    font-weight: 600;
    color: var(--k-ink);
}
.k-tt__actions {
    display: flex;
    flex-wrap: wrap;
    gap: 4px 14px;
    font-size: 12.5px;
}
</style>
