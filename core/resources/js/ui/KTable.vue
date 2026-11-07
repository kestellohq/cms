<script setup>
import { __ } from '@kestello/sdk';
import KEmpty from './KEmpty.vue';
import KSpinner from './KSpinner.vue';

/**
 * <KTable :columns="[{ key: 'name', label: 'Task' }, { key: 'status', label: 'Status', align: 'end' }]" :rows="rows" row-key="id">
 *   <template #cell-status="{ row }"><KStatusBadge :status="row.status" /></template>
 * </KTable>
 *
 * Timetable extras: `row-class` returns classes per row (is-scheduled, is-faded),
 * and `marker` draws a labelled rule before one row, e.g. the now-line:
 *   :marker="{ before: 2, label: 'Now 14:52' }"
 */
defineProps({
    columns: { type: Array, required: true },
    rows: { type: Array, default: () => [] },
    rowKey: { type: String, default: 'id' },
    loading: Boolean,
    empty: { type: String, default: () => __('Nothing here yet.') }, // a short title: "No forms yet"
    emptyText: { type: String, default: '' }, // under it, what to do next

    caption: { type: String, default: '' },
    rowClass: { type: Function, default: null },
    marker: { type: Object, default: null },
});
</script>

<template>
    <div class="k-table-wrap">
        <table class="k-table">
            <caption v-if="caption" class="k-sr-only">
                {{
                    caption
                }}
            </caption>
            <thead>
                <tr>
                    <th
                        v-for="col in columns"
                        :key="col.key"
                        scope="col"
                        :class="col.align ? `is-${col.align}` : ''"
                        :style="col.width ? { width: col.width } : null"
                    >
                        {{ col.label }}
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr v-if="loading && !rows.length">
                    <td :colspan="columns.length" class="k-table__state"><KSpinner /></td>
                </tr>
                <tr v-else-if="!rows.length">
                    <td :colspan="columns.length" class="k-table__state">
                        <KEmpty icon="tray" :title="empty"
                            ><p v-if="emptyText">{{ emptyText }}</p></KEmpty
                        >
                    </td>
                </tr>
                <template v-for="(row, index) in rows" v-else :key="row[rowKey] ?? index">
                    <tr v-if="marker && marker.before === index" class="k-now">
                        <td :colspan="columns.length">
                            <span class="k-now__label">{{ marker.label }}</span>
                        </td>
                    </tr>
                    <tr :class="rowClass ? rowClass(row) : null">
                        <td v-for="col in columns" :key="col.key" :class="[col.align ? `is-${col.align}` : '', col.class ?? '']">
                            <slot :name="`cell-${col.key}`" :row="row" :value="row[col.key]">{{ row[col.key] ?? '—' }}</slot>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>
</template>
