<script setup>
import { computed } from 'vue';
import { __ } from '@kestello/sdk';
import KButton from '../KButton.vue';
import { useBuilder } from './context.js';
import BuilderQuickInsert from './BuilderQuickInsert.vue';
import BuilderToolbar from './BuilderToolbar.vue';

/** The real page in a frame, at the chosen width, with the block toolbar and the quick inserter over it. */
const { props, canvasEl, frame, device, DEVICES, width, canvasUrl, ready, list, usablePatterns, startWriting, openQuick, quick, openPatterns } = useBuilder();
const hasLayouts = computed(() => usablePatterns.value.some((p) => p.layout));
</script>

<template>
    <section ref="canvasEl" class="k-builder__canvas" :class="`is-${device}`" :aria-label="__('Preview')">
        <p v-if="width" class="k-builder__width">{{ __('{device}, {n} px wide', { device: DEVICES.find((d) => d[0] === device)?.[2] ?? '', n: width }) }}</p>
        <div class="k-builder__frame" :style="{ width: width ? `${width}px` : '100%' }">
            <iframe
                ref="frame"
                :src="canvasUrl"
                :title="props.kind === 'entry' ? __('The page as visitors see it, with text you can edit') : __('Preview of {name}', { name: props.docKey })"
                class="k-builder__iframe"
            ></iframe>
        </div>
        <div v-if="props.kind === 'entry' && ready && !list.length && !props.disabled" class="k-builder__start-here">
            <p>{{ __('An empty page. Start with text, or pick what comes first.') }}</p>
            <div>
                <KButton variant="primary" icon="text-align-left" @click="startWriting">{{ __('Start writing') }}</KButton>
                <KButton icon="plus" @click="openQuick({ mode: 'after', id: null })">{{ __('Add a block') }}</KButton>
                <KButton v-if="hasLayouts" variant="ghost" icon="layout" @click="openPatterns('layouts')">{{ __('Start from a layout') }}</KButton>
                <KButton v-else-if="usablePatterns.length" variant="ghost" icon="bookmark" @click="openPatterns()">{{ __('Start from a pattern') }}</KButton>
            </div>
        </div>
        <BuilderToolbar />
        <BuilderQuickInsert v-if="quick.open" />
    </section>
</template>
