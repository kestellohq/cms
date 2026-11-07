<script setup>
// core/entry-content: the body of the entry being viewed. Blocks come as children;
// rich text arrives sanitized (it was cleaned with the rich-text profile when saved).
defineProps({ field: { type: String, default: '' }, kind: { type: String, default: 'blocks' }, html: { type: String, default: '' } });
</script>

<template>
    <div v-if="kind === 'html'" class="k-b-entry-content"><div class="prose" v-html="html"></div></div>
    <div v-else class="k-b-entry-content k-b-stack"><slot /></div>
</template>
