<script setup>
import { computed } from 'vue';
import { KSwitch } from '@kestello/ui';
const model = defineModel({ default: null });
defineProps({
    field: { type: Object, required: true },
    id: { type: String, default: '' },
    invalid: Boolean,
    disabled: Boolean,
    errors: { type: Object, default: () => ({}) },
    path: { type: String, default: '' },
});
const value = computed({
    get: () => !!model.value,
    set: (v) => {
        model.value = v;
    },
});
</script>

<template>
    <KSwitch :id="id" v-model="value" :label="field.options.text ?? field.label" :hide-label="!field.options.text" :disabled="disabled" />
</template>
