<script setup>
import { useExtensions } from '@kestello/sdk';
import KExtension from './KExtension.vue';

/**
 * Every component plugins registered at a slot point, in order, each
 * receiving `context`. Renders nothing when the point is empty.
 *
 *   <KSlot point="users.edit.panels" :context="{ user }" />
 */
const props = defineProps({
    point: { type: String, required: true },
    context: { type: Object, default: () => ({}) },
});
const items = useExtensions('slots', props.point, () => props.context);
</script>

<template>
    <KExtension v-for="item in items" :key="item.id" :name="item.component" :owner="item.owner" :context="context" />
</template>
