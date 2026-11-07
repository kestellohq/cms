<script setup>
import { nextTick, provide, ref } from 'vue';
import { panelId, tabFromKey, tabId, TABS } from '../../shared/tabs.js';

// core/tabs: one core/tab panel shown at a time. The server gives the tab names (tabs) and an id
// (group) when it prepares the block (CoreSectionBlocks::tabs). Arrow keys, Home and End move
// between tabs; the panel follows the tab focused (the ARIA tabs pattern).
const props = defineProps({
    tabs: { type: Array, default: () => [] },
    group: { type: String, default: '' },
    look: { type: String, default: 'line' },
    align: { type: String, default: 'start' },
    width: { type: String, default: 'content' },
});
const active = ref(0);
const list = ref(null);
provide(TABS, {
    active,
    group: () => props.group,
    show: (i) => {
        active.value = i;
    },
});

function onKey(event) {
    const next = tabFromKey(event.key, active.value, props.tabs.length);
    if (next === null) return;
    event.preventDefault();
    active.value = next;
    nextTick(() => list.value?.querySelector(`#${tabId(props.group, next)}`)?.focus());
}
</script>

<template>
    <div v-if="tabs.length" class="k-b-tabs" :class="[`is-${look === 'pills' ? 'pills' : 'line'}`, align === 'center' && 'is-center']" :data-width="width">
        <div ref="list" class="k-b-tabs__list" role="tablist" @keydown="onKey">
            <button
                v-for="(label, i) in tabs"
                :id="tabId(group, i)"
                :key="i"
                type="button"
                role="tab"
                class="k-b-tabs__tab"
                :aria-selected="String(active === i)"
                :aria-controls="panelId(group, i)"
                :tabindex="active === i ? 0 : -1"
                @click="active = i"
            >
                {{ label }}
            </button>
        </div>
        <slot />
    </div>
</template>

<style scoped>
:where(.k-b-tabs) {
    margin-block: 0;
}
.k-b-tabs__list {
    display: flex;
    flex-wrap: wrap;
    gap: 0.25rem;
    margin-bottom: 1.25rem;
}
.k-b-tabs.is-center .k-b-tabs__list {
    justify-content: center;
}
.k-b-tabs__tab {
    padding: 0.6rem 1rem;
    border: 0;
    background: none;
    color: inherit;
    font: inherit;
    font-weight: 600;
    opacity: 0.7;
    cursor: pointer;
}
.k-b-tabs__tab:hover {
    opacity: 1;
}
.k-b-tabs__tab[aria-selected='true'] {
    opacity: 1;
}
.k-b-tabs__tab:focus-visible {
    outline: 2px solid var(--block-accent, #2448c9);
    outline-offset: 2px;
}
.k-b-tabs.is-line .k-b-tabs__list {
    border-bottom: 1px solid color-mix(in srgb, currentColor 18%, transparent);
}
.k-b-tabs.is-line .k-b-tabs__tab {
    margin-bottom: -1px;
    border-bottom: 2px solid transparent;
}
.k-b-tabs.is-line .k-b-tabs__tab[aria-selected='true'] {
    border-bottom-color: var(--block-accent, #2448c9);
}
.k-b-tabs.is-pills .k-b-tabs__tab {
    border-radius: 999px;
}
.k-b-tabs.is-pills .k-b-tabs__tab[aria-selected='true'] {
    background: var(--block-accent, #2448c9);
    color: var(--block-accent-ink, #fff);
}
</style>
