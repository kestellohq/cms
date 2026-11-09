<script setup>
import { computed, ref, watch } from 'vue';
import { formatBytes, __ } from '@kestello/sdk';
import KIcon from './KIcon.vue';
import { acceptedLabel, fileProblem } from './file-rules.js';

/**
 * One file for a form: choose it or drop it, see its name and size, change
 * or remove it. The real file input stays in the page (visually hidden), so
 * the field label, keyboard, `required` and screen readers work as usual.
 *   <KField v-slot="{ id, describedBy }" :label="__('Backup zip')">
 *       <KFileInput :id="id" v-model="file" accept=".zip,application/zip" :described-by="describedBy" />
 *   </KField>
 */
const props = defineProps({
    modelValue: { type: Object, default: null }, // File | null
    id: { type: String, required: true },
    accept: { type: String, default: '' }, // as on <input type="file">: extensions and media types
    maxBytes: { type: Number, default: 0 }, // 0: no limit checked here (the server still checks)
    describedBy: { type: String, default: undefined },
    required: Boolean,
    disabled: Boolean,
});
const emit = defineEmits(['update:modelValue']);
const input = ref(null);
const over = ref(false);
const problem = ref('');

// What the file chooser shows as allowed, such as "ZIP" or "XML".
const types = computed(() => acceptedLabel(props.accept));

/** Why this file can't be used here, in words, or '' when it can. */
function refuse(file) {
    const problem = fileProblem(file, props.accept, props.maxBytes);
    if (problem?.reason === 'type') {
        return types.value
            ? __("This file can't be used here. Choose one of these: {types}.", { types: types.value })
            : __('This type of file is not allowed here.');
    }
    if (problem?.reason === 'size') {
        return __('This file is {size}. The limit is {max}.', { size: formatBytes(problem.size), max: formatBytes(problem.max) });
    }
    return '';
}
function take(file) {
    problem.value = file ? refuse(file) : '';
    emit('update:modelValue', file && !problem.value ? file : null);
}
function picked(e) {
    take(e.target.files?.[0] ?? null);
}
function clear() {
    problem.value = '';
    emit('update:modelValue', null);
    input.value?.focus();
}
// The parent can reset the form by setting the model to null.
watch(
    () => props.modelValue,
    (file) => {
        if (!file && input.value) input.value.value = '';
    },
);

const hasFiles = (e) => [...(e.dataTransfer?.types ?? [])].includes('Files');
let depth = 0;
function enter(e) {
    if (props.disabled || !hasFiles(e)) return;
    depth++;
    over.value = true;
}
function leave() {
    depth = Math.max(0, depth - 1);
    if (!depth) over.value = false;
}
function drop(e) {
    depth = 0;
    over.value = false;
    if (props.disabled || !hasFiles(e)) return;
    const files = [...e.dataTransfer.files];
    if (files.length > 1) {
        problem.value = __('Drop one file at a time.');
        return;
    }
    take(files[0] ?? null);
}
</script>

<template>
    <div
        class="k-file"
        :class="{ 'is-over': over, 'has-file': modelValue, 'has-problem': problem, 'is-disabled': disabled }"
        @dragenter.prevent="enter"
        @dragleave="leave"
        @dragover.prevent
        @drop.prevent="drop"
    >
        <input
            :id="id"
            ref="input"
            type="file"
            class="k-file__input"
            :accept="accept || undefined"
            :required="required && !modelValue"
            :disabled="disabled"
            :aria-describedby="[describedBy, problem ? `${id}-problem` : ''].filter(Boolean).join(' ') || undefined"
            :aria-invalid="problem ? 'true' : undefined"
            @change="picked"
        />
        <template v-if="modelValue">
            <KIcon name="file" class="k-file__icon" />
            <span class="k-file__body">
                <span class="k-file__chosen">
                    <span class="k-file__name">{{ modelValue.name }}</span>
                    <span class="k-muted k-small k-num">{{ formatBytes(modelValue.size) }}</span>
                </span>
                <span class="k-file__actions">
                    <label :for="id" class="k-link-btn">{{ __('Change') }}</label>
                    <button type="button" class="k-link-btn" :disabled="disabled" @click="clear">{{ __('Remove') }}</button>
                </span>
            </span>
        </template>
        <label v-else :for="id" class="k-file__empty">
            <KIcon name="file-arrow-up" class="k-file__icon" />
            <span>
                <span class="k-file__choose">{{ __('Choose a file') }}</span>
                {{ __('or drop it here.') }}
                <span v-if="types" class="k-muted k-small k-file__types">{{ types }}</span>
            </span>
        </label>
        <p v-if="problem" :id="`${id}-problem`" class="k-file__problem" role="alert">{{ problem }}</p>
    </div>
</template>
