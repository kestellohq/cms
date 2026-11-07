<script setup>
import { useApi, __ } from '@kestello/sdk';
import { KPicker } from '@kestello/ui';

const model = defineModel({ default: null });
const props = defineProps({
    field: { type: Object, required: true },
    id: { type: String, default: '' },
    invalid: Boolean,
    disabled: Boolean,
    errors: { type: Object, default: () => ({}) },
    path: { type: String, default: '' },
});
const api = useApi();
const types = (props.field.options.types ?? []).join(',') || undefined;
const STATUS = { draft: __('Draft'), pending: __('In review'), scheduled: __('Scheduled'), private: __('Private'), published: '' };
const item = (e) => ({ id: e.id, label: e.title || __('(untitled)'), meta: [e.type_label, STATUS[e.status]].filter(Boolean).join(' · ') });

const search = async (q) => (await api.get('/content/lookup', { search: q || undefined, types })).data.map(item);
const resolve = async (ids) => (await api.get('/content/lookup', { ids: ids.join(','), types })).data.map(item);
</script>

<template>
    <KPicker :id="id" v-model="model" :multiple="field.options.multiple" :search="search" :resolve="resolve" :disabled="disabled" :invalid="invalid" />
</template>
