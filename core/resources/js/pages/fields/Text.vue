<script setup>
import { computed } from 'vue';
import { __ } from '@kestello/sdk';

const model = defineModel({ default: null });
const props = defineProps({
    field: { type: Object, required: true },
    id: { type: String, default: '' },
    invalid: Boolean,
    disabled: Boolean,
    errors: { type: Object, default: () => ({}) },
    path: { type: String, default: '' },
});

// options.suggest: [{ value, label }] offers a list (the server still checks the name); a value no longer offered stays visible
const suggest = computed(() => {
    const list = Array.isArray(props.field.options.suggest) ? props.field.options.suggest : null;
    if (!list) return null;
    return model.value && !list.some((o) => o.value === model.value)
        ? [...list, { value: model.value, label: __('{name} (not found)', { name: model.value }) }]
        : list;
});
</script>

<template>
    <select v-if="suggest" :id="id" v-model="model" class="k-input k-select" :disabled="disabled" :aria-invalid="invalid ? 'true' : undefined">
        <option :value="null" disabled>{{ suggest.length ? __('Choose…') : __('None yet') }}</option>
        <option v-for="o in suggest" :key="o.value" :value="o.value">{{ o.label }}</option>
    </select>
    <input
        v-else
        :id="id"
        v-model="model"
        :type="field.options.input ?? 'text'"
        class="k-input"
        :maxlength="field.options.max"
        :placeholder="field.options.placeholder"
        :disabled="disabled"
        :aria-invalid="invalid ? 'true' : undefined"
        :autocomplete="field.options.input === 'email' ? 'email' : 'off'"
        :spellcheck="field.options.input === 'text' ? undefined : 'false'"
    />
</template>
