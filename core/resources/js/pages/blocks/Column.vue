<script setup>
defineProps({ align: { type: String, default: 'start' } });
</script>

<template>
    <div class="k-b-column k-b-stack" :class="`k-b-column--${['start', 'center', 'end'].includes(align) ? align : 'start'}`"><slot /></div>
</template>

<style scoped>
.k-b-column {
    min-width: 0;
}
.k-b-column--center {
    align-self: center;
}
.k-b-column--end {
    align-self: end;
}
</style>
