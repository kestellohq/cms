<script setup>
import { __ } from '@kestello/sdk';
import KIcon from '../KIcon.vue';
import { useBuilder } from './context.js';

/** The block library: click to add next to the selection, or drag onto the page or the layers. */
const { props, query, library, usablePatterns, patternChoices, selected, place, create, dragNew, endDrag, openPatterns, choosePattern } = useBuilder();
const browseText = (n) => __('{n, plural, one {Browse # pattern} other {Browse # patterns}}', { n });
</script>

<template>
    <div class="k-pane__scroll k-lib">
        <label class="k-lib__label" for="k-lib-search">{{ __('Find a block or pattern') }}</label>
        <div class="k-lib__search">
            <KIcon name="magnifying-glass" />
            <input id="k-lib-search" v-model="query" type="search" class="k-builder__search" />
        </div>
        <p class="k-pane__help k-lib__where">
            {{
                selected
                    ? __('Adds inside or after the selected block. You can also drag a block onto the page or the layers.')
                    : __('Adds at the end of the page. You can also drag a block onto the page or the layers.')
            }}
        </p>
        <section v-for="g in library" :key="g.key" class="k-lib__group">
            <h3>{{ g.label }}</h3>
            <div class="k-lib__grid">
                <button
                    v-for="d in g.blocks"
                    :key="d.name"
                    type="button"
                    class="k-lib__tile"
                    draggable="true"
                    :title="d.description"
                    :disabled="props.disabled"
                    @click="place(create(d))"
                    @dragstart="dragNew($event, { def: d })"
                    @dragend="endDrag"
                >
                    <KIcon :name="d.icon" /><span>{{ d.label }}</span>
                </button>
            </div>
        </section>
        <!-- while searching, only when a pattern matches -->
        <section v-if="usablePatterns.length && (!query.trim() || patternChoices.length)" class="k-lib__group">
            <h3>{{ __('Patterns') }}</h3>
            <button v-if="!query.trim()" type="button" class="k-lib__browse" :disabled="props.disabled" @click="openPatterns()">
                <KIcon name="squares-four" /><span>{{ browseText(usablePatterns.length) }}</span
                ><KIcon name="arrow-right" />
            </button>
            <button
                v-for="p in patternChoices"
                :key="p.key"
                type="button"
                class="k-lib__item"
                :draggable="!p.layout"
                :disabled="props.disabled"
                @click="choosePattern(p)"
                @dragstart="dragNew($event, { pattern: p })"
                @dragend="endDrag"
            >
                <KIcon :name="p.synced ? 'star' : 'bookmark'" /><span>{{ p.title }}</span
                ><span v-if="p.synced" class="k-lib__note">{{ __('synced') }}</span>
            </button>
        </section>
        <p v-if="query.trim() && !library.length && !patternChoices.length" class="k-pane__empty">{{ __('No block matches “{q}”.', { q: query }) }}</p>
    </div>
</template>
