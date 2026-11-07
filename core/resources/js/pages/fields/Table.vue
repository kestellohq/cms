<script setup>
import { computed, nextTick, ref } from 'vue';
import { __ } from '@kestello/sdk';
import { KButton, KSwitch } from '@kestello/ui';
import {
    addColumn,
    addRow,
    blankTable,
    MAX_COLUMNS,
    MAX_ROWS,
    moveRow,
    parseGrid,
    pasteGrid,
    removeColumn,
    removeRow,
    setCell,
    tidy,
    width,
} from '../../shared/table.js';

/**
 * The table field (Fields\TableField): a grid of cells. Rows and columns are added and
 * removed with the buttons; text copied from a spreadsheet pastes in as cells.
 * The logic is shared/table.js; this only draws it.
 */
const model = defineModel({ default: null });
const props = defineProps({
    field: { type: Object, required: true },
    id: { type: String, default: '' },
    invalid: Boolean,
    disabled: Boolean,
    errors: { type: Object, default: () => ({}) },
    path: { type: String, default: '' },
});

const table = computed(() => tidy(model.value ?? blankTable()));
const cols = computed(() => width(table.value));
const grid = ref(null);
const put = (next) => {
    if (!props.disabled) model.value = next;
};

function focusCell(r, c) {
    nextTick(() => grid.value?.querySelector(`[data-cell="${r}-${c}"]`)?.focus());
}
function onPaste(event, r, c) {
    const cells = parseGrid(event.clipboardData?.getData('text/plain'));
    if (!cells) return; // plain text: the browser pastes it into this cell
    event.preventDefault();
    put(pasteGrid(table.value, r, c, cells));
}
// Enter goes down a row (adding one at the end), like a spreadsheet
function onEnter(r, c) {
    if (r === table.value.rows.length - 1) put(addRow(table.value));
    focusCell(r + 1, c);
}
const cellLabel = (r, c) => __('Row {row}, column {column}', { row: r + 1, column: c + 1 });
</script>

<template>
    <div :id="id" class="k-tablefield" :aria-invalid="invalid ? 'true' : undefined">
        <KSwitch :model-value="table.head" :label="__('First row is headings')" :disabled="disabled" @update:model-value="put({ ...table, head: $event })" />
        <div ref="grid" class="k-tablefield__scroll">
            <table class="k-tablefield__grid">
                <thead>
                    <tr>
                        <td v-for="(_, c) in cols" :key="c" class="k-tablefield__coltools">
                            <button
                                v-if="cols > 1 && !disabled"
                                type="button"
                                class="k-tablefield__tool"
                                :title="__('Remove column {n}', { n: c + 1 })"
                                :aria-label="__('Remove column {n}', { n: c + 1 })"
                                @click="put(removeColumn(table, c))"
                            >
                                ×
                            </button>
                        </td>
                        <td></td>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(row, r) in table.rows" :key="r" :class="{ 'is-head': table.head && r === 0 }">
                        <td v-for="(cell, c) in row" :key="c">
                            <input
                                class="k-input k-tablefield__cell"
                                :value="cell"
                                :data-cell="`${r}-${c}`"
                                :aria-label="cellLabel(r, c)"
                                :maxlength="1000"
                                :disabled="disabled"
                                @input="put(setCell(table, r, c, $event.target.value))"
                                @paste="onPaste($event, r, c)"
                                @keydown.enter.prevent="onEnter(r, c)"
                            />
                        </td>
                        <td class="k-tablefield__rowtools">
                            <template v-if="!disabled">
                                <button
                                    type="button"
                                    class="k-tablefield__tool"
                                    :disabled="r === 0"
                                    :aria-label="__('Move row {n} up', { n: r + 1 })"
                                    :title="__('Move up')"
                                    @click="put(moveRow(table, r, -1))"
                                >
                                    ↑
                                </button>
                                <button
                                    v-if="table.rows.length > 1"
                                    type="button"
                                    class="k-tablefield__tool"
                                    :aria-label="__('Remove row {n}', { n: r + 1 })"
                                    :title="__('Remove row')"
                                    @click="put(removeRow(table, r))"
                                >
                                    ×
                                </button>
                            </template>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div v-if="!disabled" class="k-tablefield__add">
            <KButton
                size="sm"
                icon="plus"
                :disabled="table.rows.length >= MAX_ROWS"
                @click="
                    put(addRow(table));
                    focusCell(table.rows.length, 0);
                "
                >{{ __('Row') }}</KButton
            >
            <KButton size="sm" icon="plus" :disabled="cols >= MAX_COLUMNS" @click="put(addColumn(table))">{{ __('Column') }}</KButton>
            <span class="k-muted k-small">{{ __('Paste from a spreadsheet to fill several cells.') }}</span>
        </div>
    </div>
</template>

<style>
.k-tablefield {
    display: grid;
    gap: 8px;
    min-width: 0;
}
.k-tablefield__scroll {
    overflow-x: auto;
    border: 1px solid var(--k-rule);
    border-radius: var(--k-radius);
}
.k-tablefield__grid {
    border-collapse: collapse;
    width: 100%;
}
.k-tablefield__grid td {
    padding: 0;
    border-top: 1px solid var(--k-rule-soft);
}
.k-tablefield__grid thead td {
    border-top: 0;
}
.k-tablefield__grid td + td {
    border-left: 1px solid var(--k-rule-soft);
}
.k-tablefield__cell {
    min-width: 64px;
    width: 100%;
    height: 30px;
    border: 0;
    border-radius: 0;
    background: transparent;
    font-size: 12.5px;
}
.k-tablefield__cell:focus {
    position: relative;
    z-index: 1;
    box-shadow: inset 0 0 0 2px var(--k-focus);
}
.k-tablefield__grid tr.is-head .k-tablefield__cell {
    font-weight: 600;
    background: var(--k-sheet-2);
}
.k-tablefield__coltools {
    height: 20px;
    text-align: center;
    background: var(--k-sheet-2);
}
.k-tablefield__rowtools {
    width: 1%;
    white-space: nowrap;
    padding-inline: 2px !important;
    background: var(--k-sheet-2);
}
.k-tablefield__grid td.k-tablefield__coltools + td.k-tablefield__coltools,
.k-tablefield__grid td + td.k-tablefield__rowtools {
    border-left-color: var(--k-rule-soft);
}
.k-tablefield__tool {
    width: 20px;
    height: 20px;
    padding: 0;
    border: 0;
    border-radius: 4px;
    background: none;
    color: var(--k-ink-2);
    font: inherit;
    font-size: 13px;
    line-height: 1;
    cursor: pointer;
}
.k-tablefield__tool:hover:not(:disabled) {
    background: var(--k-rule-soft);
    color: var(--k-ink);
}
.k-tablefield__tool:disabled {
    opacity: 0.35;
    cursor: default;
}
.k-tablefield__add {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px;
}
</style>
