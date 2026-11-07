<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { __ } from '@kestello/sdk';
import KButton from '../KButton.vue';
import KIcon from '../KIcon.vue';
import { useBuilder } from './context.js';

/**
 * The toolbar over the selected block (or docked in the bar): its kind and what
 * it can turn into, moving, heading level, alignment, text formats, and more.
 * Its buttons keep the caret in the page.
 */
const b = useBuilder();
const {
    props,
    selected,
    selectedAt,
    selectedDef,
    select,
    nameOf,
    iconOf,
    toolbarDocked: docked,
    dockEl,
    toolbarStyle,
    toolbarSize,
    menu,
    toggleMenu,
    closeMenu,
    turnChoices,
    turnInto,
    shift,
    editLocked,
    setProp,
    richSelected,
    typingRich,
    formats,
    format,
    linkOpen,
    linkUrl,
    applyLink,
    openQuick,
    duplicateNode,
    copyNode,
    paste,
    copyStyles,
    pasteStyles,
    canGroup,
    canUngroup,
    groupNode,
    ungroupNode,
    removeNode,
    showRight,
    dragNode,
    endDrag,
    locked,
    K,
    multi,
    pickedIds,
    canGroupPicked,
    groupPicked,
    duplicatePicked,
    removePicked,
} = b;

const HEADING_ICONS = ['text-h-one', 'text-h-two', 'text-h-three', 'text-h-four', 'text-h-five', 'text-h-six'];
const ALIGNS = [
    ['start', 'text-align-left', __('Align left')],
    ['center', 'text-align-center', __('Align centre')],
    ['end', 'text-align-right', __('Align right')],
];
const MORE_FORMATS = [
    ['strike', 'text-strikethrough', __('Strikethrough'), 'Alt+Shift+D'],
    ['code', 'code', __('Inline code'), 'Alt+Shift+X'],
    ['mark', 'highlighter', __('Highlight'), ''],
    ['sub', 'text-subscript', __('Subscript'), ''],
    ['sup', 'text-superscript', __('Superscript'), ''],
];

const node = computed(() => selectedAt.value?.node);
const shown = computed(() => !!node.value && !!selectedDef.value && !props.disabled && (docked.value || !!toolbarStyle.value));
const isHeading = computed(() => node.value?.type === 'core/heading');
const level = computed(() => String(node.value?.props?.level ?? '2'));
const hasAlign = computed(
    () =>
        selectedDef.value?.fields.some((f) => f.name === 'align' && f.type === 'select' && f.options?.choices?.some((c) => c.value === 'center')) &&
        ['core/text', 'core/heading', 'core/quote'].includes(node.value?.type),
);
const align = computed(() => ALIGNS.find((a) => a[0] === (node.value?.props?.align ?? 'start')) ?? ALIGNS[0]);
const movable = computed(() => !locked(node.value, 'move'));

/** A menu item: do it, then close the menu. */
const act = (fn) => {
    fn();
    closeMenu();
};
function runFormat(command) {
    format(command);
    if (command !== 'link') closeMenu();
}
/** Toolbar buttons keep the caret in the page; the link field and the grip still take the pointer. */
// the toolbar's size, so it can be placed where it covers the least (toolbar-spot.js)
const bar = ref(null);
let observer = null;
watch(bar, (el) => {
    observer?.disconnect();
    if (!el || typeof ResizeObserver === 'undefined') return;
    observer = new ResizeObserver(() => {
        if (docked.value) return;
        const next = { width: el.offsetWidth, height: el.offsetHeight };
        const was = toolbarSize.value;
        if (next.width && (next.width !== was.width || next.height !== was.height)) toolbarSize.value = next;
    });
    observer.observe(el);
});
onBeforeUnmount(() => observer?.disconnect());

function keepCaret(event) {
    if (!(event.target instanceof HTMLInputElement) && !event.target.closest?.('[draggable="true"]')) event.preventDefault();
}
</script>

