<script>
// An entry shown through a theme template (Appearance → Templates), inside the theme's Site layout.
export default { layout: 'Site' };
</script>

<script setup>
// the column, spacing, screen sizes and block design: shared by every page that draws blocks
import '../../shared/block-layout.css';
import { BlockTree, Head } from '@kestello/app';

defineProps({
    entry: { type: Object, required: true },
    template: { type: Object, required: true }, // { key, blocks }: prepared by the server
});
</script>

<template>
    <Head :title="entry.title" />
    <div class="k-template k-blocks" :data-template="template.key">
        <BlockTree :blocks="template.blocks" />
    </div>
</template>
