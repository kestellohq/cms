<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { __ } from '@kestello/sdk';
import { KButton, KIcon, KTabs } from '@kestello/ui';
import FormField from '../../shared/FormField.vue';
import FieldSettings from '../content-types/FieldSettings.vue';
import { fieldTypeLabel } from '../content-types/field-labels.js';
import { newId } from '../content-types/fields.js';
import { dropSide, duplicateField, insertField, misplacedConditions, moveField, newFormField, PRESETS, removeField, TYPE_ICONS } from './form-builder.js';

/**
 * The visual form builder: field types and ready-made fields on the left (click to add
 * after the selected field, or drag into place), the form in the middle as visitors see
 * it (click a field to change it, drag to reorder), and the selected field's settings
 * or the form's own (slot #form) on the right. The list changes are form-builder.js.
 * The canvas holds real inputs (some required): a <form> around the builder needs
 * novalidate, or the browser refuses to submit it.
 */
const fields = defineModel('fields', { type: Array, required: true });
const props = defineProps({
    meta: { type: Object, required: true },
    errors: { type: Object, default: () => ({}) },
    submitLabel: { type: String, default: '' },
});

const selectedId = ref(null);
const tab = ref('form');
const live = ref('');
const canvas = ref(null);
const selectedIndex = computed(() => fields.value.findIndex((f) => f.id === selectedId.value));
const selectedField = computed(() => fields.value[selectedIndex.value] ?? null);
const misplaced = computed(() => misplacedConditions(fields.value));
const tabs = computed(() => [
    { id: 'field', label: __('Field') },
    { id: 'form', label: __('Form') },
]);

const PRESET_LABELS = {
    name: __('Name'),
    email: __('Email'),
    phone: __('Phone'),
    message: __('Message'),
    consent: __('I agree to be contacted about my message'),
};
const types = computed(() => Object.keys(props.meta.options).map((t) => ({ type: t, label: fieldTypeLabel(t), icon: TYPE_ICONS[t] ?? 'square' })));
const errorsOf = (i) => Object.keys(props.errors).some((k) => k === `fields.${i}` || k.startsWith(`fields.${i}.`));

// a save that failed: show the first field it complains about
watch(
    () => props.errors,
    (e) => {
        const i = fields.value.findIndex((_, j) => errorsOf(j));
        if (i !== -1 && e) select(fields.value[i].id);
    },
);

// ── undo for adding, moving, duplicating and removing (settings are changed in place).
// fields reaches the page's copy on the next render, so each action makes one change.
const history = ref([]);
function change(next, message) {
    history.value = [...history.value.slice(-49), fields.value];
    fields.value = next;
    live.value = message;
}
function undo() {
    const prev = history.value.at(-1);
    if (!prev) return;
    history.value = history.value.slice(0, -1);
    fields.value = prev;
    if (!prev.some((f) => f.id === selectedId.value)) selectedId.value = null;
    live.value = __('Undone.');
}

function select(id, { focus = false } = {}) {
    selectedId.value = id;
    tab.value = id ? 'field' : 'form';
    if (focus) nextTick(() => canvas.value?.querySelector(`[data-field="${id}"]`)?.focus());
}
const nameOf = (f) => f.label || f.name || __('New field');

function add(what, index = selectedIndex.value === -1 ? fields.value.length : selectedIndex.value + 1) {
    const label = what.preset ? PRESET_LABELS[what.preset.key] : fieldTypeLabel(what.type);
    const field = newFormField(fields.value, { ...what, label, allowed: props.meta.options[what.preset?.type ?? what.type] ?? {} });
    change(insertField(fields.value, field, index), __('{field} added.', { field: label }));
    select(field.id);
}
/** The usual start, in one step: name and email side by side, then the message. */
function addStarter() {
    let next = fields.value;
    for (const key of ['name', 'email', 'message']) {
        const preset = PRESETS.find((p) => p.key === key);
        next = insertField(next, newFormField(next, { preset, label: PRESET_LABELS[key], allowed: props.meta.options[preset.type] ?? {} }));
    }
    change(next, __('Name, email and message added.'));
    select(next[0].id);
}
function move(i, to) {
    const next = moveField(fields.value, i, to);
    if (next === fields.value) return;
    const f = fields.value[i];
    change(next, __('{field} moved to position {n} of {count}.', { field: nameOf(f), n: next.indexOf(f) + 1, count: next.length }));
    select(f.id, { focus: true });
}
function duplicate(i) {
    const { fields: next, copy } = duplicateField(fields.value, i, newId);
    if (!copy) return;
    change(next, __('{field} duplicated.', { field: nameOf(copy) }));
    select(copy.id, { focus: true });
}
function remove(i) {
    const f = fields.value[i];
    const { fields: next, touched } = removeField(fields.value, i);
    change(
        next,
        touched.length
            ? __('{field} removed; the conditions that used it were dropped from {others}. Undo brings it all back.', {
                  field: nameOf(f),
                  others: touched.join(', '),
              })
            : __('{field} removed.', { field: nameOf(f) }),
    );
    const after = next[i] ?? next[i - 1] ?? null;
    select(after?.id ?? null, { focus: !!after });
}
const toggleWidth = (f) => {
    f.width = f.width === 'half' ? 'full' : 'half';
    live.value = f.width === 'half' ? __('Half width.') : __('Full width.');
};

