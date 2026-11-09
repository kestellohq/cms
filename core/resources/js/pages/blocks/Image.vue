<script setup>
import { computed, ref } from 'vue';
import { KImage, Link, useCanvas } from '@kestello/app';

const props = defineProps({
    image: { type: Object, default: null },
    alt: { type: String, default: '' },
    caption: { type: String, default: '' },
    link: { type: String, default: '' },
    scale: { type: [Number, String], default: 100 },
    align: { type: String, default: 'center' },
    ratio: { type: String, default: '' },
    corners: { type: String, default: '' },
    lightbox: Boolean,
    width: { type: String, default: 'content' },
});
const canvas = useCanvas();
const pct = computed(() => Math.min(100, Math.max(10, Number(props.scale) || 100)));
const RATIOS = ['1/1', '4/3', '3/2', '16/9', '3/4', '2/3'];
const ratio = computed(() => (RATIOS.includes(props.ratio) ? props.ratio.replace('/', ' / ') : null));
const ALIGNS = ['center', 'start', 'end', 'wrap-start', 'wrap-end'];
const align = computed(() => (ALIGNS.includes(props.align) ? props.align : 'center'));
const CORNERS = ['square', 'rounded', 'round'];
const corners = computed(() => (CORNERS.includes(props.corners) ? `is-${props.corners}` : null));
// the browser picks a file close to the width the image is shown at
const sizes = computed(() => {
    const column = { content: 'min(44rem, 100vw - 2rem)', wide: 'min(72rem, 100vw - 2rem)', full: '100vw' }[props.width] ?? '100vw';
    return pct.value === 100 ? `calc(${column})` : `calc((${column}) * ${pct.value / 100})`;
});
const rendition = computed(() => (pct.value <= 40 && props.width !== 'full' ? 'medium' : 'large'));
const internal = computed(() => props.link.startsWith('/') && !props.link.startsWith('//'));
const zoomable = computed(() => props.lightbox && !props.link && !canvas);
const box = ref(null);
const big = computed(() => props.image?.sizes?.large?.src ?? props.image?.url);
const label = computed(() => props.alt || props.image?.alt || '');
</script>

<template>
    <figure
        v-if="image?.url"
        class="k-b-image"
        :class="[`is-${align}`, corners, ratio && 'has-ratio']"
        :data-width="width"
        :style="{ '--k-size': `${pct}%`, '--k-size-f': pct / 100, '--k-img-ratio': ratio ?? undefined }"
    >
        <component
            :is="link ? (internal ? Link : 'a') : zoomable ? 'button' : 'div'"
            :href="link || undefined"
            :type="zoomable ? 'button' : undefined"
            class="k-b-image__frame"
            data-k-resize="scale"
            :aria-label="zoomable ? (label ? `Open larger: ${label}` : 'Open the image larger') : undefined"
            @click="zoomable && box?.showModal()"
        >
            <KImage :media="image" :size="rendition" :sizes="sizes" :alt="alt || null" />
        </component>
        <figcaption v-if="caption || canvas" data-k-field="caption" data-placeholder="Caption">{{ caption }}</figcaption>
        <dialog v-if="zoomable" ref="box" class="k-b-image__box" @click="box?.close()">
            <img :src="big" :alt="label" />
            <button type="button" class="k-b-image__close" aria-label="Close" @click.stop="box?.close()">×</button>
        </dialog>
    </figure>
</template>

<style scoped>
:where(.k-b-image) {
    margin-block: 0;
} /* no weight: the spacing between blocks (.k-b-stack > * + *) wins */
.k-b-image {
    display: flow-root;
}
.k-b-image__frame {
    display: block;
    width: var(--k-size, 100%);
    max-width: 100%;
    margin-inline: auto;
    padding: 0;
    border: 0;
    background: none;
    color: inherit;
    font: inherit;
}
button.k-b-image__frame {
    cursor: zoom-in;
}
.k-b-image.is-start .k-b-image__frame {
    margin-inline: 0 auto;
}
.k-b-image.is-end .k-b-image__frame {
    margin-inline: auto 0;
}
.k-b-image :deep(img) {
    display: block;
    width: 100%;
    height: auto;
    border-radius: var(--block-radius, 6px);
}
.k-b-image.has-ratio :deep(img) {
    aspect-ratio: var(--k-img-ratio);
    object-fit: cover;
}
.k-b-image.is-square :deep(img) {
    border-radius: 0;
}
.k-b-image.is-rounded :deep(img) {
    border-radius: max(12px, var(--block-radius, 6px));
}
.k-b-image.is-round :deep(img) {
    border-radius: 50%;
    aspect-ratio: 1 / 1;
    object-fit: cover;
}
.k-b-image[data-width='full'] :deep(img) {
    border-radius: 0;
}
.k-b-image figcaption {
    margin-top: 0.5rem;
    font-size: 0.875rem;
    opacity: 0.75;
}
.k-b-image.is-center figcaption {
    text-align: center;
}
.k-b-image.is-end figcaption {
    text-align: end;
}
.k-b-image[data-width='full'] figcaption {
    padding-inline: 1rem;
}
/* text flows around: the figure floats at a share of the column (at most 60%, so the text keeps
   room) and the next blocks wrap. At the top of the page the column sits in the middle of a wider
   box, so the float is held in by the gutter to line up with the text. */
.k-b-image.is-wrap-start,
.k-b-image.is-wrap-end {
    --k-col: 100%;
    --k-gutter: 0px;
    width: calc(var(--k-col) * min(var(--k-size-f, 0.5), 0.6));
    padding-inline: 0;
}
.k-blocks > .k-b-image.is-wrap-start,
.k-blocks > .k-b-image.is-wrap-end {
    --k-col: min(44rem, 100% - 2rem);
    --k-gutter: calc((100% - min(44rem, 100% - 2rem)) / 2);
}
.k-b-image.is-wrap-start .k-b-image__frame,
.k-b-image.is-wrap-end .k-b-image__frame {
    width: 100%;
}
.k-b-image.is-wrap-start {
    float: left;
    margin: 0.25rem 1.5rem 1rem var(--k-gutter);
}
.k-b-image.is-wrap-end {
    float: right;
    margin: 0.25rem var(--k-gutter) 1rem 1.5rem;
}
@media (max-width: 36rem) {
    .k-b-image.is-wrap-start,
    .k-b-image.is-wrap-end {
        float: none;
        width: auto;
        margin-inline: auto;
        padding-inline: 1rem;
    }
}
.k-b-image__box {
    max-width: 96vw;
    max-height: 96vh;
    padding: 0;
    border: 0;
    background: transparent;
    overflow: visible;
}
.k-b-image__box::backdrop {
    background: rgb(0 0 0 / 0.85);
}
.k-b-image__box img {
    display: block;
    max-width: 96vw;
    max-height: 92vh;
    width: auto;
    height: auto;
    border-radius: 0;
}
.k-b-image__close {
    position: fixed;
    top: 12px;
    right: 16px;
    width: 44px;
    height: 44px;
    border: 0;
    border-radius: 50%;
    background: rgb(255 255 255 / 0.14);
    color: #fff;
    font-size: 28px;
    line-height: 1;
    cursor: pointer;
}
.k-b-image__close:focus-visible {
    outline: 2px solid #fff;
    outline-offset: 2px;
}
</style>
