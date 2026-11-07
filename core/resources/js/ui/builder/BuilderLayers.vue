<script setup>
import { __ } from '@kestello/sdk';
import KIcon from '../KIcon.vue';
import { useBuilder } from './context.js';

/** The layers: the tree as a list, one row per block. A tree for keyboards and screen readers; rows drag. */
const {
    props,
    rows,
    selected,
    pickedIds,
    selectMore,
    hover,
    collapsed,
    defOf,
    iconOf,
    nameOf,
    summary,
    select,
    toggleCollapse,
    rowKey,
    drag,
    drop,
    dragNode,
    overRow,
    overEnd,
    leave,
    finish,
    endDrag,
} = useBuilder();
const hasKids = (node) => (node.children ?? []).length > 0;
const isPicked = (id) => pickedIds.value.includes(id);
/** A click picks one block; with Shift a run of them, with Ctrl or ⌘ one more (or one fewer). */
function pick(event, id) {
    if (event.shiftKey) selectMore(id, 'range');
    else if (event.metaKey || event.ctrlKey) selectMore(id, 'toggle');
    else select(id);
}
</script>

<template>
    <div class="k-pane__scroll">
        <ul v-if="rows.length" class="k-layers" role="tree" :aria-label="__('Layers')" aria-multiselectable="true" aria-describedby="k-layers-help">
            <li v-for="(row, i) in rows" :key="row.node.id" role="none">
                <div
                    :data-row="row.node.id"
                    class="k-layer"
                    role="treeitem"
                    :aria-level="row.depth + 1"
                    :aria-selected="String(isPicked(row.node.id))"
                    :aria-expanded="hasKids(row.node) ? String(!collapsed.has(row.node.id)) : undefined"
                    :tabindex="selected === row.node.id || (!selected && i === 0) ? 0 : -1"
                    :class="{
                        'is-selected': isPicked(row.node.id),
                        'is-hover': hover === row.node.id,
                        'is-missing': !defOf(row.node),
                        'is-dragging': drag?.id === row.node.id,
                        [`drop-${drop?.where}`]: drop?.id === row.node.id,
                    }"
                    :style="{ '--depth': row.depth }"
                    :draggable="!props.disabled"
                    @click="pick($event, row.node.id)"
                    @keydown="rowKey($event, row, i)"
                    @mouseenter="hover = row.node.id"
                    @mouseleave="hover = null"
                    @dragstart="dragNode($event, row.node.id)"
                    @dragover="overRow($event, row)"
                    @dragleave="leave((t) => t?.id === row.node.id)"
                    @drop="finish"
                    @dragend="endDrag"
                >
                    <button
                        v-if="defOf(row.node)?.children"
                        type="button"
                        class="k-layer__twisty"
                        tabindex="-1"
                        :aria-label="collapsed.has(row.node.id) ? __('Expand') : __('Collapse')"
                        @click.stop="toggleCollapse(row.node.id)"
                    >
                        <KIcon :name="collapsed.has(row.node.id) ? 'caret-right' : 'caret-down'" />
                    </button>
                    <span v-else class="k-layer__twisty" aria-hidden="true"></span>
                    <KIcon :name="iconOf(row.node)" class="k-layer__icon" />
                    <span class="k-layer__name">{{ nameOf(row.node) }}</span>
                    <span class="k-layer__summary">{{ summary(row.node) }}</span>
                    <KIcon
                        v-if="row.node.visibility?.hide?.length"
                        name="eye-slash"
                        class="k-layer__flag"
                        :title="__('Hidden on {screens}', { screens: row.node.visibility.hide.join(', ') })"
                    />
                    <KIcon v-if="row.node.lock?.length" name="lock" class="k-layer__flag" :title="__('Locked for editors')" />
                </div>
            </li>
        </ul>
        <p v-else class="k-pane__empty">{{ __('Nothing here yet. Press + to add a block, or drag one here.') }}</p>
        <div class="k-layers__end" :class="{ 'is-target': drop?.end }" @dragover="overEnd" @dragleave="leave((t) => t?.end)" @drop="finish">
            {{ drag ? __('Drop here to add at the end') : '' }}
        </div>
        <p id="k-layers-help" class="k-pane__help">
            {{
                __(
                    'Arrow keys move between blocks; Alt with arrows moves the block; Shift with arrows, or Shift or Ctrl with a click, selects several. Press ? for every shortcut.',
                )
            }}
        </p>
    </div>
</template>