function itemKey(event, i) {
    if (event.target !== event.currentTarget) return;
    if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        select(fields.value[i].id);
    } else if (event.altKey && event.key === 'ArrowUp') {
        event.preventDefault();
        move(i, i - 1);
    } else if (event.altKey && event.key === 'ArrowDown') {
        event.preventDefault();
        move(i, i + 2);
    } else if (event.key === 'ArrowUp' || event.key === 'ArrowDown') {
        event.preventDefault();
        const to = fields.value[i + (event.key === 'ArrowUp' ? -1 : 1)];
        if (to) select(to.id, { focus: true });
    } else if (event.key === 'Delete' || event.key === 'Backspace') {
        event.preventDefault();
        remove(i);
    } else if (event.key === 'Escape') {
        event.preventDefault();
        select(null);
    }
}
function onKey(event) {
    const typing = event.target instanceof HTMLElement && (event.target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(event.target.tagName));
    if (!typing && (event.metaKey || event.ctrlKey) && !event.shiftKey && event.key.toLowerCase() === 'z') {
        event.preventDefault();
        undo();
    }
}

// ── dragging: a field to another place, or a type from the left into the form
const FIELD = 'application/x-kestello-form-field';
const NEW = 'application/x-kestello-form-new';
const drop = ref(null); // { index, side } | { end: true }
let dragging = null;
function dragField(event, i) {
    dragging = { index: i };
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData(FIELD, String(i));
}
function dragNew(event, what) {
    dragging = { what };
    event.dataTransfer.effectAllowed = 'copy';
    event.dataTransfer.setData(NEW, what.preset?.key ?? what.type);
}
function over(event, i) {
    if (!dragging) return;
    event.preventDefault();
    const f = fields.value[i];
    drop.value = { index: i, side: dropSide(event.currentTarget.getBoundingClientRect(), event.clientX, event.clientY, f.width) };
}
function overEnd(event) {
    if (!dragging) return;
    event.preventDefault();
    drop.value = { end: true };
}
function finish(event) {
    event.preventDefault();
    const d = drop.value,
        what = dragging;
    endDrag();
    if (!d || !what) return;
    const to = d.end ? fields.value.length : d.index + (d.side === 'after' ? 1 : 0);
    if (what.what) add(what.what, to);
    else move(what.index, to);
}
function endDrag() {
    dragging = null;
    drop.value = null;
}

// the canvas speaks the admin's language; the site's Form block gets the same words from the server
const fileWords = { one: (p) => __('Up to {size} MB.', p), many: (p) => __('Up to {count} files, {size} MB each.', p) };
</script>

