<script setup>
import { __ } from '@kestello/sdk';
import KIcon from './KIcon.vue';

const props = defineProps({ tone: { type: String, default: 'info' }, title: { type: String, default: '' }, dismissible: Boolean });
const emit = defineEmits(['dismiss']);
const ICONS = { info: 'info', success: 'check-circle', warning: 'warning', danger: 'warning-octagon' };
</script>

<template>
    <div class="k-alert" :class="`k-alert--${tone}`" :role="tone === 'danger' ? 'alert' : 'status'">
        <KIcon :name="ICONS[props.tone] ?? 'info'" />
        <div class="k-alert__body">
            <strong v-if="title" class="k-alert__title">{{ title }}</strong>
            <div><slot /></div>
        </div>
        <button v-if="dismissible" type="button" class="k-alert__close" :aria-label="__('Dismiss')" @click="emit('dismiss')"><KIcon name="x" /></button>
    </div>
</template>
