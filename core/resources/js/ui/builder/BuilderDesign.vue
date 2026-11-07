<script setup>
import { computed, ref, watch } from 'vue';
import { __ } from '@kestello/sdk';
import KIcon from '../KIcon.vue';
import KSelect from '../KSelect.vue';
import { useBuilder } from './context.js';
import { effective, withValue } from '../../shared/block-design.js';
import { sameSides, screenOf, withoutScreen } from './design.js';
import DesignColor from './DesignColor.vue';
import DesignScale from './DesignScale.vue';

/**
 * The selected block's styles (the Style tab): colour, spacing, type and border,
 * as far as the block supports them (BlockDefinition::supports). They are set
 * for the screen size the canvas shows: desktop styles every size, tablet and
 * phone change only theirs (and smaller), the way the page applies them
 * (shared/block-design.js).
 */
const { props, selected, selectedAt, selectedDef, designSpec, device, DEVICES, patchSelected, editLocked, frame, ready } = useBuilder();

const screen = computed(() => screenOf(device.value));
const supports = computed(() => selectedDef.value?.supports ?? []);
const presets = computed(() => designSpec.value?.presets ?? {});
const design = computed(() => selectedAt.value?.node.design ?? null);
const disabled = computed(() => props.disabled || editLocked.value); // locked by the layout: styles can't change either
const SCREEN_NAME = { base: __('Desktop'), tablet: __('Tablet'), phone: __('Phone') };

/** This screen size's own value, and the one it shows meanwhile (from a wider size). */
const own = (prop) => design.value?.[screen.value]?.[prop] ?? null;
function wider(prop) {
    if (screen.value === 'base') return { value: null, name: '' };
    const order = ['base', 'tablet', 'phone'];
    const above = order[order.indexOf(screen.value) - 1];
    const e = effective(design.value, prop, above);
    return { value: e.value, name: e.from ? SCREEN_NAME[e.from] : '' };
}
/** What a control for one style is given: v-bind="field('text')". */
const field = (prop) => ({ modelValue: own(prop), inherited: wider(prop).value, inheritedFrom: wider(prop).name, disabled: disabled.value });
function set(prop, value) {
    if (disabled.value) return;
    patchSelected({ design: withValue(design.value, screen.value, prop, value) });
}
function setMany(propsList, value) {
    if (disabled.value) return;
    let next = design.value;
    for (const p of propsList) next = withValue(next, screen.value, p, value);
    patchSelected({ design: next });
}

// padding: one control while the four sides match, four when they differ (or on request)
const SIDES = ['padding-top', 'padding-right', 'padding-bottom', 'padding-left'];
const sidesApart = ref(false);
watch(
    [selected, screen],
    () => {
        sidesApart.value = !sameSides(SIDES.map(own));
    },
    { immediate: true },
);
const SIDE_LABEL = { 'padding-top': __('Top'), 'padding-right': __('Right'), 'padding-bottom': __('Bottom'), 'padding-left': __('Left') };

// the palette as the page draws it (a preset may be a theme token the admin doesn't have)
const resolved = ref({});
function readPalette() {
    const doc = frame.value?.contentDocument;
    if (!doc?.documentElement) return;
    const style = doc.defaultView.getComputedStyle(doc.documentElement);
    resolved.value = Object.fromEntries(Object.keys(presets.value.color ?? {}).map((k) => [k, style.getPropertyValue(`--k-color-${k}`).trim() || null]));
}
watch([ready, presets], readPalette, { immediate: true });
const resolve = (key) => resolved.value[key] ?? null;

function clearScreen() {
    if (!disabled.value) patchSelected({ design: withoutScreen(design.value, screen.value) });
}
const anyHere = computed(() => Object.keys(design.value?.[screen.value] ?? {}).length > 0);
const lines = computed(() => [
    { value: '', label: __('Default') },
    ...(designSpec.value?.lines ?? []).map((l) => ({
        value: l,
        label: { solid: __('Solid'), dashed: __('Dashed'), dotted: __('Dotted'), double: __('Double'), none: __('None') }[l] ?? l,
    })),
]);
</script>

