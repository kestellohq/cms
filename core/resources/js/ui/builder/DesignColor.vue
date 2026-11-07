<script setup>
import { computed, ref, watch } from 'vue';
import { __ } from '@kestello/sdk';
import { fullHex, parseColor } from './design.js';

/**
 * One colour in the styles panel: the theme's palette as swatches, or a colour
 * of one's own (picker or hex). Empty means the block's own default.
 * The swatches show the colours as the page has them (resolve), since a preset
 * may name a theme token the admin doesn't have.
 */
const model = defineModel({ type: String, default: null });
const props = defineProps({
    label: { type: String, required: true },
    presets: { type: Object, default: () => ({}) }, // key => { label, value }
    resolve: { type: Function, default: (key) => null }, // key => the colour on the page, e.g. "rgb(30, 58, 138)"
    inherited: { type: String, default: null },
    inheritedFrom: { type: String, default: '' },
    disabled: Boolean,
});

const uid = `k-dc-${Math.random().toString(36).slice(2, 8)}`;
const isPreset = (v) => v !== null && Object.hasOwn(props.presets, v);
const custom = computed(() => (model.value !== null && !isPreset(model.value) ? model.value : null));
const hex = ref(custom.value ?? '');
const invalid = ref(false);
watch(custom, (v) => {
    hex.value = v ?? '';
    invalid.value = false;
});

const swatch = (key) => props.resolve(key) ?? props.presets[key]?.value ?? 'transparent';
const shownName = computed(() => {
    const v = model.value ?? props.inherited;
    if (v === null) return '';
    return isPreset(v) ? props.presets[v].label : v;
});

function pick(key) {
    model.value = model.value === key ? null : key;
}
function typed() {
    if (hex.value.trim() === '') {
        if (custom.value) model.value = null;
        invalid.value = false;
        return;
    }
    const c = parseColor(hex.value);
    invalid.value = c === null;
    if (c) model.value = c;
}
</script>

<template>
    <div class="k-dc" :class="{ 'is-set': model !== null }">
        <div class="k-ds__head">
            <span :id="`${uid}-l`" class="k-ds__label">{{ label }}</span>
            <span v-if="shownName" class="k-ds__from">{{
                model === null ? __('{value}, as on {screen}', { value: shownName, screen: inheritedFrom }) : shownName
            }}</span>
            <button v-if="model !== null" type="button" class="k-ds__reset" :disabled="disabled" @click="model = null">
                {{ inheritedFrom && inherited !== null ? __("Use {screen}'s", { screen: inheritedFrom }) : __('Clear') }}
            </button>
        </div>
        <div class="k-dc__swatches" role="group" :aria-labelledby="`${uid}-l`">
            <button
                v-for="(p, key) in presets"
                :key="key"
                type="button"
                class="k-dc__swatch"
                :class="{ 'is-inherited': model === null && inherited === key }"
                :style="{ '--swatch': swatch(key) }"
                :aria-pressed="String(model === key)"
                :title="p.label"
                :aria-label="p.label"
                :disabled="disabled"
                @click="pick(key)"
            ></button>
            <label class="k-dc__own" :class="{ 'is-on': custom }" :title="__('A colour of your own')">
                <input
                    type="color"
                    class="k-dc__picker"
                    :value="fullHex(custom) || '#000000'"
                    :aria-label="__('{label}: a colour of your own', { label })"
                    :disabled="disabled"
                    @change="model = parseColor($event.target.value)"
                />
                <span class="k-dc__own-chip" :style="custom ? { '--swatch': custom } : null" aria-hidden="true"></span>
            </label>
            <input
                v-model="hex"
                class="k-input k-dc__hex"
                :aria-label="__('{label}, hex', { label })"
                :aria-invalid="invalid ? 'true' : undefined"
                spellcheck="false"
                maxlength="7"
                :disabled="disabled"
                @change="typed"
                @keydown.enter.prevent="typed"
            />
        </div>
        <p v-if="invalid" class="k-ds__error" role="alert">{{ __('A colour such as #1e3a8a.') }}</p>
    </div>
</template>
