<script setup>
import { KContentField } from '@kestello/ui';
const model = defineModel({ default: null });
defineProps({
    field: { type: Object, required: true },
    id: { type: String, default: '' },
    invalid: Boolean,
    disabled: Boolean,
    errors: { type: Object, default: () => ({}) },
    path: { type: String, default: '' },
});

function set(name, value) {
    model.value = { ...(model.value ?? {}), [name]: value };
}
</script>

<template>
    <div :id="id" class="k-fields k-group-field">
        <KContentField
            v-for="child in field.fields"
            :key="child.name"
            :model-value="model?.[child.name] ?? null"
            :field="child"
            :errors="errors"
            :path="path"
            :disabled="disabled"
            @update:model-value="set(child.name, $event)"
        />
    </div>
</template>
