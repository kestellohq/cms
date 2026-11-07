<script setup>
import { computed } from 'vue';
import { KImage } from '@kestello/app';

// core/cover: blocks over an image (cropped around its focal point) or a colour (Style tab).
// The shade keeps text readable; on a dark shade the text turns light. Without an image it
// has the theme's muted background.
const props = defineProps({
    image: { type: Object, default: null },
    shade: { type: String, default: 'dark' },
    strength: { type: [Number, String], default: 45 },
    height: { type: String, default: 'medium' },
    valign: { type: String, default: 'center' },
    align: { type: String, default: 'center' },
    tag: { type: String, default: 'section' },
    width: { type: String, default: 'full' },
});
const pick = (value, allowed, fallback) => (allowed.includes(value) ? value : fallback);
const tag = computed(() => pick(props.tag, ['section', 'header', 'div'], 'section'));
const shade = computed(() => (props.image?.url ? pick(props.shade, ['dark', 'light', 'none'], 'dark') : 'none'));
const level = computed(() => Math.min(90, Math.max(0, Number(props.strength) || 0)) / 100);
const classes = computed(() => [
    `is-h-${pick(props.height, ['auto', 'short', 'medium', 'tall', 'screen'], 'medium')}`,
    `is-v-${pick(props.valign, ['start', 'center', 'end'], 'center')}`,
    `is-a-${pick(props.align, ['start', 'center', 'end'], 'center')}`,
    `is-shade-${shade.value}`,
    props.image?.url ? 'has-image' : 'no-image',
]);
</script>

<template>
    <component :is="tag" class="k-b-cover" :class="classes" :data-width="width" :style="{ '--k-shade': level }">
        <KImage v-if="image?.url" class="k-b-cover__img" :media="image" size="large" sizes="100vw" alt="" />
        <span v-if="shade !== 'none'" class="k-b-cover__shade" aria-hidden="true"></span>
        <div class="k-b-cover__inner k-b-stack"><slot /></div>
    </component>
</template>

<style scoped>
:where(.k-b-cover) {
    margin-block: 0;
} /* no weight: the spacing between blocks wins */
.k-b-cover {
    position: relative;
    isolation: isolate;
    display: flex;
    flex-direction: column;
    justify-content: center;
    overflow: hidden;
    padding-block: clamp(2.5rem, 6vw, 5rem);
    border-radius: var(--block-radius, 6px);
}
.k-b-cover[data-width='full'] {
    border-radius: 0;
}
/* no image yet: the theme's muted background, so it still reads as a banner (a colour from Style wins) */
.k-b-cover.no-image {
    background: var(--block-muted-bg, color-mix(in srgb, currentColor 6%, transparent));
}
.k-b-cover.is-h-short {
    min-height: max(18rem, 40vh);
}
.k-b-cover.is-h-medium {
    min-height: max(22rem, 60vh);
}
.k-b-cover.is-h-tall {
    min-height: max(26rem, 80vh);
}
.k-b-cover.is-h-screen {
    min-height: 100svh;
}
.k-b-cover.is-v-start {
    justify-content: flex-start;
}
.k-b-cover.is-v-end {
    justify-content: flex-end;
}
.k-b-cover :deep(.k-b-cover__img) {
    position: absolute;
    inset: 0;
    z-index: -2;
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.k-b-cover__shade {
    position: absolute;
    inset: 0;
    z-index: -1;
}
.k-b-cover.is-shade-dark {
    color: #fff;
}
.k-b-cover.is-shade-dark .k-b-cover__shade {
    background: rgb(0 0 0 / var(--k-shade));
}
.k-b-cover.is-shade-light {
    color: #1b1b1a;
}
.k-b-cover.is-shade-light .k-b-cover__shade {
    background: rgb(255 255 255 / var(--k-shade));
}
.k-b-cover.is-shade-dark :deep(a:not([class])) {
    color: inherit;
}
/* on a dark shade, outline and plain buttons turn light (the accent colour would be lost on it) */
.k-b-cover.is-shade-dark :deep(.k-b-button--secondary),
.k-b-cover.is-shade-dark :deep(.k-b-button--plain) {
    color: #fff;
    border-color: currentColor;
}
.k-b-cover.is-shade-dark :deep(.k-b-button--plain) {
    border-color: transparent;
}
.k-b-cover.is-shade-dark :deep(.k-b-button--secondary:hover) {
    background: #fff;
    border-color: #fff;
    color: #1b1b1a;
}
.k-b-cover__inner {
    width: min(44rem, 100% - 2rem);
    margin-inline: auto;
}
.k-b-cover[data-width='wide'] .k-b-cover__inner,
.k-b-cover[data-width='full'] .k-b-cover__inner {
    width: min(56rem, 100% - 2rem);
}
.k-b-cover.is-a-center .k-b-cover__inner {
    text-align: center;
}
.k-b-cover.is-a-end .k-b-cover__inner {
    text-align: end;
}
/* a row of buttons left on its default (left) follows the cover's alignment */
.k-b-cover.is-a-center .k-b-cover__inner > :deep(.k-b-buttons.is-start) {
    justify-content: center;
}
.k-b-cover.is-a-end .k-b-cover__inner > :deep(.k-b-buttons.is-start) {
    justify-content: flex-end;
}
</style>
