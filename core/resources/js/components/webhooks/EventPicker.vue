<script setup>
import { computed } from 'vue';
import { __ } from '@kestello/sdk';

/** Checkboxes for the events a webhook sends, grouped as core and plugins declare them. */
const props = defineProps({
    events: { type: Array, default: () => [] }, // [{ name, label, group, description }]
    error: { type: String, default: '' },
});
const model = defineModel({ type: Array, default: () => [] });

const groups = computed(() => {
    const out = new Map();
    for (const e of props.events) {
        if (e.name === 'webhook.ping') continue; // sent by "Send a test" only
        const g = e.group || __('Other');
        if (!out.has(g)) out.set(g, []);
        out.get(g).push(e);
    }
    return [...out.entries()];
});
const all = computed(() => model.value.includes('*'));

function toggle(name, on) {
    const next = new Set(model.value.filter((n) => n !== '*'));
    if (on) next.add(name);
    else next.delete(name);
    model.value = [...next];
}
function toggleAll(on) {
    model.value = on ? ['*'] : [];
}
</script>

<template>
    <fieldset class="k-fieldset k-hook-events">
        <legend class="k-field__label">{{ __('Events') }}</legend>
        <p v-if="error" class="k-field__error">{{ error }}</p>
        <label class="k-option">
            <input type="checkbox" :checked="all" @change="toggleAll($event.target.checked)" />
            <span
                ><strong>{{ __('Everything') }}</strong> <span class="k-muted">{{ __('including events added later by plugins') }}</span></span
            >
        </label>
        <div v-for="[group, items] in groups" :key="group" class="k-hook-events__group">
            <p class="k-hook-events__name">{{ group }}</p>
            <label v-for="e in items" :key="e.name" class="k-option">
                <input type="checkbox" :checked="all || model.includes(e.name)" :disabled="all" @change="toggle(e.name, $event.target.checked)" />
                <span
                    >{{ e.label }} <code class="k-small">{{ e.name }}</code></span
                >
            </label>
        </div>
    </fieldset>
</template>

<style scoped>
.k-hook-events__group {
    margin-top: 10px;
}
.k-hook-events__name {
    margin: 0 0 4px;
    font-size: 13px;
    font-weight: 600;
    color: var(--k-ink-2);
}
.k-hook-events code {
    margin-left: 4px;
    color: var(--k-ink-3);
}
</style>