<template>
    <div class="k-fbld" @keydown="onKey">
        <aside class="k-fbld__palette" :aria-label="__('Add a field')">
            <div class="k-fbld__palette-head">
                <h2>{{ __('Add a field') }}</h2>
                <KButton
                    size="sm"
                    variant="ghost"
                    icon="arrow-counter-clockwise"
                    :disabled="!history.length"
                    :title="__('Undo')"
                    :aria-label="__('Undo')"
                    @click="undo"
                />
            </div>
            <h3>{{ __('Ready-made') }}</h3>
            <div class="k-fbld__tiles">
                <button
                    v-for="p in PRESETS"
                    :key="p.key"
                    type="button"
                    class="k-fbld__tile"
                    draggable="true"
                    @click="add({ preset: p })"
                    @dragstart="dragNew($event, { preset: p })"
                    @dragend="endDrag"
                >
                    <KIcon :name="p.icon" /><span>{{ p.key === 'consent' ? __('Consent') : PRESET_LABELS[p.key] }}</span>
                </button>
            </div>
            <h3>{{ __('Field types') }}</h3>
            <div class="k-fbld__tiles">
                <button
                    v-for="t in types"
                    :key="t.type"
                    type="button"
                    class="k-fbld__tile"
                    draggable="true"
                    @click="add({ type: t.type })"
                    @dragstart="dragNew($event, { type: t.type })"
                    @dragend="endDrag"
                >
                    <KIcon :name="t.icon" /><span>{{ t.label }}</span>
                </button>
            </div>
            <p class="k-fbld__hint">{{ __('Click to add after the selected field, or drag into place.') }}</p>
        </aside>

        <section ref="canvas" class="k-fbld__canvas" :aria-label="__('The form as visitors see it')" @click.self="select(null)">
            <div class="k-fbld__sheet">
                <ul v-if="fields.length" class="k-b-form__grid k-fbld__grid" role="listbox" :aria-label="__('Fields')" @dragover.prevent @drop="finish">
                    <li
                        v-for="(f, i) in fields"
                        :key="f.id"
                        :data-field="f.id"
                        role="option"
                        :aria-selected="String(selectedId === f.id)"
                        tabindex="0"
                        class="k-b-form__field k-fbld__item"
                        :class="[
                            `k-b-form__field--${f.width}`,
                            { 'is-selected': selectedId === f.id, 'has-error': errorsOf(i), [`drop-${drop?.side}`]: drop?.index === i },
                        ]"
                        :aria-label="`${nameOf(f)}, ${fieldTypeLabel(f.type)}${f.required ? `, ${__('required')}` : ''}`"
                        draggable="true"
                        @click="select(f.id)"
                        @keydown="itemKey($event, i)"
                        @dragstart="dragField($event, i)"
                        @dragover="over($event, i)"
                        @dragend="endDrag"
                    >
                        <div class="k-fbld__field" inert>
                            <FormField
                                :model-value="f.type === 'multi_select' ? [] : f.type === 'boolean' ? f.default === true : (f.default ?? '')"
                                :field="f"
                                :input-id="`k-fbld-${f.id}`"
                                :choose-text="__('Choose…')"
                                :file-words="fileWords"
                            />
                        </div>
                        <div v-if="f.conditions || errorsOf(i) || misplaced.has(f.id)" class="k-fbld__flags">
                            <span v-if="errorsOf(i)" class="k-fbld__flag is-danger"><KIcon name="warning-circle" />{{ __('Needs attention') }}</span>
                            <span v-if="misplaced.has(f.id)" class="k-fbld__flag is-danger"
                                ><KIcon name="arrow-up" />{{ __('Its condition reads a field below it; move it lower') }}</span
                            >
                            <span v-else-if="f.conditions" class="k-fbld__flag"><KIcon name="eye" />{{ __('Shown only when its condition holds') }}</span>
                        </div>
                        <div
                            v-if="selectedId === f.id"
                            class="k-fbld__tools"
                            role="toolbar"
                            :aria-label="__('{field} field', { field: nameOf(f) })"
                            @click.stop
                        >
                            <button
                                type="button"
                                class="k-fbld__tool"
                                :disabled="i === 0"
                                :title="__('Move up (Alt+Up)')"
                                :aria-label="__('Move up')"
                                @click="move(i, i - 1)"
                            >
                                <KIcon name="arrow-up" />
                            </button>
                            <button
                                type="button"
                                class="k-fbld__tool"
                                :disabled="i === fields.length - 1"
                                :title="__('Move down (Alt+Down)')"
                                :aria-label="__('Move down')"
                                @click="move(i, i + 2)"
                            >
                                <KIcon name="arrow-down" />
                            </button>
                            <button
                                type="button"
                                class="k-fbld__tool"
                                :title="f.width === 'half' ? __('Full width') : __('Half width')"
                                :aria-label="f.width === 'half' ? __('Full width') : __('Half width')"
                                @click="toggleWidth(f)"
                            >
                                <KIcon :name="f.width === 'half' ? 'arrows-out-line-horizontal' : 'arrows-in-line-horizontal'" />
                            </button>
                            <button type="button" class="k-fbld__tool" :title="__('Duplicate')" :aria-label="__('Duplicate')" @click="duplicate(i)">
                                <KIcon name="copy" />
                            </button>
                            <button type="button" class="k-fbld__tool" :title="__('Remove (Delete)')" :aria-label="__('Remove')" @click="remove(i)">
                                <KIcon name="trash" />
                            </button>
                        </div>
                    </li>
                    <li class="k-fbld__end" :class="{ 'is-over': drop?.end }" aria-hidden="true" @dragover="overEnd"></li>
                </ul>
                <div v-else class="k-fbld__empty" :class="{ 'is-over': drop?.end }" @dragover="overEnd" @drop="finish">
                    <p>{{ __('No fields yet. Add one from the left, or drag it here.') }}</p>
                    <KButton size="sm" icon="lightning" @click="addStarter">{{ __('Start with name, email and message') }}</KButton>
                </div>
                <span class="k-fbld__submit" aria-hidden="true">{{ submitLabel || __('Send') }}</span>
            </div>
        </section>

        <aside class="k-fbld__panel" :aria-label="__('Settings')">
            <KTabs v-model="tab" :tabs="tabs" :label="__('Settings')" />
            <div class="k-fbld__panel-body">
                <template v-if="tab === 'field'">
                    <template v-if="selectedField">
                        <div class="k-fbld__panel-head">
                            <KIcon :name="TYPE_ICONS[selectedField.type] ?? 'square'" />
                            <span>{{ nameOf(selectedField) }}</span>
                        </div>
                        <FieldSettings
                            :key="selectedField.id"
                            :field="selectedField"
                            :index="selectedIndex"
                            :siblings="fields"
                            :meta="meta"
                            :errors="errors"
                            mode="form"
                        />
                    </template>
                    <p v-else class="k-fbld__none">{{ __('Select a field in the form to change it.') }}</p>
                </template>
                <div v-show="tab === 'form'"><slot name="form" /></div>
            </div>
        </aside>
        <div class="k-sr-only" aria-live="polite" role="status">{{ live }}</div>
    </div>
