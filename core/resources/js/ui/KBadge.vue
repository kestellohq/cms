<script setup>
defineProps({ tone: { type: String, default: 'neutral' }, dot: Boolean }); // neutral | success | warning | danger | info | accent | scheduled
</script>

<template>
    <span class="k-badge" :class="`k-badge--${tone}`"><span v-if="dot" class="k-badge__dot" aria-hidden="true"></span><slot /></span>
</template>
