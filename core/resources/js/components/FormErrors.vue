<script setup>
// Errors that belong to no field on the form (too many attempts, an expired form).
// Field errors show under their own field.
import { computed } from 'vue';
import { KAlert } from '@kestello/ui';

const props = defineProps({
    errors: { type: Object, default: () => ({}) },
    fields: { type: Array, default: () => [] }, // keys shown by the form's own fields
});
const messages = computed(() =>
    Object.entries(props.errors)
        .filter(([key, msg]) => msg && !props.fields.includes(key))
        .map(([, msg]) => msg),
);
</script>

<template>
    <KAlert v-if="messages.length" tone="danger">
        <div v-for="(msg, i) in messages" :key="i">{{ msg }}</div>
    </KAlert>
</template>
