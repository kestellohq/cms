<script setup>
import { useApi } from '@kestello/sdk';
import { KPicker } from '@kestello/ui';

const model = defineModel({ default: null });
defineProps({
    field: { type: Object, required: true },
    id: { type: String, default: '' },
    invalid: Boolean,
    disabled: Boolean,
    errors: { type: Object, default: () => ({}) },
    path: { type: String, default: '' },
});
const api = useApi();
const item = (u) => ({ id: u.id, label: u.username });
const search = async (q) => (await api.get('/content/users', { search: q || undefined })).data.map(item);
const resolve = async (ids) => (await api.get('/content/users', { ids: ids.join(',') })).data.map(item);
</script>

<template>
    <KPicker :id="id" v-model="model" :multiple="field.options.multiple" :search="search" :resolve="resolve" :disabled="disabled" :invalid="invalid" />
</template>