<template>
    <Teleport :to="dockEl || 'body'" :disabled="!docked || !dockEl">
        <div
            v-if="shown"
            ref="bar"
            class="k-builder__toolbar"
            :class="{ 'is-docked': docked }"
            :style="docked ? null : toolbarStyle"
            role="toolbar"
            :aria-label="multi ? __('{n} blocks', { n: pickedIds.length }) : __('{block} block', { block: nameOf(node) })"
            @mousedown="keepCaret"
        >
            <template v-if="multi">
                <span class="k-tb__count">{{ __('{n} blocks', { n: pickedIds.length }) }}</span>
                <span class="k-tb__sep" aria-hidden="true"></span>
                <button v-if="canGroupPicked" type="button" class="k-tb" :title="__('Put in a group')" :aria-label="__('Put in a group')" @click="groupPicked">
                    <KIcon name="selection-plus" />
                </button>
                <button type="button" class="k-tb" :title="`${__('Duplicate')} (${K('Mod+Shift+D')})`" :aria-label="__('Duplicate')" @click="duplicatePicked">
                    <KIcon name="copy" />
                </button>
                <button
                    type="button"
                    class="k-tb"
                    :title="`${__('Paste styles')} (${K('Mod+Alt+V')})`"
                    :aria-label="__('Paste styles')"
                    @click="pasteStyles(pickedIds)"
                >
                    <KIcon name="paint-roller" />
                </button>
                <button type="button" class="k-tb" :title="`${__('Remove')} (${K('Alt+Shift+Z')})`" :aria-label="__('Remove')" @click="removePicked">
                    <KIcon name="trash" />
                </button>
            </template>
            <template v-else>
                <button
                    v-if="selectedAt.parent"
                    type="button"
                    class="k-tb"
                    :title="__('Select {block}', { block: nameOf(selectedAt.parent) })"
                    @click="select(selectedAt.parent.id)"
                >
                    <KIcon name="arrow-elbow-left-up" />
                </button>
                <button
                    v-if="movable"
                    type="button"
                    class="k-tb k-tb--grip"
                    draggable="true"
                    :title="__('Drag to move. Drop at a block’s side to put them side by side.')"
                    :aria-label="__('Drag to move')"
                    @dragstart="dragNode($event, selected)"
                    @dragend="endDrag"
                >
                    <KIcon name="dots-six-vertical" />
                </button>
                <div class="k-menu-wrap">
                    <button
                        type="button"
                        class="k-tb k-tb--type"
                        :disabled="!turnChoices.length"
                        aria-haspopup="menu"
                        :aria-expanded="String(menu === 'turn')"
                        :title="turnChoices.length ? __('Turn into another block') : selectedDef.label"
                        @click="toggleMenu('turn')"
                    >
                        <KIcon :name="iconOf(node)" /><span class="k-tb__label">{{ selectedDef.label }}</span
                        ><KIcon v-if="turnChoices.length" name="caret-down" class="k-tb__caret" />
                    </button>
                    <div v-if="menu === 'turn'" class="k-menu" role="menu">
                        <p class="k-menu__head">{{ __('Turn into') }}</p>
                        <button
                            v-for="c in turnChoices"
                            :key="`${c.type}:${c.variant}`"
                            type="button"
                            class="k-menu__item"
                            role="menuitem"
                            @click="act(() => turnInto(c))"
                        >
                            <KIcon :name="c.icon" />{{ c.label }}
                        </button>
                    </div>
                </div>
                <span v-if="movable" class="k-tb__pair">
                    <button
                        type="button"
                        class="k-tb k-tb--move"
                        :disabled="selectedAt.index === 0"
                        :title="__('Move up (Alt+Up in layers)')"
                        :aria-label="__('Move up')"
                        @click="shift(selected, -1)"
                    >
                        <KIcon name="caret-up" />
                    </button>
                    <button
                        type="button"
                        class="k-tb k-tb--move"
                        :disabled="selectedAt.index === selectedAt.list.length - 1"
                        :title="__('Move down (Alt+Down in layers)')"
                        :aria-label="__('Move down')"
                        @click="shift(selected, 1)"
                    >
                        <KIcon name="caret-down" />
                    </button>
                </span>

                <template v-if="isHeading || hasAlign">
                    <span class="k-tb__sep" aria-hidden="true"></span>
                    <div v-if="isHeading" class="k-menu-wrap">
                        <button
                            type="button"
                            class="k-tb k-tb--text"
                            :disabled="editLocked"
                            aria-haspopup="menu"
                            :aria-expanded="String(menu === 'level')"
                            :title="__('Heading level')"
                            @click="toggleMenu('level')"
                        >
                            H{{ level }}<KIcon name="caret-down" class="k-tb__caret" />
                        </button>
                        <div v-if="menu === 'level'" class="k-menu" role="menu">
                            <button
                                v-for="(icon, i) in HEADING_ICONS"
                                :key="i"
                                type="button"
                                class="k-menu__item"
                                role="menuitemradio"
                                :aria-checked="String(level === String(i + 1))"
                                @click="act(() => setProp('level', String(i + 1)))"
                            >
                                <KIcon :name="icon" />{{ __('Heading {n}', { n: i + 1 })
                                }}<span v-if="i === 0" class="k-menu__hint">{{ __('page title') }}</span>
                            </button>
                        </div>
                    </div>
                    <div v-if="hasAlign" class="k-menu-wrap">
                        <button
                            type="button"
                            class="k-tb"
                            :disabled="editLocked"
                            aria-haspopup="menu"
                            :aria-expanded="String(menu === 'align')"
                            :title="__('Alignment')"
                            @click="toggleMenu('align')"
                        >
                            <KIcon :name="align[1]" />
                        </button>
                        <div v-if="menu === 'align'" class="k-menu" role="menu">
                            <button
                                v-for="[v, icon, label] in ALIGNS"
                                :key="v"
                                type="button"
                                class="k-menu__item"
                                role="menuitemradio"
                                :aria-checked="String(align[0] === v)"
                                @click="act(() => setProp('align', v))"
                            >
                                <KIcon :name="icon" />{{ label }}
                            </button>
                        </div>
                    </div>
                </template>

                <template v-if="richSelected">
                    <span class="k-tb__sep" aria-hidden="true"></span>
                    <!-- formatting works on text being typed: until then the buttons say why they are off -->
                    <span id="k-tb-format-why" class="k-sr-only">{{ __('Click into the text to format it.') }}</span>
                    <button
                        type="button"
                        class="k-tb"
                        :class="{ 'is-on': formats.bold }"
                        :aria-pressed="String(!!formats.bold)"
                        :disabled="!typingRich"
                        :aria-describedby="typingRich ? undefined : 'k-tb-format-why'"
                        :title="`${__('Bold')} (${K('Mod+B')})`"
                        :aria-label="__('Bold')"
                        @click="runFormat('bold')"
                    >
                        <KIcon name="text-b" />
                    </button>
                    <button
                        type="button"
                        class="k-tb"
                        :class="{ 'is-on': formats.italic }"
                        :aria-pressed="String(!!formats.italic)"
                        :disabled="!typingRich"
                        :aria-describedby="typingRich ? undefined : 'k-tb-format-why'"
                        :title="`${__('Italic')} (${K('Mod+I')})`"
                        :aria-label="__('Italic')"
                        @click="runFormat('italic')"
                    >
                        <KIcon name="text-italic" />
                    </button>
                    <button
                        type="button"
                        class="k-tb"
                        :class="{ 'is-on': formats.link || linkOpen }"
                        :disabled="!typingRich"
                        :aria-describedby="typingRich ? undefined : 'k-tb-format-why'"
                        :title="formats.link ? __('Remove link') : `${__('Link')} (${K('Mod+K')})`"
                        :aria-label="formats.link ? __('Remove link') : __('Link')"
                        :aria-expanded="String(linkOpen)"
                        @click="formats.link ? runFormat('unlink') : (linkOpen = !linkOpen)"
                    >
                        <KIcon :name="formats.link ? 'link-break' : 'link'" />
                    </button>
                    <div class="k-menu-wrap">
                        <button
                            type="button"
                            class="k-tb k-tb--narrow"
                            :disabled="!typingRich"
                            :aria-describedby="typingRich ? undefined : 'k-tb-format-why'"
                            aria-haspopup="menu"
                            :aria-expanded="String(menu === 'format')"
                            :title="__('More formatting')"
                            @click="toggleMenu('format')"
                        >
                            <KIcon name="caret-down" />
                        </button>
                        <div v-if="menu === 'format'" class="k-menu" role="menu">
                            <button
                                v-for="[cmd, icon, label, keys] in MORE_FORMATS"
                                :key="cmd"
                                type="button"
                                class="k-menu__item"
                                role="menuitemcheckbox"
                                :aria-checked="String(!!formats[cmd])"
                                :class="{ 'is-on': formats[cmd] }"
                                @click="runFormat(cmd)"
                            >
                                <KIcon :name="icon" />{{ label }}<kbd v-if="keys">{{ K(keys) }}</kbd>
                            </button>
                            <span class="k-menu__sep" role="separator"></span>
                            <button type="button" class="k-menu__item" role="menuitem" @click="runFormat('clear')">
                                <KIcon name="eraser" />{{ __('Clear formatting') }}
                            </button>
                        </div>
                    </div>
                </template>

                <span class="k-tb__sep" aria-hidden="true"></span>
                <button
                    type="button"
                    class="k-tb"
                    :title="`${__('Add a block after')} (${K('Mod+Alt+Y')})`"
                    :aria-label="__('Add a block after')"
                    @click="openQuick({ mode: 'after', id: selected })"
                >
                    <KIcon name="plus" />
                </button>
                <div class="k-menu-wrap">
                    <button
                        type="button"
                        class="k-tb"
                        aria-haspopup="menu"
                        :aria-expanded="String(menu === 'options')"
                        :title="__('More')"
                        :aria-label="__('More')"
                        @click="toggleMenu('options')"
                    >
                        <KIcon name="dots-three-vertical" />
                    </button>
                    <div v-if="menu === 'options'" class="k-menu k-menu--end" role="menu">
                        <button type="button" class="k-menu__item" role="menuitem" @click="act(() => openQuick({ mode: 'before', id: selected }))">
                            <KIcon name="arrow-line-up" />{{ __('Add before') }}<kbd>{{ K('Mod+Alt+T') }}</kbd>
                        </button>
                        <button type="button" class="k-menu__item" role="menuitem" @click="act(() => openQuick({ mode: 'after', id: selected }))">
                            <KIcon name="arrow-line-down" />{{ __('Add after') }}<kbd>{{ K('Mod+Alt+Y') }}</kbd>
                        </button>
                        <span class="k-menu__sep" role="separator"></span>
                        <button type="button" class="k-menu__item" role="menuitem" @click="act(() => duplicateNode(selected))">
                            <KIcon name="copy" />{{ __('Duplicate') }}<kbd>{{ K('Mod+Shift+D') }}</kbd>
                        </button>
                        <button type="button" class="k-menu__item" role="menuitem" @click="act(() => copyNode(selected))">
                            <KIcon name="clipboard" />{{ __('Copy') }}
                        </button>
                        <button type="button" class="k-menu__item" role="menuitem" @click="act(paste)">
                            <KIcon name="clipboard-text" />{{ __('Paste after') }}
                        </button>
                        <button type="button" class="k-menu__item" role="menuitem" @click="act(() => copyStyles(selected))">
                            <KIcon name="paint-brush" />{{ __('Copy styles') }}<kbd>{{ K('Mod+Alt+C') }}</kbd>
                        </button>
                        <button type="button" class="k-menu__item" role="menuitem" @click="act(() => pasteStyles(selected))">
                            <KIcon name="paint-roller" />{{ __('Paste styles') }}<kbd>{{ K('Mod+Alt+V') }}</kbd>
                        </button>
                        <button v-if="canGroup" type="button" class="k-menu__item" role="menuitem" @click="act(() => groupNode(selected))">
                            <KIcon name="selection-plus" />{{ __('Put in a group') }}
                        </button>
                        <button v-if="canUngroup" type="button" class="k-menu__item" role="menuitem" @click="act(() => ungroupNode(selected))">
                            <KIcon name="selection-slash" />{{ __('Ungroup') }}
                        </button>
                        <button type="button" class="k-menu__item" role="menuitem" @click="act(() => showRight('block'))">
                            <KIcon name="sliders-horizontal" />{{ __('Block settings') }}
                        </button>
                        <template v-if="!locked(node, 'remove')">
                            <span class="k-menu__sep" role="separator"></span>
                            <button type="button" class="k-menu__item k-menu__item--danger" role="menuitem" @click="act(() => removeNode(selected))">
                                <KIcon name="trash" />{{ __('Remove') }}<kbd>{{ K('Alt+Shift+Z') }}</kbd>
                            </button>
                        </template>
                    </div>
                </div>
                <form v-if="linkOpen" class="k-builder__link" @submit.prevent="applyLink">
                    <label class="k-builder__link-label" for="k-builder-link">{{ __('Link address') }}</label>
                    <input
                        id="k-builder-link"
                        v-model="linkUrl"
                        class="k-input"
                        type="text"
                        inputmode="url"
                        autocomplete="off"
                        aria-describedby="k-builder-link-help"
                        @keydown.esc.prevent="linkOpen = false"
                    />
                    <KButton size="sm" type="submit" variant="primary">{{ __('Link') }}</KButton>
                    <p id="k-builder-link-help" class="k-builder__link-help">{{ __('A web address, or a page of this site such as /about.') }}</p>
                </form>
            </template>
        </div>
    </Teleport>
</template>
