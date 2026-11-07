<script setup>
import { computed, useId } from 'vue';

const props = defineProps({
    label: { type: String, required: true },
    help: { type: String, default: '' },
    error: { type: String, default: '' },
    required: Boolean,
    id: { type: String, default: '' },
});
const auto = useId();
const fieldId = computed(() => props.id || `k-field-${auto}`);
const describedBy = computed(
    () => [props.help ? `${fieldId.value}-help` : '', props.error ? `${fieldId.value}-error` : ''].filter(Boolean).join(' ') || undefined,
);
</script>

<template>
    <div class="k-field" :class="{ 'has-error': error }">
        <label class="k-field__label" :for="fieldId">{{ label }}<span v-if="required" class="k-field__required" aria-hidden="true"> *</span></label>
        <slot :id="fieldId" :described-by="describedBy" :invalid="!!error" />
        <p v-if="help" :id="`${fieldId}-help`" class="k-field__help">{{ help }}</p>
        <p v-if="error" :id="`${fieldId}-error`" class="k-field__error">{{ error }}</p>
    </div>
</template>
