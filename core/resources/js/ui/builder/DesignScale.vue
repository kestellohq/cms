<script setup>
import { computed, ref, watch } from 'vue';
import { __ } from '@kestello/sdk';
import { parseLength } from './design.js';

/**
 * One size in the styles panel: the theme's steps (S, M, L…) as buttons, or a
 * length typed in. Empty means the block's own default. When a wider screen
 * size set a value, it is shown as what applies here until this one is set.
 */
const model = defineModel({ type: String, default: null });
const props = defineProps({
    label: { type: String, required: true },
    presets: { type: Object, default: () => ({}) }, // key => { label, value }
    inherited: { type: String, default: null }, // the value a wider screen size gives
    inheritedFrom: { type: String, default: '' }, // its name, for the hint
    units: { type: Array, default: () => ['px', 'rem', 'em', '%', 'vw'] },
    disabled: Boolean,
});

const uid = `k-ds-${Math.random().toString(36).slice(2, 8)}`;
const shown = computed(() => model.value ?? props.inherited);
const isPreset = (v) => v !== null && Object.hasOwn(props.presets, v);
const typed = ref(isPreset(model.value) ? '' : (model.value ?? ''));
const invalid = ref(false);
watch(model, (v) => {
    if (!isPreset(v)) typed.value = v ?? '';
    invalid.value = false;
});

function pick(key) {
    model.value = model.value === key ? null : key;
}
function commitTyped() {
    const text = typed.value.trim();
    if (text === '') {
        if (!isPreset(model.value)) model.value = null;
        invalid.value = false;
        return;
    }
    const v = parseLength(text, props.units);
    invalid.value = v === null;
    if (v !== null) model.value = v;
}
</script>

<template>
    <div class="k-ds" :class="{ 'is-set': model !== null }">
        <div class="k-ds__head">
            <span :id="`${uid}-l`" class="k-ds__label">{{ label }}</span>
            <span v-if="model === null && inherited !== null" class="k-ds__from">{{
                isPreset(inherited)
                    ? __('as on {screen}', { screen: inheritedFrom })
                    : __('{value}, as on {screen}', { value: inherited, screen: inheritedFrom })
            }}</span>
            <button v-if="model !== null" type="button" class="k-ds__reset" :disabled="disabled" @click="model = null">
                {{ inheritedFrom && inherited !== null ? __("Use {screen}'s", { screen: inheritedFrom }) : __('Clear') }}
            </button>
        </div>
        <div class="k-ds__row">
            <div class="k-ds__steps" role="group" :aria-labelledby="`${uid}-l`">
                <button
                    v-for="(p, key) in presets"
                    :key="key"
                    type="button"
                    class="k-ds__step"
                    :class="{ 'is-inherited': model === null && shown === key }"
                    :aria-pressed="String(model === key)"
                    :title="`${p.label} (${p.value})`"
                    :disabled="disabled"
                    @click="pick(key)"
                >
                    {{ p.label }}
                </button>
            </div>
            <input
                v-model="typed"
                class="k-input k-ds__custom"
                :aria-label="__('{label}, custom', { label })"
                :aria-invalid="invalid ? 'true' : undefined"
                inputmode="decimal"
                :disabled="disabled"
                @change="commitTyped"
                @keydown.enter.prevent="commitTyped"
            />
        </div>
        <p v-if="invalid" class="k-ds__error" role="alert">{{ __('A number, or a length such as 24px or 1.5rem.') }}</p>
    </div>
</template>
