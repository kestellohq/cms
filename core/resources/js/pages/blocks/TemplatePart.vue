<script setup>
// core/template-part: the server puts the part's blocks in as children.
defineProps({ part: { type: String, default: '' }, area: { type: String, default: 'general' } });
const tags = { header: 'header', footer: 'footer', sidebar: 'aside' };
</script>

<template>
    <component :is="tags[area] ?? 'div'" class="k-b-part k-blocks" :class="`k-b-part--${area}`" data-width="full" :data-part="part"><slot /></component>
</template>
