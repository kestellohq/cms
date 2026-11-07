<script setup>
import KIcon from './KIcon.vue';

/** A fact cell: label, value and a one-line hint. `coord` adds a grid reference (A1). */
defineProps({
    label: { type: String, required: true },
    value: { type: [String, Number], default: '—' },
    hint: { type: String, default: '' },
    icon: { type: String, default: '' },
    tone: { type: String, default: 'neutral' },
    coord: { type: String, default: '' },
});
</script>

<template>
    <div class="k-stat" :class="`k-stat--${tone}`">
        <div class="k-stat__label">
            <span><KIcon v-if="icon" :name="icon" /> {{ label }}</span
            ><span v-if="coord" class="k-card__coord" aria-hidden="true">{{ coord }}</span>
        </div>
        <div class="k-stat__value">{{ value }}</div>
        <div v-if="hint || $slots.default" class="k-stat__hint">
            <slot>{{ hint }}</slot>
        </div>
    </div>
</template>
