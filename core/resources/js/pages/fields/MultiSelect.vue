<script setup>
const model = defineModel({ default: null });
const props = defineProps({
    field: { type: Object, required: true },
    id: { type: String, default: '' },
    invalid: Boolean,
    disabled: Boolean,
    errors: { type: Object, default: () => ({}) },
    path: { type: String, default: '' },
});

function toggle(value, on) {
    const list = new Set(model.value ?? []);
    on ? list.add(value) : list.delete(value);
    // Keep the declared order, not the click order.
    model.value = props.field.options.choices.map((c) => c.value).filter((v) => list.has(v));
}
</script>

<template>
    <div :id="id" class="k-choices" :class="{ 'k-choices--inline': field.options.choices.length <= 4 }">
        <label v-for="c in field.options.choices" :key="c.value" class="k-choice">
            <input type="checkbox" :checked="(model ?? []).includes(c.value)" :disabled="disabled" @change="toggle(c.value, $event.target.checked)" />
            <span>{{ c.label }}</span>
        </label>
    </div>
</template>
