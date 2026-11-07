<script setup>
import { computed } from 'vue';
import KBadge from './KBadge.vue';

/** Site Health / task statuses → badge tone and label. */
const props = defineProps({ status: { type: String, default: '' } });
const MAP = {
    good: ['success', 'Good'],
    ok: ['success', 'OK'],
    done: ['success', 'Done'],
    sent: ['success', 'Sent'],
    recommended: ['warning', 'Recommended'],
    skipped: ['neutral', 'Skipped'],
    running: ['info', 'Running'],
    disabled: ['neutral', 'Disabled'],
    critical: ['danger', 'Critical'],
    failed: ['danger', 'Failed'],
};
const entry = computed(() => MAP[props.status] ?? ['neutral', props.status || '—']);
</script>

<template>
    <KBadge :tone="entry[0]" dot
        ><slot>{{ entry[1] }}</slot></KBadge
    >
</template>
