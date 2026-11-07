<script setup>
import { computed } from 'vue';
import { choiceLabel } from './choices.js';
import { fileHint, fill } from './file-hint.js';

/**
 * One field of a site form, as visitors see it: label, input, help and error. Drawn by
 * the Form block (pages/blocks/Form.vue) and by the form builder's canvas in the admin,
 * so both look the same. The words around it ("Choose…", "Up to 5 MB.") come from the caller,
 * in its language: the site's on a page, the admin's in the builder.
 */
const value = defineModel({ default: '' });
const props = defineProps({
    field: { type: Object, required: true },
    inputId: { type: String, required: true },
    error: { type: String, default: '' },
    chooseText: { type: String, default: 'Choose…' }, // a dropdown's empty first option, in the caller's language
    // a file field's limits, in the caller's language: { one({size}), many({size, count}) } (file-hint.js)
    fileWords: {
        type: Object,
        default: () => ({ one: (p) => fill('Up to {size} MB.', p), many: (p) => fill('Up to {count} files, {size} MB each.', p) }),
    },
});
const help = computed(() => [props.field.help, props.field.type === 'file' && fileHint(props.field.options, props.fileWords)].filter(Boolean).join(' '));
const describedBy = computed(() => [help.value && `${props.inputId}-help`, props.error && `${props.inputId}-error`].filter(Boolean).join(' ') || undefined);
const inputType = computed(() => ({ email: 'email', url: 'url', number: 'number', date: 'date' })[props.field.type] ?? 'text');
const autocomplete = computed(() =>
    props.field.type === 'email' ? 'email' : /(^|_)name$/.test(props.field.name) ? 'name' : /phone|tel/.test(props.field.name) ? 'tel' : undefined,
);
</script>

<template>
    <template v-if="field.type === 'boolean'">
        <label class="k-b-form__check">
            <input
                :id="inputId"
                v-model="value"
                type="checkbox"
                :name="field.name"
                value="1"
                :required="field.required"
                :aria-invalid="error ? 'true' : undefined"
                :aria-describedby="describedBy"
            />
            <span>{{ field.label }}<span v-if="field.required" class="k-b-form__req" aria-hidden="true"> *</span></span>
        </label>
    </template>

    <fieldset
        v-else-if="field.type === 'multi_select' || (field.type === 'select' && field.options.display === 'radios')"
        class="k-b-form__set"
        :aria-describedby="describedBy"
    >
        <legend class="k-b-form__label">{{ field.label }}<span v-if="field.required" class="k-b-form__req" aria-hidden="true"> *</span></legend>
        <label v-for="(c, ci) in field.options.choices" :key="c.value" class="k-b-form__check">
            <input
                v-if="field.type === 'multi_select'"
                :id="ci === 0 ? inputId : undefined"
                v-model="value"
                type="checkbox"
                :name="`${field.name}[]`"
                :value="c.value"
            />
            <input v-else :id="ci === 0 ? inputId : undefined" v-model="value" type="radio" :name="field.name" :value="c.value" :required="field.required" />
            <span>{{ choiceLabel(c) }}</span>
        </label>
    </fieldset>

    <template v-else>
        <label class="k-b-form__label" :for="inputId">{{ field.label }}<span v-if="field.required" class="k-b-form__req" aria-hidden="true"> *</span></label>
        <select
            v-if="field.type === 'select'"
            :id="inputId"
            v-model="value"
            class="k-b-form__input"
            :name="field.name"
            :required="field.required"
            :aria-invalid="error ? 'true' : undefined"
            :aria-describedby="describedBy"
        >
            <option value="">{{ chooseText }}</option>
            <option v-for="c in field.options.choices" :key="c.value" :value="c.value">{{ choiceLabel(c) }}</option>
        </select>
        <textarea
            v-else-if="field.type === 'textarea'"
            :id="inputId"
            v-model="value"
            class="k-b-form__input"
            :name="field.name"
            :rows="field.options.rows || 5"
            :maxlength="field.options.max"
            :placeholder="field.options.placeholder"
            :required="field.required"
            :aria-invalid="error ? 'true' : undefined"
            :aria-describedby="describedBy"
        ></textarea>
        <input
            v-else-if="field.type === 'file'"
            :id="inputId"
            class="k-b-form__file"
            type="file"
            :name="field.options.multiple ? `${field.name}[]` : field.name"
            :multiple="field.options.multiple"
            :accept="field.options.accept"
            :required="field.required"
            :aria-invalid="error ? 'true' : undefined"
            :aria-describedby="describedBy"
        />
        <input
            v-else
            :id="inputId"
            v-model="value"
            class="k-b-form__input"
            :type="inputType"
            :name="field.name"
            :maxlength="field.options.max"
            :min="field.type === 'number' ? field.options.min : undefined"
            :max="field.type === 'number' ? field.options.max : undefined"
            :step="field.type === 'number' ? field.options.step || (field.options.integer ? 1 : 'any') : undefined"
            :placeholder="field.options.placeholder"
            :autocomplete="autocomplete"
            :required="field.required"
            :aria-invalid="error ? 'true' : undefined"
            :aria-describedby="describedBy"
        />
    </template>

    <p v-if="help" :id="`${inputId}-help`" class="k-b-form__help">{{ help }}</p>
    <p v-if="error" :id="`${inputId}-error`" class="k-b-form__error">{{ error }}</p>
