<script setup>
import { useReplacement } from '@kestello/sdk';
import KAvatarCore from './KAvatarCore.vue';
import KExtension from './KExtension.vue';

/**
 * A person's avatar. Replaceable as "users.avatar": a plugin's component
 * receives the same props plus `core` (this core avatar) so it can wrap it,
 * for example to fall back to the initial when there is no picture.
 *
 *   <KAvatar :user="row" :size="24" />
 */
defineProps({
    user: { type: Object, default: null },
    size: { type: Number, default: 30 },
});
const replacement = useReplacement('users.avatar');
</script>

<template>
    <KExtension v-if="replacement" :name="replacement" inline :user="user" :size="size" :core="KAvatarCore" />
    <KAvatarCore v-else :user="user" :size="size" />
</template>
