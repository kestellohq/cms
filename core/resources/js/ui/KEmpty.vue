<script setup>
import KIcon from './KIcon.vue';

defineProps({ icon: { type: String, default: 'tray' }, title: { type: String, required: true } });
</script>

<template>
    <div class="k-empty">
        <KIcon :name="icon" />
        <p class="k-empty__title">{{ title }}</p>
        <div v-if="$slots.default" class="k-empty__body"><slot /></div>
    </div>
</template>
