<script setup>
import { computed } from 'vue';
import { KImage, useCanvas } from '@kestello/app';

// core/media-text: an image beside its blocks; on phones they stack, the image first.
const props = defineProps({
    image: { type: Object, default: null },
    alt: { type: String, default: '' },
    side: { type: String, default: 'start' },
    share: { type: String, default: '50' },
    valign: { type: String, default: 'center' },
    fill: Boolean,
    width: { type: String, default: 'wide' },
});
const canvas = useCanvas();
const share = computed(() => (['40', '50', '60'].includes(String(props.share)) ? Number(props.share) : 50));
const sizes = computed(() => {
    const column = { content: 'min(44rem, 100vw - 2rem)', wide: 'min(72rem, 100vw - 2rem)' }[props.width] ?? '100vw';
    return `(min-width: 600px) calc((${column}) * ${share.value / 100}), calc(100vw - 2rem)`;
});
const classes = computed(() => [
    props.side === 'end' ? 'is-end' : 'is-start',
    `is-v-${['start', 'center', 'end'].includes(props.valign) ? props.valign : 'center'}`,
    props.fill && 'is-fill',
]);
</script>

<template>
    <div class="k-b-mediatext" :class="classes" :data-width="width" :style="{ '--k-share': `${share}%` }">
        <figure class="k-b-mediatext__media">
            <KImage v-if="image?.url" :media="image" size="large" :sizes="sizes" :alt="alt || null" />
            <div v-else-if="canvas" class="k-b-mediatext__empty">Choose an image in this block's settings.</div>
        </figure>
        <div class="k-b-mediatext__text k-b-stack"><slot /></div>
    </div>
</template>

<style scoped>
:where(.k-b-mediatext) {
    margin-block: 0;
}
.k-b-mediatext {
    display: grid;
    gap: clamp(1.25rem, 4vw, 3rem);
}
.k-b-mediatext__media {
    margin: 0;
    min-width: 0;
}
.k-b-mediatext__media :deep(img) {
    display: block;
    width: 100%;
    height: auto;
    border-radius: var(--block-radius, 6px);
}
.k-b-mediatext__empty {
    display: grid;
    place-items: center;
    min-height: 12rem;
    padding: 1rem;
    border: 1px dashed currentColor;
    border-radius: 6px;
    opacity: 0.55;
    font:
        500 14px/1.4 system-ui,
        sans-serif;
    text-align: center;
}
.k-b-mediatext__text {
    min-width: 0;
}
@media (min-width: 600px) {
    .k-b-mediatext {
        grid-template-columns: var(--k-share) 1fr;
    }
    .k-b-mediatext.is-end {
        grid-template-columns: 1fr var(--k-share);
    }
    .k-b-mediatext.is-end .k-b-mediatext__media {
        order: 2;
    }
    .k-b-mediatext.is-v-center {
        align-items: center;
    }
    .k-b-mediatext.is-v-end {
        align-items: end;
    }
    .k-b-mediatext.is-fill {
        align-items: stretch;
    }
    .k-b-mediatext.is-fill .k-b-mediatext__media {
        position: relative;
        min-height: 16rem;
    }
    .k-b-mediatext.is-fill .k-b-mediatext__media :deep(picture),
    .k-b-mediatext.is-fill .k-b-mediatext__media :deep(img) {
        position: absolute;
        inset: 0;
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    .k-b-mediatext.is-fill.is-v-center .k-b-mediatext__text {
        align-self: center;
    }
}
</style>
