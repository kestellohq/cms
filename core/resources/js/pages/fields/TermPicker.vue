<script setup>
import { useApi } from '@kestello/sdk';
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
const base = `/taxonomies/${props.field.options.taxonomy}/terms`;
const item = (t) => ({ id: t.id, label: t.name });
const search = async (q) => (await api.get(base, { search: q || undefined, limit: 20 })).data.map(item);
const resolve = async (ids) => (await api.get(base, { ids: ids.join(',') })).data.map(item);
</script>

<template>
    <KPicker :id="id" v-model="model" :multiple="field.options.multiple" :search="search" :resolve="resolve" :disabled="disabled" :invalid="invalid" />
</template>
