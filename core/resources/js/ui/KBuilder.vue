<script setup>
import { computed, ref } from 'vue';
import { __ } from '@kestello/sdk';
import KButton from './KButton.vue';
import KIcon from './KIcon.vue';
import KSpinner from './KSpinner.vue';
import { useBuilderState } from './builder/useBuilderState.js';
import BuilderBar from './builder/BuilderBar.vue';
import BuilderCanvas from './builder/BuilderCanvas.vue';
import BuilderInspector from './builder/BuilderInspector.vue';
import BuilderFind from './builder/BuilderFind.vue';
import BuilderLayers from './builder/BuilderLayers.vue';
import BuilderLibrary from './builder/BuilderLibrary.vue';
import BuilderPatterns from './builder/BuilderPatterns.vue';
import BuilderShortcuts from './builder/BuilderShortcuts.vue';

/**
 * The visual editor: block library and layers on the left, the real site in a
 * frame in the middle, the selected block's settings on the right. Text is
 * typed straight into the page; a toolbar follows the selected block, and /
 * opens a quick inserter. Everything works from the keyboard as well as by
 * dragging. The parts and their state are in builder/ (context.js explains how
 * they fit together).
 *
 *   <KBuilder v-model="blocks" kind="template" doc-key="entry-post" :reset-key="revision">
 *       <template #document>…settings of the template itself…</template>
 *   </KBuilder>
 *
 * For an entry's blocks field (kind="entry"), the page passes where the canvas
 * lives and how a tree is prepared for it:
 *
 *   <KBuilder v-model="form.data.body" kind="entry" doc-key="body" :canvas-src="url" :previewer="prepare" :canvas-data="{ title }" />
 *
 * Slots: #start and #end (the page's own controls in the bar), #document (the
 * document's settings, a tab of the right panel), #below (a drawer under the canvas).
 */
const tree = defineModel({ type: Array, default: () => [] });
const props = defineProps({
    kind: { type: String, required: true }, // template | part | pattern | entry
    docKey: { type: String, required: true },
    resetKey: { type: [String, Number], default: '' }, // a new value starts a fresh undo history
    disabled: Boolean,
    errors: { type: Object, default: () => ({}) },
    canvasSrc: { type: String, default: '' }, // the canvas page; templates, parts and patterns have their own
    previewer: { type: Function, default: null }, // async (blocks) => prepared blocks
    canvasData: { type: Object, default: () => ({}) }, // shown on the canvas with the tree (an entry's unsaved title)
    allowed: { type: Array, default: null }, // blocks allowed at the top level
    documentLabel: { type: String, default: '' },
    startTab: { type: String, default: 'document' }, // the settings tab shown first: document | block (a new page opens on writing)
    belowLabel: { type: String, default: '' }, // the drawer under the canvas (slot #below)
    extraIssues: { type: Array, default: () => [] }, // the document's own checks, shown with the page's: { tone, id: null, message }
});
const emit = defineEmits(['save', 'ready', 'choose-template', 'title']); // title: the entry's title was clicked on the canvas
const root = ref(null);
const b = useBuilderState({ tree, props, emit, root });
const {
    bodyHidden,
    loading,
    failed,
    loadCatalog,
    blockErrors,
    left,
    right,
    rightWidth,
    belowOpen,
    belowHeight,
    resize,
    resizeKey,
    trail,
    selected,
    select,
    rightTab,
    nameOf,
    stats,
    live,
    globalKey,
    onMenuKey,
} = b;
const paneTitle = computed(() => ({ add: __('Add a block'), find: __('Find and replace') })[left.value] ?? __('Layers'));

defineExpose({ undo: b.undo, redo: b.redo, canUndo: b.canUndo, canRedo: b.canRedo, select: b.select });
</script>

