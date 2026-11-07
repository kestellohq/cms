<script setup>
import { computed } from 'vue';
import { formatDateTime, formatRelative } from '@kestello/sdk';

const props = defineProps({ value: { type: [Number, String, null], default: null } }); // unix seconds or an ISO 8601 string
const seconds = computed(() => {
    if (props.value === null || props.value === '' || props.value === undefined) return null;
    const n = typeof props.value === 'number' ? props.value : Date.parse(props.value) / 1000;
    return Number.isFinite(n) && n > 0 ? n : null;
});
const iso = computed(() => (seconds.value ? new Date(seconds.value * 1000).toISOString() : undefined));
</script>

<template>
    <time v-if="seconds" :datetime="iso" :title="formatDateTime(seconds)">{{ formatRelative(seconds) }}</time>
    <span v-else class="k-muted">—</span>
</template>
