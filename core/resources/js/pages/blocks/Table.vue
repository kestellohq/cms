<script setup>
import { computed } from 'vue';
import { useCanvas } from '@kestello/app';

// core/table: rows of plain-text cells (Fields\TableField). A wide table scrolls sideways
// inside its own box, which keyboard users can reach and scroll.
const props = defineProps({
    table: { type: Object, default: null },
    caption: { type: String, default: '' },
    striped: Boolean,
    side: Boolean,
    width: { type: String, default: 'content' },
});
const canvas = useCanvas();
const rows = computed(() => (Array.isArray(props.table?.rows) ? props.table.rows.filter(Array.isArray) : []));
const head = computed(() => (props.table?.head && rows.value.length ? rows.value[0] : null));
const body = computed(() => (head.value ? rows.value.slice(1) : rows.value));
// where a body cell is in the stored rows (a heading row is row 0), for typing on the canvas
const cellField = (r, c) => `table.rows.${r + (head.value ? 1 : 0)}.${c}`;
</script>

<template>
    <div v-if="rows.length" class="k-b-table" :class="striped && 'is-striped'" :data-width="width">
        <div class="k-b-table__scroll" role="region" :aria-label="caption || 'Table'" tabindex="0">
            <table>
                <caption v-if="caption || canvas" data-k-field="caption" data-placeholder="Caption">
                    {{
                        caption
                    }}
                </caption>
                <thead v-if="head">
                    <tr>
                        <th v-for="(cell, c) in head" :key="c" scope="col" :data-k-field="`table.rows.0.${c}`">{{ cell }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(row, r) in body" :key="r">
                        <template v-for="(cell, c) in row" :key="c">
                            <th v-if="side && c === 0" scope="row" :data-k-field="cellField(r, c)">{{ cell }}</th>
                            <td v-else :data-k-field="cellField(r, c)">{{ cell }}</td>
                        </template>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>

<style scoped>
:where(.k-b-table) {
    margin-block: 0;
}
.k-b-table__scroll {
    overflow-x: auto;
}
.k-b-table__scroll:focus-visible {
    outline: 2px solid var(--block-accent, #2448c9);
    outline-offset: 2px;
}
.k-b-table table {
    width: 100%;
    border-collapse: collapse;
    font-variant-numeric: tabular-nums;
}
.k-b-table caption {
    margin-bottom: 0.5rem;
    text-align: start;
    font-size: 0.875rem;
    opacity: 0.75;
}
.k-b-table th,
.k-b-table td {
    padding: 0.6rem 0.75rem;
    border-bottom: 1px solid color-mix(in srgb, currentColor 16%, transparent);
    text-align: start;
    vertical-align: top;
}
.k-b-table thead th {
    border-bottom: 2px solid color-mix(in srgb, currentColor 32%, transparent);
    font-weight: 650;
    white-space: nowrap;
}
.k-b-table tbody th {
    font-weight: 650;
}
.k-b-table.is-striped tbody tr:nth-child(odd) {
    background: color-mix(in srgb, currentColor 5%, transparent);
}
</style>
