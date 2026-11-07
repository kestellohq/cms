<script setup>
import { __ } from '@kestello/sdk';
import { nextTick, onBeforeUnmount, ref, useId, watch } from 'vue';
import KIcon from './KIcon.vue';

const open = defineModel('open', { type: Boolean, default: false });
const props = defineProps({ title: { type: String, required: true }, size: { type: String, default: 'md' }, dismissible: { type: Boolean, default: true } });
const emit = defineEmits(['close']);
const panel = ref(null);
const titleId = `k-modal-${useId()}`;
let previous = null;

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

function close() {
    if (!props.dismissible) return;
    open.value = false;
    emit('close');
}

function onKeydown(event) {
    if (event.key === 'Escape') {
        event.stopPropagation();
        close();
        return;
    }
    if (event.key !== 'Tab' || !panel.value) return;
    const items = [...panel.value.querySelectorAll(FOCUSABLE)];
    if (items.length === 0) return;
    const first = items[0];
    const last = items[items.length - 1];
    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
}

watch(
    open,
    async (value) => {
        if (value) {
            previous = document.activeElement;
            document.body.classList.add('k-modal-open');
            await nextTick();
            // what the dialog asks for first; a dialog without fields (a confirmation) starts on its close button
            const field = panel.value?.querySelector(
                '.k-modal__body :is(input:not([type=hidden]):not(:disabled), select:not(:disabled), textarea:not(:disabled))',
            );
            (panel.value?.querySelector('[autofocus]') ?? field ?? panel.value?.querySelector(FOCUSABLE) ?? panel.value)?.focus();
        } else {
            document.body.classList.remove('k-modal-open');
            previous?.focus?.();
        }
    },
    { immediate: true },
);

onBeforeUnmount(() => document.body.classList.remove('k-modal-open'));
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="k-modal" @keydown="onKeydown">
            <div class="k-modal__backdrop" @click="close"></div>
            <div ref="panel" class="k-modal__panel" :class="`k-modal__panel--${size}`" role="dialog" aria-modal="true" :aria-labelledby="titleId" tabindex="-1">
                <header class="k-modal__header">
                    <h2 :id="titleId" class="k-modal__title">{{ title }}</h2>
                    <button v-if="dismissible" type="button" class="k-modal__close" :aria-label="__('Close')" @click="close"><KIcon name="x" /></button>
                </header>
                <div class="k-modal__body"><slot /></div>
                <footer v-if="$slots.footer" class="k-modal__footer"><slot name="footer" /></footer>
            </div>
        </div>
    </Teleport>
</template>