<template>
    <div class="k-design">
        <div class="k-design__screen">
            <div class="k-design__devices" role="group" :aria-label="__('Styles for')">
                <button
                    v-for="[id, icon, label] in DEVICES"
                    :key="id"
                    type="button"
                    class="k-design__device"
                    :aria-pressed="String(device === id)"
                    :title="label"
                    @click="device = id"
                >
                    <KIcon :name="icon" /><span>{{ label }}</span>
                </button>
            </div>
            <p class="k-design__note">
                {{
                    screen === 'base'
                        ? __('Styles set here apply on every screen size.')
                        : __('Changes here apply on {screen} only; the rest follows desktop.', { screen: SCREEN_NAME[screen].toLowerCase() })
                }}
            </p>
        </div>

        <p v-if="!supports.length" class="k-pane__empty">{{ __('This block has no style settings. Its look comes from the theme.') }}</p>

        <section v-if="supports.includes('color')" class="k-design__group">
            <h3 class="k-design__title">{{ __('Colour') }}</h3>
            <DesignColor v-bind="field('text')" :label="__('Text')" :presets="presets.color" :resolve="resolve" @update:model-value="set('text', $event)" />
            <DesignColor
                v-bind="field('background')"
                :label="__('Background')"
                :presets="presets.color"
                :resolve="resolve"
                @update:model-value="set('background', $event)"
            />
        </section>

        <section v-if="supports.includes('spacing')" class="k-design__group">
            <h3 class="k-design__title">{{ __('Spacing') }}</h3>
            <template v-if="!sidesApart">
                <DesignScale v-bind="field('padding-top')" :label="__('Padding')" :presets="presets.space" @update:model-value="setMany(SIDES, $event)" />
            </template>
            <template v-else>
                <DesignScale
                    v-for="side in SIDES"
                    :key="side"
                    v-bind="field(side)"
                    :label="__('Padding: {side}', { side: SIDE_LABEL[side] })"
                    :presets="presets.space"
                    @update:model-value="set(side, $event)"
                />
            </template>
            <button type="button" class="k-design__link" :aria-pressed="String(sidesApart)" @click="sidesApart = !sidesApart">
                <KIcon :name="sidesApart ? 'link-break' : 'link'" />{{ sidesApart ? __('Same padding on every side') : __('Different padding per side') }}
            </button>
            <DesignScale v-bind="field('margin-top')" :label="__('Space above')" :presets="presets.space" @update:model-value="set('margin-top', $event)" />
            <DesignScale
                v-bind="field('margin-bottom')"
                :label="__('Space below')"
                :presets="presets.space"
                @update:model-value="set('margin-bottom', $event)"
            />
        </section>

        <section v-if="supports.includes('type')" class="k-design__group">
            <h3 class="k-design__title">{{ __('Type') }}</h3>
            <DesignScale
                v-bind="field('font-size')"
                :label="__('Text size')"
                :presets="presets.font"
                :units="['px', 'rem', 'em']"
                @update:model-value="set('font-size', $event)"
            />
        </section>

        <section v-if="supports.includes('border')" class="k-design__group">
            <h3 class="k-design__title">{{ __('Border') }}</h3>
            <DesignScale
                v-bind="field('border-width')"
                :label="__('Line width')"
                :presets="{}"
                :units="['px']"
                @update:model-value="set('border-width', $event)"
            />
            <label class="k-design__field">
                <span class="k-ds__label">{{ __('Line') }}</span>
                <KSelect
                    :model-value="own('border-style') ?? ''"
                    :options="lines"
                    :disabled="disabled"
                    @update:model-value="set('border-style', $event || null)"
                />
            </label>
            <DesignColor
                v-bind="field('border-color')"
                :label="__('Line colour')"
                :presets="presets.color"
                :resolve="resolve"
                @update:model-value="set('border-color', $event)"
            />
            <DesignScale
                v-bind="field('radius')"
                :label="__('Corners')"
                :presets="presets.radius"
                :units="['px', 'rem', '%']"
                @update:model-value="set('radius', $event)"
            />
        </section>

        <button v-if="anyHere && !disabled" type="button" class="k-design__clear" @click="clearScreen">
            {{ screen === 'base' ? __('Clear every style of this block') : __('Clear the {screen} styles', { screen: SCREEN_NAME[screen].toLowerCase() }) }}
        </button>
    </div>
</template>
