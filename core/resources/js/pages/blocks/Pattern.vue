<script setup>
// core/pattern: a synced pattern; the server puts its blocks in as children.
defineProps({ pattern: { type: String, default: '' } });
</script>

<template>
    <div class="k-b-pattern k-blocks" data-width="full" :data-pattern="pattern"><slot /></div>
</template>