<template>
    <div
        ref="root"
        class="k-builder"
        :class="{ 'has-left': !!left, 'has-right': right }"
        :style="{ '--k-right-w': `${rightWidth}px` }"
        @keydown="globalKey"
        @keydown.capture="onMenuKey"
    >
        <BuilderBar>
            <template v-if="$slots.start" #start><slot name="start" /></template>
            <template v-if="$slots.end" #end><slot name="end" /></template>
        </BuilderBar>

        <div v-if="blockErrors.length" class="k-builder__errors" role="alert">
            <KIcon name="warning-circle" />
            <ul>
                <li v-for="(e, i) in blockErrors" :key="i">
                    <button v-if="e.id" type="button" class="k-builder__error-link" @click="select(e.id, { focus: true, scroll: true })">{{ e.name }}</button
                    ><span v-if="e.id">: </span>{{ e.message }}
                </li>
            </ul>
        </div>
        <div v-if="bodyHidden && kind === 'entry'" class="k-builder__notice" role="status">
            <KIcon name="info" />
            <p>
                {{
                    __(
                        "This page's template has no Entry content block, so these blocks go after everything in the template, for visitors too. Add Entry content to the template to choose where they go.",
                    )
                }}
            </p>
            <KButton
                size="sm"
                variant="ghost"
                @click="
                    rightTab = 'document';
                    right = true;
                    emit('choose-template');
                "
                >{{ __('Choose another template') }}</KButton
            >
        </div>
        <p v-if="loading" class="k-builder__state"><KSpinner />{{ __('Loading blocks…') }}</p>
        <p v-else-if="failed" class="k-builder__state">
            {{ __('The block list could not be loaded.') }} <KButton size="sm" variant="ghost" @click="loadCatalog">{{ __('Try again') }}</KButton>
        </p>

        <div v-else class="k-builder__panes">
            <aside v-show="left" class="k-builder__left" :aria-label="paneTitle">
                <header class="k-pane__head">
                    <h2 class="k-pane__title">{{ paneTitle }}</h2>
                    <span v-if="left === 'layers'" class="k-pane__count">{{ b.rows.value.length }}</span>
                    <button type="button" class="k-bb k-bb--sm" :title="__('Close')" @click="left = ''">
                        <KIcon name="x" /><span class="k-sr-only">{{ __('Close') }}</span>
                    </button>
                </header>
                <BuilderLayers v-show="left === 'layers'" />
                <BuilderLibrary v-show="left === 'add'" />
                <BuilderFind v-if="left === 'find'" />
            </aside>

            <div class="k-builder__middle">
                <BuilderCanvas />

                <section v-if="$slots.below" class="k-builder__below" :class="{ 'is-open': belowOpen }" :aria-label="belowLabel || __('More fields')">
                    <div
                        v-if="belowOpen"
                        class="k-resize k-resize--y"
                        role="separator"
                        aria-orientation="horizontal"
                        tabindex="0"
                        :aria-label="__('Resize')"
                        @pointerdown="resize($event, 'below')"
                        @keydown="resizeKey($event, 'below')"
                    ></div>
                    <button type="button" class="k-builder__below-head" :aria-expanded="String(belowOpen)" @click="belowOpen = !belowOpen">
                        <KIcon :name="belowOpen ? 'caret-down' : 'caret-up'" />{{ belowLabel || __('More fields') }}
                    </button>
                    <div v-show="belowOpen" class="k-builder__below-body" :style="{ height: `${belowHeight}px` }"><slot name="below" /></div>
                </section>

                <footer class="k-builder__foot">
                    <nav class="k-builder__trail" :aria-label="__('Where this block is')">
                        <button
                            type="button"
                            class="k-builder__crumb"
                            @click="
                                select(null);
                                rightTab = 'document';
                            "
                        >
                            {{ documentLabel || __('Page') }}
                        </button>
                        <template v-for="n in trail" :key="n.id">
                            <KIcon name="caret-right" class="k-builder__crumb-sep" />
                            <button
                                type="button"
                                class="k-builder__crumb"
                                :aria-current="n.id === selected ? 'true' : undefined"
                                @click="select(n.id, { scroll: true })"
                            >
                                {{ nameOf(n) }}
                            </button>
                        </template>
                    </nav>
                    <span class="k-builder__count">{{ __('{n} words', { n: stats.words }) }}</span>
                </footer>
            </div>

            <BuilderInspector>
                <template #document><slot name="document" /></template>
            </BuilderInspector>
        </div>

        <BuilderShortcuts />
        <BuilderPatterns />
        <div class="k-sr-only" aria-live="polite" role="status">{{ live }}</div>
    </div>
</template>

<style src="./builder/builder.css"></style>
