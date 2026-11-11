<script setup>
import { KImage } from '@kestello/app';
import { useI18n } from '@kestello/sdk';

/**
 * A photograph mounted for the wall: a white margin and a soft hang shadow.
 * Without a photograph the mount stays, with a note, so a room keeps its
 * shape while an editor adds one.
 */
defineProps({
    media: { type: Object, default: null },
    size: { type: String, default: 'large' },
    sizes: { type: String, default: '100vw' },
    alt: { type: String, default: null },
    priority: Boolean,
});
const { __ } = useI18n('exhibit');
</script>

<template>
    <figure v-if="media?.url" class="print">
        <KImage :media="media" :size="size" :sizes="sizes" :alt="alt" :priority="priority" />
    </figure>
    <figure v-else class="print print--blank">
        <span>{{ __('No photograph yet') }}</span>
    </figure>
</template>
