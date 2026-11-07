<script setup>
import { computed, inject, onBeforeUnmount, onMounted, ref } from 'vue';
import { REVEAL } from '../../shared/canvas-protocol.js';
import { panelId, tabId, TABS } from '../../shared/tabs.js';

// core/tab: one panel of core/tabs, shown while its tab is. index and group come from the
// server (CoreSectionBlocks::tabs). In the editor, selecting a block inside it opens it.
const props = defineProps({ label: { type: String, default: '' }, index: { type: Number, default: 0 }, group: { type: String, default: '' } });
const tabs = inject(TABS, null);
const shown = computed(() => !tabs || tabs.active.value === props.index);
const el = ref(null);
const reveal = () => tabs?.show(props.index);
onMounted(() => el.value?.addEventListener(REVEAL, reveal));
onBeforeUnmount(() => el.value?.removeEventListener(REVEAL, reveal));
</script>

<template>
    <div :id="panelId(group, index)" ref="el" class="k-b-tab k-b-stack" role="tabpanel" :aria-labelledby="tabId(group, index)" tabindex="0" :hidden="!shown">
        <slot />
    </div>
</template>

<style scoped>
.k-b-tab:focus-visible {
    outline: 2px solid var(--block-accent, #2448c9);
    outline-offset: 4px;
}
</style>
