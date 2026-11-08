<script setup>
import { computed, onBeforeUnmount, ref } from 'vue';
import { __ } from '@kestello/sdk';
import { KAlert } from '@kestello/ui';
import { minutesUntil } from './demo.js';

/**
 * The admin's demo bar: this is a demo, what it shares with other
 * visitors, when it resets, and what is locked.
 */
const props = defineProps({
    /** The "demo" page prop: { next_reset, every }. */
    demo: { type: Object, required: true },
});

const now = ref(Date.now());
const timer = setInterval(() => (now.value = Date.now()), 30_000);
onBeforeUnmount(() => clearInterval(timer));

const minutes = computed(() => minutesUntil(props.demo.next_reset, now.value));
const when = computed(() =>
    minutes.value === null
        ? __('Everything goes back to how it was shortly.')
        : minutes.value === 1
          ? __('Everything goes back to how it was in about a minute.')
          : __('Everything goes back to how it was in {n} minutes.', { n: minutes.value }),
);
</script>

<template>
    <KAlert tone="info" :title="__('This is a demo')">
        <p>
            {{ when }}
            {{ __('Anyone can sign in here, so others may see what you change, and you may see theirs. Nothing is emailed.') }}
        </p>
        <p class="k-small">
            {{ __('Content, design, menus, forms and images can be changed. Your account, people, plugins, updates, backups and settings are locked.') }}
        </p>
    </KAlert>
</template>
