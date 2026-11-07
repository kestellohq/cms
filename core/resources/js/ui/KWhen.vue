<script setup>
import { computed } from 'vue';
import { formatDateTime, useAdmin } from '@kestello/sdk';

/**
 * A timetable time: the clock time large, the day under it.
 * "14:20 / Today", "09:00 / Mon 9 Nov", "08:30 / 30 Oct 2025".
 */
const props = defineProps({ value: { type: [Number, String, null], default: null } }); // unix seconds or ISO 8601
const admin = useAdmin();
const date = computed(() => {
    if (props.value === null || props.value === '' || props.value === undefined) return null;
    const n = typeof props.value === 'number' ? props.value * 1000 : Date.parse(props.value);
    return Number.isFinite(n) && n > 0 ? new Date(n) : null;
});
const locale = computed(() => admin.value?.locale || undefined);
const time = computed(() => date.value && new Intl.DateTimeFormat(locale.value, { hour: '2-digit', minute: '2-digit' }).format(date.value));
const day = computed(() => {
    const d = date.value;
    if (!d) return '';
    const now = new Date();
    const start = (x) => new Date(x.getFullYear(), x.getMonth(), x.getDate()).getTime();
    const diff = Math.round((start(d) - start(now)) / 86400000);
    const rel = new Intl.RelativeTimeFormat(locale.value, { numeric: 'auto' });
    if (diff === 0 || diff === -1 || diff === 1) return rel.format(diff, 'day').replace(/^\w/, (c) => c.toUpperCase());
    const opts =
        Math.abs(diff) < 7
            ? { weekday: 'short', day: 'numeric', month: 'short' }
            : d.getFullYear() === now.getFullYear()
              ? { day: 'numeric', month: 'short' }
              : { day: 'numeric', month: 'short', year: 'numeric' };
    return new Intl.DateTimeFormat(locale.value, opts).format(d);
});
</script>

<template>
    <time v-if="date" class="k-when__stamp" :datetime="date.toISOString()" :title="formatDateTime(date.getTime() / 1000)">
        <span class="k-when__time">{{ time }}</span
        ><span class="k-when__day">{{ day }}</span>
    </time>
    <span v-else class="k-muted">—</span>
</template>
