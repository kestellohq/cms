<script setup>
import { computed, ref, watch } from 'vue';
import { __ } from '@kestello/sdk';
import { KButton, KContentField } from '@kestello/ui';

const model = defineModel({ default: null });
const props = defineProps({
    field: { type: Object, required: true },
    id: { type: String, default: '' },
    invalid: Boolean,
    disabled: Boolean,
    errors: { type: Object, default: () => ({}) },
    path: { type: String, default: '' },
});

const rows = computed(() => (Array.isArray(model.value) ? model.value : []));
// Stable keys so inputs keep focus and state when rows move.
let next = 0;
const keys = ref(rows.value.map(() => next++));
watch(
    () => rows.value.length,
    (n) => {
        while (keys.value.length < n) keys.value.push(next++);
        if (keys.value.length > n) keys.value = keys.value.slice(0, n);
    },
);

const max = computed(() => props.field.options.max ?? null);
const canAdd = computed(() => !props.disabled && (max.value === null || rows.value.length < max.value));
const single = computed(() => props.field.options.singular ?? __('item'));

function add() {
    const blank = Object.fromEntries(props.field.fields.map((f) => [f.name, f.default ?? null]));
    keys.value.push(next++);
    model.value = [...rows.value, blank];
}
function remove(i) {
    keys.value.splice(i, 1);
    model.value = rows.value.filter((_, j) => j !== i);
}
function move(i, delta) {
    const j = i + delta;
    if (j < 0 || j >= rows.value.length) return;
    const list = [...rows.value];
    [list[i], list[j]] = [list[j], list[i]];
    const k = [...keys.value];
    [k[i], k[j]] = [k[j], k[i]];
    keys.value = k;
    model.value = list;
}
function set(i, name, value) {
    model.value = rows.value.map((row, j) => (j === i ? { ...row, [name]: value } : row));
}
const summary = (row) => {
    const first = props.field.fields.find((f) => ['text', 'email', 'url'].includes(f.type));
    return first ? String(row?.[first.name] ?? '').trim() : '';
};
</script>

<template>
    <div :id="id" class="k-repeater">
        <ol v-if="rows.length" class="k-repeater__rows">
            <li v-for="(row, i) in rows" :key="keys[i]" class="k-repeater__row">
                <div class="k-repeater__head">
                    <span class="k-repeater__index">{{ i + 1 }}</span>
                    <span class="k-repeater__summary">{{ summary(row) || __('Untitled {item}', { item: single }) }}</span>
                    <span v-if="!disabled" class="k-repeater__tools">
                        <KButton size="sm" variant="ghost" icon="arrow-up" :disabled="i === 0" :aria-label="__('Move up')" @click="move(i, -1)" />
                        <KButton
                            size="sm"
                            variant="ghost"
                            icon="arrow-down"
                            :disabled="i === rows.length - 1"
                            :aria-label="__('Move down')"
                            @click="move(i, 1)"
                        />
                        <KButton size="sm" variant="ghost" icon="x" :aria-label="__('Remove {item} {n}', { item: single, n: i + 1 })" @click="remove(i)" />
                    </span>
                </div>
                <div class="k-fields">
                    <KContentField
                        v-for="child in field.fields"
                        :key="child.name"
                        :model-value="row?.[child.name] ?? null"
                        :field="child"
                        :errors="errors"
                        :path="`${path}.${i}`"
                        :disabled="disabled"
                        @update:model-value="set(i, child.name, $event)"
                    />
                </div>
            </li>
        </ol>
        <p v-else class="k-muted k-small k-repeater__empty">{{ __('Nothing added yet.') }}</p>
        <div v-if="canAdd">
            <KButton size="sm" icon="plus" @click="add">{{ __('Add {item}', { item: single }) }}</KButton>
        </div>
    </div>
</template>
