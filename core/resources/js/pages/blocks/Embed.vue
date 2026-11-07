<script setup>
import { computed, onMounted, ref } from 'vue';
import { KImage, useCanvas } from '@kestello/app';

// YouTube and Vimeo stay behind a click-to-load facade: nothing is requested from them until the visitor presses play.
const props = defineProps({
    file: { type: Object, default: null },
    url: { type: String, default: '' },
    title: { type: String, default: '' },
    poster: { type: Object, default: null },
    ratio: { type: String, default: '16/9' },
    start: { type: [Number, String], default: null },
    autoplay: Boolean,
    loop: Boolean,
    controls: { type: Boolean, default: true },
    width: { type: String, default: 'wide' },
    embed: { type: Object, default: null },
});
const canvas = useCanvas();
const playing = ref(false);
const provider = computed(() => (props.embed?.provider === 'vimeo' ? 'Vimeo' : 'YouTube'));
const RATIOS = ['16/9', '4/3', '1/1', '9/16', '21/9'];
const ratio = computed(() => (RATIOS.includes(props.ratio) ? props.ratio.replace('/', ' / ') : '16 / 9'));
const video = computed(() => (props.file?.url && props.file.kind === 'video' ? props.file : null));
const startAt = computed(() => Math.max(0, Number(props.start) || 0));
const src = computed(() => (video.value ? video.value.url + (startAt.value ? `#t=${startAt.value}` : '') : ''));
// visitors who ask for less motion get a play button instead of a moving background
const reduced = ref(false);
onMounted(() => {
    reduced.value = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;
});
const autoplaying = computed(() => props.autoplay && !reduced.value && !canvas);
</script>

<template>
    <figure v-if="video" class="k-b-embed" :data-width="width" :style="{ '--k-video-ratio': ratio }">
        <div class="k-b-embed__frame">
            <video
                :src="src"
                :poster="poster?.sizes?.large?.src ?? poster?.url ?? undefined"
                :title="title || undefined"
                :aria-label="title || undefined"
                :autoplay="autoplaying"
                :muted="autoplaying"
                :loop="loop || autoplaying"
                :controls="controls || !autoplaying"
                playsinline
                preload="metadata"
            ></video>
        </div>
    </figure>
    <figure v-else-if="embed?.src" class="k-b-embed" :data-width="width" :style="{ '--k-video-ratio': ratio }">
        <div class="k-b-embed__frame">
            <iframe
                v-if="playing && !canvas"
                :src="embed.src"
                :title="title || `${provider} video`"
                allow="autoplay; encrypted-media; picture-in-picture; fullscreen"
                allowfullscreen
                referrerpolicy="strict-origin-when-cross-origin"
            ></iframe>
            <button v-else type="button" class="k-b-embed__play" @click="playing = true">
                <KImage v-if="poster" :media="poster" size="large" sizes="(min-width: 74rem) 72rem, 100vw" class="k-b-embed__poster" alt="" />
                <svg class="k-b-embed__icon" viewBox="0 0 64 64" aria-hidden="true">
                    <circle cx="32" cy="32" r="31" />
                    <path d="M26 21l19 11-19 11z" />
                </svg>
                <span class="k-b-embed__label">Play{{ title ? `: ${title}` : ' video' }}</span>
                <span class="k-b-embed__note">Loads from {{ provider }}</span>
            </button>
        </div>
    </figure>
    <p v-else-if="url" class="k-b-embed__fallback">
        <a :href="url" rel="noopener noreferrer">{{ title || url }}</a>
    </p>
    <div v-else-if="canvas" class="k-b-embed__empty">Choose a video file, or paste a YouTube or Vimeo address in the block settings.</div>
</template>

<style scoped>
:where(.k-b-embed) {
    margin-block: 0;
} /* no weight: the spacing between blocks (.k-b-stack > * + *) wins */
.k-b-embed__frame {
    position: relative;
    aspect-ratio: var(--k-video-ratio, 16 / 9);
    border-radius: var(--block-radius, 6px);
    overflow: hidden;
    background: var(--block-inverse-bg, #16181d);
    color: var(--block-inverse-ink, #f6f5f1);
}
.k-b-embed[data-width='full'] .k-b-embed__frame {
    border-radius: 0;
}
.k-b-embed__frame iframe,
.k-b-embed__frame video {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    border: 0;
    object-fit: cover;
    background: #000;
}
.k-b-embed__play {
    position: absolute;
    inset: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 0.6rem;
    width: 100%;
    border: 0;
    background: none;
    color: inherit;
    font: inherit;
    cursor: pointer;
    padding: 1rem;
    text-align: center;
}
.k-b-embed__poster {
    position: absolute;
    inset: 0;
}
.k-b-embed__poster :deep(img),
.k-b-embed__play > :deep(img) {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.k-b-embed__play:has(.k-b-embed__poster)::before {
    content: '';
    position: absolute;
    inset: 0;
    z-index: 1;
    background: linear-gradient(transparent 40%, rgb(0 0 0 / 0.55));
}
.k-b-embed__icon,
.k-b-embed__label,
.k-b-embed__note {
    position: relative;
    z-index: 2;
}
.k-b-embed__icon {
    width: 4rem;
    height: 4rem;
    transition: transform 0.15s;
}
.k-b-embed__icon circle {
    fill: var(--block-accent, #2448c9);
}
.k-b-embed__icon path {
    fill: var(--block-accent-ink, #fff);
}
.k-b-embed__play:hover .k-b-embed__icon {
    transform: scale(1.06);
}
.k-b-embed__play:focus-visible {
    outline: 3px solid var(--block-accent, #2448c9);
    outline-offset: -6px;
}
.k-b-embed__label {
    font-weight: 700;
    font-size: 1.05rem;
}
.k-b-embed__note {
    font-size: 0.8125rem;
    opacity: 0.7;
}
.k-b-embed__empty {
    padding: 2rem 1rem;
    border: 1px dashed currentColor;
    border-radius: 6px;
    opacity: 0.6;
    text-align: center;
    font-size: 0.9rem;
}
@media (prefers-reduced-motion: reduce) {
    .k-b-embed__icon {
        transition: none;
    }
}
</style>
