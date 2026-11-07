<script setup>
// One entry inside core/query: the blocks in it read that entry.
defineProps({ url: { type: String, default: '' } });
</script>

<template>
    <li class="k-b-query-item k-b-stack"><slot /></li>
</template>

<style scoped>
.k-b-query-item {
    min-width: 0;
}
.k-b-query-item > :deep(* + *) {
    margin-top: 0.5rem;
}
</style>
