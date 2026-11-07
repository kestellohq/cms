<script setup>
import { computed } from 'vue';
import { useReplacement } from '@kestello/sdk';
import { KExtension, KRichText } from '@kestello/ui';

const model = defineModel({ default: null });
defineProps({
    field: { type: Object, required: true },
    id: { type: String, default: '' },
    invalid: Boolean,
    disabled: Boolean,
    errors: { type: Object, default: () => ({}) },
    path: { type: String, default: '' },
});
// A plugin can provide the editor ($cms->admin->replace('editor.richtext', …)); it gets the same props.
const replacement = useReplacement('editor.richtext');
const value = computed({
    get: () => model.value ?? '',
    set: (v) => {
        model.value = v;
    },
});
</script>

<template>
    <KExtension v-if="replacement" v-model="value" :name="replacement" :id="id" :field="field" :invalid="invalid" :disabled="disabled" />
    <KRichText v-else :id="id" v-model="value" :label="field.label" :invalid="invalid" :disabled="disabled" />
</template>
