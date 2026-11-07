<script setup>
import { computed, ref } from 'vue';
import { KImage, useCanvas } from '@kestello/app';

// core/gallery: images in a grid (two columns on phones). Opened larger, visitors go through
// them with the arrow buttons or keys; Escape or a click outside closes (a native <dialog>).
const props = defineProps({
    images: { type: Array, default: () => [] },
    columns: { type: [String, Number], default: '3' },
    ratio: { type: String, default: '1/1' },
    gap: { type: String, default: 'md' },
    captions: Boolean,
    lightbox: { type: Boolean, default: true },
    width: { type: String, default: 'wide' },
});
const canvas = useCanvas();
const items = computed(() => (Array.isArray(props.images) ? props.images.filter((m) => m?.url) : []));
const cols = computed(() => Math.min(6, Math.max(2, Number(props.columns) || 3)));
const ratio = computed(() => (['1/1', '4/3', '3/2', '16/9', '3/4'].includes(props.ratio) ? props.ratio.replace('/', ' / ') : null));
const sizes = computed(() => {
    const column = { content: 'min(44rem, 100vw - 2rem)', wide: 'min(72rem, 100vw - 2rem)' }[props.width] ?? '100vw';
    return `(min-width: 600px) calc((${column}) / ${cols.value}), 50vw`;
});
const zoomable = computed(() => props.lightbox && !canvas);

const box = ref(null);
const at = ref(0);
const current = computed(() => items.value[at.value] ?? null);
const big = (m) => m?.sizes?.large?.src ?? m?.url;
const label = (m, i) => m?.alt || `Image ${i + 1} of ${items.value.length}`;
let opener = null;
function open(i, event) {
    at.value = i;
    opener = event.currentTarget;
    box.value?.showModal();
}
function go(delta) {
    at.value = (at.value + delta + items.value.length) % items.value.length;
}
function onKey(event) {
    if (event.key === 'ArrowRight') {
        event.preventDefault();
        go(1);
    }
    if (event.key === 'ArrowLeft') {
        event.preventDefault();
        go(-1);
    }
}
function onClose() {
    opener?.focus();
}
</script>

<template>
    <figure
        v-if="items.length"
        class="k-b-gallery"
        :class="[`is-gap-${gap}`, ratio && 'has-ratio']"
        :data-width="width"
        :style="{ '--k-cols': cols, '--k-ratio': ratio ?? undefined }"
    >
        <ul class="k-b-gallery__grid">
            <li v-for="(m, i) in items" :key="m.id ?? i" class="k-b-gallery__item">
                <button v-if="zoomable" type="button" class="k-b-gallery__open" :aria-label="`Open larger: ${label(m, i)}`" @click="open(i, $event)">
                    <KImage :media="m" size="medium" :sizes="sizes" />
                </button>
                <KImage v-else :media="m" size="medium" :sizes="sizes" />
                <span v-if="captions && m.caption" class="k-b-gallery__caption">{{ m.caption }}</span>
            </li>
        </ul>
        <dialog v-if="zoomable" ref="box" class="k-b-gallery__box" aria-label="Images, larger" @keydown="onKey" @close="onClose" @click.self="box?.close()">
            <figure v-if="current" class="k-b-gallery__shown">
                <img :src="big(current)" :alt="current.alt || ''" />
                <figcaption>
                    <span v-if="current.caption">{{ current.caption }}</span>
                    <span class="k-b-gallery__count">{{ at + 1 }} / {{ items.length }}</span>
                </figcaption>
            </figure>
            <template v-if="items.length > 1">
                <button type="button" class="k-b-gallery__nav is-prev" aria-label="Previous image" @click="go(-1)">‹</button>
                <button type="button" class="k-b-gallery__nav is-next" aria-label="Next image" @click="go(1)">›</button>
            </template>
            <button type="button" class="k-b-gallery__close" aria-label="Close" @click="box?.close()">×</button>
        </dialog>
    </figure>
</template>

<style scoped>
:where(.k-b-gallery) {
    margin-block: 0;
}
.k-b-gallery__grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1rem;
    margin: 0;
    padding: 0;
    list-style: none;
}
@media (min-width: 600px) {
    .k-b-gallery__grid {
        grid-template-columns: repeat(var(--k-cols, 3), 1fr);
    }
}
.k-b-gallery.is-gap-sm .k-b-gallery__grid {
    gap: 0.5rem;
}
.k-b-gallery.is-gap-lg .k-b-gallery__grid {
    gap: clamp(1rem, 3vw, 2rem);
}
.k-b-gallery.is-gap-none .k-b-gallery__grid {
    gap: 0;
}
.k-b-gallery__item {
    min-width: 0;
    margin: 0;
}
.k-b-gallery__open {
    display: block;
    width: 100%;
    padding: 0;
    border: 0;
    background: none;
    cursor: zoom-in;
}
.k-b-gallery__open:focus-visible {
    outline: 3px solid var(--block-accent, #2448c9);
    outline-offset: 2px;
}
.k-b-gallery :deep(img) {
    display: block;
    width: 100%;
    height: auto;
    border-radius: var(--block-radius, 6px);
}
.k-b-gallery.has-ratio :deep(.k-b-gallery__item img) {
    aspect-ratio: var(--k-ratio);
    object-fit: cover;
}
.k-b-gallery.is-gap-none :deep(img) {
    border-radius: 0;
}
.k-b-gallery__caption {
    display: block;
    margin-top: 0.4rem;
    font-size: 0.875rem;
    opacity: 0.75;
}
.k-b-gallery__box {
    max-width: 100vw;
    max-height: 100vh;
    width: 100vw;
    height: 100vh;
    margin: 0;
    padding: 0;
    border: 0;
    background: transparent;
    color: #fff;
}
.k-b-gallery__box::backdrop {
    background: rgb(0 0 0 / 0.9);
}
.k-b-gallery__shown {
    display: grid;
    place-items: center;
    align-content: center;
    gap: 0.75rem;
    height: 100%;
    margin: 0;
    padding: 3.5rem 4rem;
    box-sizing: border-box;
    pointer-events: none;
}
.k-b-gallery__shown img {
    max-width: 100%;
    max-height: calc(100vh - 9rem);
    width: auto;
    height: auto;
    border-radius: 0;
    pointer-events: auto;
}
.k-b-gallery__shown figcaption {
    display: flex;
    gap: 1rem;
    font:
        500 0.9rem/1.4 system-ui,
        sans-serif;
    opacity: 0.85;
}
.k-b-gallery__count {
    font-variant-numeric: tabular-nums;
    opacity: 0.7;
}
.k-b-gallery__nav,
.k-b-gallery__close {
    position: fixed;
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
.k-b-gallery__nav:hover,
.k-b-gallery__close:hover {
    background: rgb(255 255 255 / 0.26);
}
.k-b-gallery__nav:focus-visible,
.k-b-gallery__close:focus-visible {
    outline: 2px solid #fff;
    outline-offset: 2px;
}
.k-b-gallery__nav {
    top: 50%;
    translate: 0 -50%;
}
.k-b-gallery__nav.is-prev {
    left: 12px;
}
.k-b-gallery__nav.is-next {
    right: 12px;
}
.k-b-gallery__close {
    top: 12px;
    right: 12px;
}
@media (max-width: 599.98px) {
    .k-b-gallery__shown {
        padding-inline: 0.5rem;
    }
    .k-b-gallery__nav {
        top: auto;
        bottom: 16px;
        translate: none;
    }
}
</style>