</template>

<style>
.k-fbld {
    display: grid;
    grid-template-columns: 220px minmax(0, 1fr) 360px;
    /* a workspace the height of the window: each column scrolls by itself, never the page */
    grid-template-rows: minmax(0, 1fr);
    gap: 0;
    height: max(480px, calc(100dvh - 182px));
    border: 1px solid var(--k-rule);
    border-radius: var(--k-radius);
    background: var(--k-sheet);
}
.k-fbld__palette {
    padding: 14px;
    border-right: 1px solid var(--k-rule);
    overflow-y: auto;
}
.k-fbld__palette-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 8px;
}
.k-fbld__palette h2 {
    margin: 0;
    font-size: 13px;
    font-weight: 600;
}
.k-fbld__palette h3 {
    margin: 14px 0 6px;
    font-size: 12px;
    font-weight: 600;
    color: var(--k-ink-2);
}
.k-fbld__tiles {
    display: grid;
    gap: 2px;
}
.k-fbld__tile {
    display: flex;
    align-items: center;
    gap: 8px;
    min-height: 32px;
    padding: 0 8px;
    border: 0;
    border-radius: var(--k-radius);
    background: none;
    color: var(--k-ink);
    font: inherit;
    font-size: 13px;
    text-align: left;
    cursor: grab;
}
.k-fbld__tile:hover {
    background: var(--k-sheet-2);
}
.k-fbld__tile:focus-visible {
    outline: 2px solid var(--k-focus);
    outline-offset: -2px;
}
.k-fbld__tile .k-icon {
    color: var(--k-ink-2);
}
.k-fbld__hint {
    margin: 14px 0 0;
    font-size: 12px;
    color: var(--k-ink-3);
    line-height: 1.45;
}