</template>

<style>
/* the form's grid: fields full width, or half (two side by side, one on small screens) */
.k-b-form__grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 1.1rem 1.25rem;
}
.k-b-form__field {
    grid-column: 1 / -1;
    min-width: 0;
}
.k-b-form__field--half {
    grid-column: span 1;
}
@media (max-width: 36rem) {
    .k-b-form__field--half {
        grid-column: 1 / -1;
    }
}
.k-b-form__label {
    display: block;
    font-weight: 600;
    margin-bottom: 0.4rem;
    padding: 0;
}
.k-b-form__req {
    color: var(--block-accent, #2448c9);
}
.k-b-form__input {
    display: block;
    width: 100%;
    min-height: 2.75rem;
    padding: 0.55rem 0.85rem;
    font: inherit;
    color: inherit;
    background: var(--block-field-bg, #fff);
    border: 1px solid color-mix(in srgb, currentColor 30%, transparent);
    border-radius: var(--block-radius, 6px);
}
textarea.k-b-form__input {
    resize: vertical;
    min-height: 7rem;
    line-height: 1.5;
}
.k-b-form__input:focus-visible,
.k-b-form__file:focus-visible {
    outline: 3px solid var(--block-accent, #2448c9);
    outline-offset: 1px;
    border-color: transparent;
}
.k-b-form__file {
    display: block;
    width: 100%;
    min-height: 2.75rem;
    padding: 0.3rem 0.85rem 0.3rem 0.3rem;
    font: inherit;
    color: inherit;
    background: var(--block-field-bg, #fff);
    border: 1px solid color-mix(in srgb, currentColor 30%, transparent);
    border-radius: var(--block-radius, 6px);
    cursor: pointer;
}
/* the browser's own button, in the theme's shape: quiet beside the form's Send */
.k-b-form__file::file-selector-button {
    margin-right: 0.85rem;
    min-height: 2.05rem;
    padding: 0.3rem 1rem;
    font: inherit;
    font-weight: 600;
    color: inherit;
    background: transparent;
    border: 1px solid color-mix(in srgb, currentColor 35%, transparent);
    border-radius: var(--block-button-radius, 999px);
    cursor: pointer;
    transition: background-color 0.15s ease;
}
.k-b-form__file:hover::file-selector-button {
    background: color-mix(in srgb, currentColor 8%, transparent);
}
.k-b-form__set {
    border: 0;
    margin: 0;
    padding: 0;
    min-width: 0;
}
.k-b-form__set .k-b-form__check + .k-b-form__check {
    margin-top: 0.4rem;
}
.k-b-form__check {
    display: flex;
    align-items: flex-start;
    gap: 0.6rem;
    cursor: pointer;
    line-height: 1.45;
}
.k-b-form__check input {
    flex-shrink: 0;
    width: 1.15rem;
    height: 1.15rem;
    margin: 0.15rem 0 0;
    accent-color: var(--block-accent, #2448c9);
}
.k-b-form__help {
    margin: 0.35rem 0 0;
    font-size: 0.9em;
    opacity: 0.75;
}
.k-b-form__error {
    margin: 0.35rem 0 0;
    font-size: 0.92em;
    font-weight: 600;
    color: var(--block-danger, #b42318);
}
.has-error .k-b-form__input,
.has-error .k-b-form__file {
    border-color: var(--block-danger, #b42318);
}
</style>
