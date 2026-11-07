<script>
// A template that brings its own header and footer (template parts): no theme layout around it.
export default { layout: null };
</script>

<script setup>
// the column, spacing, screen sizes and block design: shared by every page that draws blocks
import '../../shared/block-layout.css';
import { BlockTree, Head } from '@kestello/app';

defineProps({
    entry: { type: Object, required: true },
    template: { type: Object, required: true },
    design: { type: Object, default: null }, // { preview: true }: a pattern shown on its own (the pattern browser)
});
</script>

<template>
    <Head :title="entry.title" />
    <div class="k-template k-template--bare k-blocks" :class="{ 'is-preview': design?.preview }" :data-template="template.key">
        <BlockTree :blocks="template.blocks" />
    </div>
</template>

<style>
/* a pattern on its own gets room around it, as it would have between other blocks */
.k-template.is-preview {
    padding-block: 2.5rem;
}
</style>
