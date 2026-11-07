<script setup>
import { __ } from '@kestello/sdk';
import KIcon from '../KIcon.vue';
import KSelect from '../KSelect.vue';
import KSpinner from '../KSpinner.vue';
import { useBuilder } from './context.js';
import BuilderDetails from './BuilderDetails.vue';

/** The editor's one bar: the page's controls (slots) around the editor's own, and a place the block toolbar can dock. */
const b = useBuilder();
const {
    props,
    left,
    right,
    toggleLeft,
    openFind,
    canUndo,
    canRedo,
    undo,
    redo,
    menu,
    toggleMenu,
    closeMenu,
    docked,
    focusMode,
    toggleFocus,
    showRight,
    issues,
    updating,
    ready,
    entry,
    entryOptions,
    DEVICES,
    device,
    K,
} = b;
const dockEl = b.dockEl;
const disabled = () => props.disabled;
</script>

<template>
    <div class="k-builder__bar">
        <div v-if="$slots.start" class="k-builder__start"><slot name="start" /></div>
        <div class="k-builder__group k-builder__group--editor" role="group" :aria-label="__('Editor')">
            <button
                type="button"
                class="k-bb k-bb--add"
                :class="{ 'is-on': left === 'add' }"
                :aria-pressed="String(left === 'add')"
                :title="__('Add a block')"
                :disabled="disabled()"
                @click="toggleLeft('add')"
            >
                <KIcon name="plus" /><span class="k-sr-only">{{ __('Add a block') }}</span>
            </button>
            <button
                type="button"
                class="k-bb"
                :class="{ 'is-on': left === 'layers' }"
                :aria-pressed="String(left === 'layers')"
                :title="`${__('Layers')} (${K('Alt+Shift+O')})`"
                @click="toggleLeft('layers')"
            >
                <KIcon name="list-dashes" /><span class="k-sr-only">{{ __('Layers') }}</span>
            </button>
            <button
                type="button"
                class="k-bb"
                :class="{ 'is-on': left === 'find' }"
                :aria-pressed="String(left === 'find')"
                :title="`${__('Find and replace')} (${K('Mod+Shift+F')})`"
                @click="left === 'find' ? (left = '') : openFind()"
            >
                <KIcon name="magnifying-glass" /><span class="k-sr-only">{{ __('Find and replace') }}</span>
            </button>
            <button type="button" class="k-bb" :disabled="!canUndo || disabled()" :title="`${__('Undo')} (${K('Mod+Z')})`" @click="undo">
                <KIcon name="arrow-counter-clockwise" /><span class="k-sr-only">{{ __('Undo') }}</span>
            </button>
            <button type="button" class="k-bb" :disabled="!canRedo || disabled()" :title="`${__('Redo')} (${K('Mod+Shift+Z')})`" @click="redo">
                <KIcon name="arrow-clockwise" /><span class="k-sr-only">{{ __('Redo') }}</span>
            </button>
            <BuilderDetails />
        </div>

        <!-- the block toolbar docks here when it is fixed to the top -->
        <div ref="dockEl" class="k-builder__dock"></div>

        <div class="k-builder__group">
            <span class="k-builder__status" aria-hidden="true"><KSpinner v-if="updating || !ready" size="sm" />{{ !ready ? __('Loading…') : '' }}</span>
            <div v-if="props.kind === 'template'" class="k-builder__entry">
                <label class="k-sr-only" for="k-builder-entry">{{ __('Preview with') }}</label>
                <KSelect v-if="entryOptions.length" id="k-builder-entry" v-model="entry" :options="entryOptions" :title="__('Preview with')" />
            </div>
            <div class="k-seg" role="group" :aria-label="__('Preview width')">
                <button
                    v-for="[id, icon, label] in DEVICES"
                    :key="id"
                    type="button"
                    class="k-seg__btn"
                    :class="{ 'is-on': device === id }"
                    :aria-pressed="String(device === id)"
                    :title="label"
                    @click="device = id"
                >
                    <KIcon :name="icon" /><span class="k-sr-only">{{ label }}</span>
                </button>
            </div>
        </div>
        <div v-if="$slots.end" class="k-builder__end"><slot name="end" /></div>
        <div class="k-builder__group">
            <button
                type="button"
                class="k-bb"
                :class="{ 'is-on': right }"
                :aria-pressed="String(right)"
                :title="`${__('Settings')} (${K('Mod+Shift+,')})`"
                @click="right = !right"
            >
                <KIcon name="sidebar-simple" class="k-flip" /><span class="k-sr-only">{{ __('Settings') }}</span>
            </button>
            <div class="k-menu-wrap">
                <button
                    type="button"
                    class="k-bb"
                    :class="{ 'is-on': menu === 'more' }"
                    :aria-expanded="String(menu === 'more')"
                    aria-haspopup="menu"
                    :title="__('Options')"
                    @click="toggleMenu('more')"
                >
                    <KIcon name="dots-three-vertical" /><span class="k-sr-only">{{ __('Options') }}</span>
                </button>
                <div v-if="menu === 'more'" class="k-menu k-menu--end" role="menu">
                    <p class="k-menu__head">{{ __('View') }}</p>
                    <button
                        type="button"
                        class="k-menu__item"
                        role="menuitemcheckbox"
                        :aria-checked="String(docked)"
                        @click="
                            docked = !docked;
                            closeMenu();
                        "
                    >
                        <KIcon :name="docked ? 'check' : 'blank'" class="k-menu__check" />{{ __('Block toolbar at the top') }}
                    </button>
                    <button
                        type="button"
                        class="k-menu__item"
                        role="menuitemcheckbox"
                        :aria-checked="String(focusMode)"
                        @click="
                            toggleFocus();
                            closeMenu();
                        "
                    >
                        <KIcon :name="focusMode ? 'check' : 'blank'" class="k-menu__check" />{{ __('Hide both panels') }}<kbd>{{ K('Mod+Shift+\\') }}</kbd>
                    </button>
                    <span class="k-menu__sep" role="separator"></span>
                    <button
                        type="button"
                        class="k-menu__item"
                        role="menuitem"
                        @click="
                            showRight('checks');
                            closeMenu();
                        "
                    >
                        <KIcon name="check-circle" />{{ __('Accessibility checks') }}<span v-if="issues.length" class="k-menu__count">{{ issues.length }}</span>
                    </button>
                    <button
                        type="button"
                        class="k-menu__item"
                        role="menuitem"
                        @click="
                            b.shortcutsOpen.value = true;
                            closeMenu();
                        "
                    >
                        <KIcon name="keyboard" />{{ __('Keyboard shortcuts') }}<kbd>?</kbd>
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
