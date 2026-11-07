<script setup>
import { __ } from '@kestello/sdk';
import KIcon from '../KIcon.vue';
import { useBuilder } from './context.js';

/** Search for a block to add; arrows choose, Enter adds, Escape goes back to the text. */
const { quick, quickChoices, quickActive, pickQuick, quickKey } = useBuilder();
</script>

<template>
    <div class="k-builder__quick" :style="quick.style" role="dialog" :aria-label="__('Add a block')">
        <label class="k-sr-only" for="k-builder-quick">{{ __('Search blocks') }}</label>
        <input
            id="k-builder-quick"
            v-model="quick.query"
            class="k-input"
            type="search"
            autocomplete="off"
            role="combobox"
            aria-controls="k-builder-quick-list"
            aria-expanded="true"
            :aria-activedescendant="quickChoices.length ? `k-quick-${quickActive}` : undefined"
            @keydown="quickKey"
            @blur="quick.open = false"
        />
        <ul id="k-builder-quick-list" role="listbox" class="k-builder__quick-list">
            <li
                v-for="(c, i) in quickChoices"
                :id="`k-quick-${i}`"
                :key="c.key"
                role="option"
                :aria-selected="String(i === quickActive)"
                :class="{ 'is-active': i === quickActive }"
                @mousedown.prevent="pickQuick(c)"
                @mouseenter="quickActive = i"
            >
                <KIcon :name="c.icon" /><span>{{ c.label }}</span>
            </li>
            <li v-if="!quickChoices.length" class="k-builder__quick-none">{{ __('No block matches “{q}”.', { q: quick.query }) }}</li>
        </ul>
    </div>
</template>
