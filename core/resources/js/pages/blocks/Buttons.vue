<script setup>
// core/buttons: core/button children side by side, wrapping onto the next line when there is no room.
defineProps({ align: { type: String, default: 'start' } });
</script>

<template>
    <div class="k-b-buttons" :class="`is-${['start', 'center', 'end', 'between'].includes(align) ? align : 'start'}`"><slot /></div>
</template>

<style scoped>
:where(.k-b-buttons) {
    margin-block: 0;
}
.k-b-buttons {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.75rem;
}
.k-b-buttons.is-center {
    justify-content: center;
}
.k-b-buttons.is-end {
    justify-content: flex-end;
}
.k-b-buttons.is-between {
    justify-content: space-between;
}
.k-b-buttons > :deep(*) {
    margin: 0;
}
</style>
