<script setup>
import { computed } from 'vue';
import { useCanvas } from '@kestello/app';

const props = defineProps({ text: { type: String, default: '' }, level: { type: [String, Number], default: '2' }, align: { type: String, default: 'start' } });
const canvas = useCanvas();
const tag = computed(() => `h${Math.min(6, Math.max(1, Number(props.level) || 2))}`);
</script>

<template>
    <component :is="tag" v-if="text || canvas" :class="['k-b-heading', `k-b-align--${align}`]" data-k-field="text" data-placeholder="Heading">{{
        text
    }}</component>
</template>

<style scoped>
:where(.k-b-heading) {
    margin-block: 0;
} /* no weight: the spacing between blocks (.k-b-stack > * + *) wins */
.k-b-heading {
    line-height: 1.15;
    letter-spacing: -0.015em;
    text-wrap: balance;
}
h1.k-b-heading {
    font-size: clamp(2rem, 5vw, 3rem);
}
h2.k-b-heading {
    font-size: clamp(1.5rem, 3.4vw, 2rem);
}
h3.k-b-heading {
    font-size: 1.3rem;
}
h4.k-b-heading,
h5.k-b-heading,
h6.k-b-heading {
    font-size: 1.075rem;
}
.k-b-align--center {
    text-align: center;
}
.k-b-align--end {
    text-align: end;
}
</style>