.k-fbld__canvas {
    min-width: 0;
    padding: 28px;
    background: var(--k-paper);
    overflow-y: auto;
}
.k-fbld__sheet {
    max-width: 720px;
    margin: 0 auto;
    padding: 32px 36px 36px;
    border: 1px solid var(--k-rule);
    border-radius: var(--k-radius);
    background: #fff;
    box-shadow: var(--k-shadow-sheet);
    color: #1d1d1b;
    font-size: 15px;
    line-height: 1.5;
    color-scheme: light;
}
.k-fbld__grid {
    margin: 0;
    padding: 0;
    list-style: none;
}
.k-fbld__item {
    position: relative;
    padding: 8px 10px;
    margin: -8px -10px;
    border-radius: 6px;
    outline: 1px solid transparent;
    cursor: pointer;
}
.k-fbld__item:hover {
    outline-color: color-mix(in srgb, var(--k-focus) 45%, transparent);
}
.k-fbld__item.is-selected {
    outline: 2px solid var(--k-focus);
}
.k-fbld__item.has-error {
    outline: 1px solid var(--k-danger);
}
.k-fbld__item:focus-visible {
    outline: 2px solid var(--k-focus);
    outline-offset: 2px;
}
.k-fbld__item.drop-before::before,
.k-fbld__item.drop-after::after {
    content: '';
    position: absolute;
    left: 0;
    right: 0;
    height: 3px;
    border-radius: 2px;
    background: var(--k-focus);
}
.k-fbld__item.drop-before::before {
    top: -10px;
}
.k-fbld__item.drop-after::after {
    bottom: -10px;
}
.k-fbld__item.k-b-form__field--half.drop-before::before,
.k-fbld__item.k-b-form__field--half.drop-after::after {
    top: 0;
    bottom: 0;
    width: 3px;
    height: auto;
}
.k-fbld__item.k-b-form__field--half.drop-before::before {
    left: -12px;
    right: auto;
}
.k-fbld__item.k-b-form__field--half.drop-after::after {
    right: -12px;
    left: auto;
}
.k-fbld__field {
    pointer-events: none;
}
.k-fbld__flags {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 6px;
}
.k-fbld__flag {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 1px 7px;
    border-radius: 999px;
    background: var(--k-sheet-2);
    color: var(--k-ink-2);
    font-size: 11.5px;
    font-weight: 500;
}
.k-fbld__flag.is-danger {
    background: color-mix(in srgb, var(--k-danger) 12%, transparent);
    color: var(--k-danger);
}
.k-fbld__tools {
    position: absolute;
    top: -16px;
    right: 8px;
    z-index: 2;
    display: flex;
    gap: 1px;
    padding: 2px;
    border: 1px solid var(--k-rule);
    border-radius: var(--k-radius);
    background: var(--k-sheet);
    box-shadow: var(--k-shadow-md, 0 4px 12px rgb(0 0 0 / 0.12));
}
.k-fbld__tool {
    display: grid;
    place-items: center;
    width: 28px;
    height: 26px;
    border: 0;
    border-radius: 4px;
    background: none;
    color: var(--k-ink);
    cursor: pointer;
}
.k-fbld__tool:hover:not(:disabled) {
    background: var(--k-sheet-2);
}
.k-fbld__tool:disabled {
    opacity: 0.35;
    cursor: default;
}
.k-fbld__tool:focus-visible {
    outline: 2px solid var(--k-focus);
    outline-offset: -2px;
}
.k-fbld__end {
    grid-column: 1 / -1;
    min-height: 14px;
    border-radius: 4px;
}
.k-fbld__end.is-over {
    background: color-mix(in srgb, var(--k-focus) 25%, transparent);
}
.k-fbld__empty {
    display: grid;
    justify-items: center;
    gap: 12px;
    padding: 40px 20px;
    border: 1px dashed var(--k-rule);
    border-radius: 6px;
    color: var(--k-ink-2);
    text-align: center;
}
.k-fbld__empty.is-over {
    border-color: var(--k-focus);
    background: color-mix(in srgb, var(--k-focus) 8%, transparent);
}
.k-fbld__empty p {
    margin: 0;
}
.k-fbld__submit {
    display: inline-block;
    margin-top: 1.4rem;
    padding: 0.55rem 1.4rem;
    border-radius: 999px;
    background: #2448c9;
    color: #fff;
    font-weight: 700;
}

.k-fbld__panel {
    min-width: 0;
    min-height: 0;
    border-left: 1px solid var(--k-rule);
    display: flex;
    flex-direction: column;
}
.k-fbld__panel > .k-tabs {
    padding: 0 14px;
    border-bottom: 1px solid var(--k-rule);
}
.k-fbld__panel-body {
    flex: 1;
    min-height: 0;
    padding: 14px;
    overflow-y: auto;
}
.k-fbld__panel-head {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 12px;
    font-size: 13px;
    font-weight: 600;
}
.k-fbld__panel .k-fs__grid {
    grid-template-columns: 1fr;
}
.k-fbld__panel .k-fs__switches {
    margin-top: 14px;
}
.k-fbld__none {
    margin: 20px 0;
    color: var(--k-ink-2);
    font-size: 13px;
}
@media (max-width: 1280px) {
    .k-fbld {
        grid-template-columns: 200px minmax(0, 1fr) 320px;
    }
}
@media (max-width: 1080px) {
    .k-fbld {
        grid-template-columns: 1fr;
        grid-template-rows: none;
        height: auto;
    }
    .k-fbld__palette {
        border-right: 0;
        border-bottom: 1px solid var(--k-rule);
    }
    .k-fbld__tiles {
        grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    }
    .k-fbld__panel {
        border-left: 0;
        border-top: 1px solid var(--k-rule);
    }
    .k-fbld__canvas {
        padding: 16px;
    }
    .k-fbld__sheet {
        padding: 20px;
    }
}
</style>
