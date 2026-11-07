<script setup>
import { computed } from 'vue';
import { __ } from '@kestello/sdk';
import KBlockSettings from '../KBlockSettings.vue';
import KButton from '../KButton.vue';
import KIcon from '../KIcon.vue';
import KTabs from '../KTabs.vue';
import { useBuilder } from './context.js';
import BuilderDesign from './BuilderDesign.vue';

/** The right panel: the selected block's settings and styles, the document's own (slot #document), and the checks. */
const {
    props,
    right,
    resize,
    resizeKey,
    rightTab,
    selected,
    selectedAt,
    selectedDef,
    select,
    multi,
    pickedNodes,
    nameOf,
    iconOf,
    canGroupPicked,
    groupPicked,
    duplicatePicked,
    removePicked,
    bindings,
    bindingLabel,
    patchSelected,
    editable,
    issues,
    runChecks,
} = useBuilder();

const tabs = computed(() => [
    { id: 'block', label: __('Block') },
    { id: 'style', label: __('Style') },
    { id: 'document', label: props.documentLabel || { template: __('Template'), part: __('Part'), pattern: __('Pattern'), entry: __('Page') }[props.kind] },
    { id: 'checks', label: __('Checks'), count: issues.value.length },
]);
const inline = computed(() => Object.keys(editable.value[selected.value] ?? {}));
</script>

<template>
    <aside v-show="right" class="k-builder__right" :aria-label="__('Settings')">
        <div
            class="k-resize k-resize--x"
            role="separator"
            aria-orientation="vertical"
            tabindex="0"
            :aria-label="__('Resize the settings panel')"
            @pointerdown="resize($event, 'right')"
            @keydown="resizeKey($event, 'right')"
        ></div>
        <header class="k-pane__head k-pane__head--tabs">
            <KTabs v-model="rightTab" :tabs="tabs" :label="__('Settings')" />
            <button type="button" class="k-bb k-bb--sm" :title="__('Close')" @click="right = false">
                <KIcon name="x" /><span class="k-sr-only">{{ __('Close') }}</span>
            </button>
        </header>
        <div class="k-pane__scroll k-builder__inspector">
            <template v-if="rightTab === 'block'">
                <div v-if="multi" class="k-multi">
                    <p class="k-multi__title">{{ __('{n} blocks selected', { n: pickedNodes.length }) }}</p>
                    <ul class="k-multi__list">
                        <li v-for="n in pickedNodes" :key="n.id"><KIcon :name="iconOf(n)" />{{ nameOf(n) }}</li>
                    </ul>
                    <div v-if="!props.disabled" class="k-multi__actions">
                        <KButton v-if="canGroupPicked" size="sm" icon="selection-plus" @click="groupPicked">{{ __('Put in a group') }}</KButton>
                        <KButton size="sm" icon="copy" @click="duplicatePicked">{{ __('Duplicate') }}</KButton>
                        <KButton size="sm" variant="danger" icon="trash" @click="removePicked">{{ __('Remove') }}</KButton>
                    </div>
                    <p class="k-pane__help">{{ __('Settings are changed one block at a time. Press Esc to go back to one block.') }}</p>
                </div>
                <template v-else-if="selectedAt && selectedDef">
                    <div class="k-blockcard">
                        <KIcon :name="iconOf(selectedAt.node)" class="k-blockcard__icon" />
                        <div>
                            <p class="k-blockcard__name">{{ selectedDef.label }}</p>
                            <p v-if="selectedDef.description" class="k-blockcard__about">{{ selectedDef.description }}</p>
                        </div>
                    </div>
                    <KBlockSettings
                        :key="selected"
                        :node="selectedAt.node"
                        :def="selectedDef"
                        :bindings="bindings"
                        :binding-label="bindingLabel"
                        :disabled="props.disabled"
                        :lockable="props.kind !== 'entry'"
                        hide-about
                        :inline="inline"
                        @update="patchSelected"
                    />
                </template>
                <p v-else class="k-pane__empty">{{ __('Select a block on the page or in the layers to change it.') }}</p>
            </template>
            <template v-if="rightTab === 'style'">
                <p v-if="multi" class="k-pane__empty">
                    {{
                        __(
                            'Styles are set one block at a time. To give several blocks the same look, style one, choose Copy styles in its menu, select the others and choose Paste styles.',
                        )
                    }}
                </p>
                <BuilderDesign v-else-if="selectedAt && selectedDef" :key="selected" />
                <p v-else class="k-pane__empty">{{ __('Select a block on the page or in the layers to style it.') }}</p>
            </template>
            <div v-show="rightTab === 'document'" class="k-builder__doc"><slot name="document" /></div>
            <template v-if="rightTab === 'checks'">
                <p class="k-pane__help">{{ __('Checked on the preview as it is now, including the theme around it.') }}</p>
                <p v-if="!issues.length" class="k-builder__ok"><KIcon name="check-circle" />{{ __('No problems found.') }}</p>
                <ul v-else class="k-issues">
                    <li v-for="(issue, i) in issues" :key="i" :class="`is-${issue.tone}`">
                        <KIcon :name="issue.tone === 'danger' ? 'x-circle' : 'warning'" />
                        <span>{{ issue.message }}</span>
                        <button v-if="issue.id" type="button" class="k-builder__crumb" @click="select(issue.id, { focus: true, scroll: true })">
                            {{ __('Show') }}
                        </button>
                    </li>
                </ul>
                <KButton size="sm" variant="ghost" icon="arrows-clockwise" @click="runChecks">{{ __('Check again') }}</KButton>
            </template>
        </div>
    </aside>
</template>
