<script setup>
import { __ } from '@kestello/sdk';
const model = defineModel({ type: String, default: '' });
const props = defineProps({ tabs: { type: Array, required: true }, label: { type: String, default: () => __('Sections') } }); // [{ id, label, count }]

function move(event, index) {
    const delta = event.key === 'ArrowRight' ? 1 : event.key === 'ArrowLeft' ? -1 : 0;
    if (!delta) return;
    const next = (index + delta + props.tabs.length) % props.tabs.length;
    model.value = props.tabs[next].id;
    event.currentTarget.parentElement.children[next]?.focus();
}
</script>

<template>
    <div class="k-tabs" role="tablist" :aria-label="label">
        <button
            v-for="(tab, i) in tabs"
            :key="tab.id"
            type="button"
            role="tab"
            class="k-tabs__tab"
            :aria-selected="model === tab.id ? 'true' : 'false'"
            :tabindex="model === tab.id ? 0 : -1"
            @click="model = tab.id"
            @keydown="move($event, i)"
        >
            {{ tab.label }}<span v-if="tab.count !== undefined && tab.count !== null" class="k-tabs__count">{{ tab.count }}</span>
        </button>
    </div>
</template>
