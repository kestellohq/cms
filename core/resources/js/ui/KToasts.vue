<script setup>
import { __, dismissToast, runToastAction, toasts } from '@kestello/sdk';
import KIcon from './KIcon.vue';

const ICONS = { success: 'check-circle', info: 'info', warning: 'warning', danger: 'warning-octagon' };
</script>

<template>
    <div class="k-toasts" aria-live="polite" aria-relevant="additions">
        <div v-for="toast in toasts" :key="toast.id" class="k-toast" :class="`k-toast--${toast.kind}`" :role="toast.kind === 'danger' ? 'alert' : 'status'">
            <KIcon :name="ICONS[toast.kind] ?? 'info'" />
            <span class="k-toast__message">{{ toast.message }}</span>
            <button v-if="toast.action" type="button" class="k-toast__action" @click="runToastAction(toast.id)">{{ toast.action.label }}</button>
            <button type="button" class="k-toast__close" :aria-label="__('Dismiss')" @click="dismissToast(toast.id)"><KIcon name="x" /></button>
        </div>
    </div>
</template>
