<script setup>
import { computed, ref, watch } from 'vue';
import { __ } from '@kestello/sdk';
import { KButton, KIcon } from '@kestello/ui';
import FieldSettings from './FieldSettings.vue';
import { blankField } from './fields.js';
import { fieldTypeLabel } from './field-labels.js';

/**
 * The fields of a content type (or of a group or repeating group inside
 * it): a list of rows, each opening into its settings (FieldSettings). Edits
 * the array in place. Field ids stay with a field through renames, so stored
 * values follow it.
 */
const props = defineProps({
    fields: { type: Array, required: true },
    meta: { type: Object, required: true }, // options, types, taxonomies
    errors: { type: Object, default: () => ({}) },
    path: { type: String, default: 'fields' }, // error key prefix
    depth: { type: Number, default: 1 },
    mode: { type: String, default: 'content' }, // content | form
});
const isForm = computed(() => props.mode === 'form');

const open = ref(new Set());
const toggle = (f) => {
    const s = new Set(open.value);
    s.has(f.id) ? s.delete(f.id) : s.add(f.id);
    open.value = s;
};

const hasErrors = (i) => Object.keys(props.errors).some((k) => k === `${props.path}.${i}` || k.startsWith(`${props.path}.${i}.`));
// a save that failed opens the rows it complains about
watch(
    () => props.errors,
    () => {
        const s = new Set(open.value);
        props.fields.forEach((f, i) => {
            if (hasErrors(i)) s.add(f.id);
        });
        open.value = s;
    },
);

function add() {
    const f = blankField();
    props.fields.push(f);
    open.value = new Set([...open.value, f.id]);
}
function remove(i) {
    props.fields.splice(i, 1);
}
function move(i, by) {
    const j = i + by;
    if (j < 0 || j >= props.fields.length) return;
    const [f] = props.fields.splice(i, 1);
    props.fields.splice(j, 0, f);
}
const summary = (f) =>
    [
        f.required && __('Required'),
        f.indexed && __('Sortable'),
        f.options?.display === 'radios' && __('Radio buttons'),
        f.internal && __('Editors only'),
        f.width === 'sidebar' && __('Sidebar'),
        f.conditions && __('Conditional'),
        f.options?.multiple && __('Several'),
        ['group', 'repeater'].includes(f.type) && (f.children.length === 1 ? __('1 field') : __('{n} fields', { n: f.children.length })),
    ]
        .filter(Boolean)
        .join(' · ');
</script>

<template>
    <div class="k-fb">
        <p v-if="!fields.length" class="k-fb__empty">
            {{
                isForm
                    ? __('No fields yet. Add what you want people to fill in.')
                    : depth === 1
                      ? __('No fields yet. Every entry already has a title, an address and a publishing date; add what else it needs.')
                      : __('Add the fields this group holds.')
            }}
        </p>
        <ol v-else class="k-fb__list">
            <li v-for="(f, i) in fields" :key="f.id" class="k-fb__item" :class="{ 'is-open': open.has(f.id), 'has-error': hasErrors(i) }">
                <div class="k-fb__row">
                    <button type="button" class="k-fb__summary" :aria-expanded="open.has(f.id) ? 'true' : 'false'" @click="toggle(f)">
                        <KIcon :name="open.has(f.id) ? 'caret-down' : 'caret-right'" class="k-fb__chev" />
                        <span class="k-fb__label">{{ f.label || f.name || __('New field') }}</span>
                        <code v-if="f.name" class="k-fb__name">{{ f.name }}</code>
                        <span class="k-fb__type">{{ fieldTypeLabel(f.type) }}</span>
                        <span v-if="summary(f)" class="k-fb__flags">{{ summary(f) }}</span>
                        <span v-if="hasErrors(i)" class="k-fb__problem"><KIcon name="warning-circle" /> {{ __('Needs attention') }}</span>
                    </button>
                    <div class="k-fb__tools">
                        <KButton
                            size="sm"
                            variant="ghost"
                            icon="arrow-up"
                            :disabled="i === 0"
                            :aria-label="__('Move {field} up', { field: f.label || f.name })"
                            @click="move(i, -1)"
                        />
                        <KButton
                            size="sm"
                            variant="ghost"
                            icon="arrow-down"
                            :disabled="i === fields.length - 1"
                            :aria-label="__('Move {field} down', { field: f.label || f.name })"
                            @click="move(i, 1)"
                        />
                        <KButton size="sm" variant="ghost" icon="trash" :aria-label="__('Remove {field}', { field: f.label || f.name })" @click="remove(i)" />
                    </div>
                </div>

                <div v-if="open.has(f.id)" class="k-fb__panel">
                    <FieldSettings :field="f" :index="i" :siblings="fields" :meta="meta" :errors="errors" :path="path" :depth="depth" :mode="mode" />
                </div>
            </li>
        </ol>
        <KButton class="k-fb__add" icon="plus" @click="add">{{ depth === 1 ? __('Add a field') : __('Add a field inside') }}</KButton>
    </div>
</template>

<style scoped>
.k-fb__empty {
    margin: 0 0 14px;
    color: var(--k-ink-2);
    max-width: 60ch;
}
.k-fb__list {
    list-style: none;
    margin: 0 0 14px;
    padding: 0;
    border-top: 1px solid var(--k-rule);
}
.k-fb__item {
    border-bottom: 1px solid var(--k-rule);
}
.k-fb__item.is-open {
    background: var(--k-sheet);
}
.k-fb__row {
    display: flex;
    align-items: center;
    gap: 8px;
}
.k-fb__summary {
    flex: 1;
    min-width: 0;
    display: flex;
    align-items: baseline;
    flex-wrap: wrap;
    gap: 4px 12px;
    padding: 11px 8px 11px 4px;
    border: 0;
    background: none;
    color: inherit;
    font: inherit;
    text-align: left;
    cursor: pointer;
}
.k-fb__summary:hover .k-fb__label {
    color: var(--k-mark);
}
.k-fb__summary:focus-visible {
    outline: 2px solid var(--k-focus);
    outline-offset: -2px;
}
.k-fb__chev {
    align-self: center;
    color: var(--k-ink-3);
    font-size: 12px;
}
.k-fb__label {
    font-weight: 600;
}
.k-fb__name {
    font-family: var(--k-mono);
    font-size: 12px;
    color: var(--k-ink-3);
}
.k-fb__type {
    font-size: 13px;
    color: var(--k-ink-2);
}
.k-fb__flags {
    font-size: 12.5px;
    color: var(--k-ink-3);
}
.k-fb__problem {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 12.5px;
    color: var(--k-danger);
}
.k-fb__item.has-error > .k-fb__row .k-fb__label {
    color: var(--k-danger);
}
.k-fb__tools {
    display: flex;
    gap: 2px;
    flex-shrink: 0;
}
.k-fb__panel {
    padding: 4px 16px 18px 26px;
}
.k-fs__children .k-fb__item.is-open {
    background: var(--k-paper);
}
@media (max-width: 640px) {
    .k-fb__panel {
        padding: 4px 4px 16px 8px;
    }
}
</style>
