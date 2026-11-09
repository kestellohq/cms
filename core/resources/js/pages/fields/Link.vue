<script setup>
import { useApi } from '@kestello/sdk';
import { KLinkInput, linkOptions } from '@kestello/ui';

/** A link field: an address, or a published page found by its title. */
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
const search = async (q) => linkOptions((await api.get('/content/lookup', { linkable: 1, search: q, limit: 8 })).data);
</script>

<template>
    <KLinkInput
        :id="id"
        :model-value="model ?? ''"
        :search="search"
        :placeholder="props.field.options.placeholder"
        :invalid="invalid"
        :disabled="disabled"
        @update:model-value="model = $event === '' ? null : $event"
    />
</template>
