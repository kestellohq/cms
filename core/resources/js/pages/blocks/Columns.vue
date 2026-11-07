<script setup>
import { computed } from 'vue';

// core/columns: core/column children side by side; they stack below 40rem. Classes, not inline styles (strict CSP).
const props = defineProps({ layout: { type: String, default: '1-1' }, gap: { type: String, default: 'md' }, width: { type: String, default: 'content' } });
const layouts = ['1-1', '2-1', '1-2', '1-1-1', '1-1-1-1'];
const layout = computed(() => (layouts.includes(props.layout) ? props.layout : '1-1'));
</script>

<template>
    <div class="k-b-columns" :class="[`k-b-columns--${gap}`, `k-b-columns--l${layout}`]" :data-width="width"><slot /></div>
</template>

<style scoped>
.k-b-columns {
    display: grid;
    gap: 1.5rem;
}
.k-b-columns--sm {
    gap: 1rem;
}
.k-b-columns--lg {
    gap: clamp(1.5rem, 4vw, 3rem);
}
@media (min-width: 40rem) {
    .k-b-columns--l1-1 {
        grid-template-columns: 1fr 1fr;
    }
    .k-b-columns--l2-1 {
        grid-template-columns: 2fr 1fr;
    }
    .k-b-columns--l1-2 {
        grid-template-columns: 1fr 2fr;
    }
    .k-b-columns--l1-1-1 {
        grid-template-columns: repeat(3, 1fr);
    }
    .k-b-columns--l1-1-1-1 {
        grid-template-columns: repeat(2, 1fr);
    }
}
@media (min-width: 60rem) {
    .k-b-columns--l1-1-1-1 {
        grid-template-columns: repeat(4, 1fr);
    }
}
</style>
