<script setup>
import { computed } from 'vue';
const model = defineModel({ default: null });
const props = defineProps({
    field: { type: Object, required: true },
    id: { type: String, default: '' },
    invalid: Boolean,
    disabled: Boolean,
    errors: { type: Object, default: () => ({}) },
    path: { type: String, default: '' },
});

// Datetimes are stored in UTC (2027-03-01T08:00:00Z) and edited in the browser's time zone.
const pad = (n) => String(n).padStart(2, '0');
const value = computed({
    get() {
        if (!model.value) return '';
        if (!props.field.options.time) return String(model.value).slice(0, 10);
        const d = new Date(model.value);
        return Number.isNaN(d.getTime()) ? '' : `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
    },
    set(v) {
        if (!v) {
            model.value = null;
            return;
        }
        model.value = props.field.options.time ? new Date(v).toISOString().replace(/\.\d{3}Z$/, 'Z') : v;
    },
});
const zone = computed(() => (props.field.options.time ? Intl.DateTimeFormat().resolvedOptions().timeZone : ''));
</script>

<template>
    <div class="k-date-field">
        <input
            :id="id"
            v-model="value"
            :type="field.options.time ? 'datetime-local' : 'date'"
            class="k-input"
            :disabled="disabled"
            :aria-invalid="invalid ? 'true' : undefined"
        />
        <span v-if="zone" class="k-muted k-small">{{ zone }}</span>
    </div>
</template>
