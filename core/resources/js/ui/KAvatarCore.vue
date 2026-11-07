<script setup>
import { computed } from 'vue';

/** The core avatar: a square stamp with the person's initial. */
const props = defineProps({
    user: { type: Object, default: null }, // { username, email, … }
    size: { type: Number, default: 30 },
});
const initial = computed(() => (props.user?.username ?? '?').slice(0, 1).toUpperCase());
</script>

<template>
    <span
        class="k-avatar"
        :style="size !== 30 ? { width: `${size}px`, height: `${size}px`, fontSize: `${Math.round(size * 0.44)}px` } : null"
        aria-hidden="true"
        >{{ initial }}</span
    >
</template>
