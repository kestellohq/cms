<script setup>
import { __ } from '@kestello/sdk';
import KIcon from '../KIcon.vue';
import { useBuilder } from './context.js';

/** Counts and the outline of headings, from the bar. */
const { menu, toggleMenu, closeMenu, stats, select } = useBuilder();
function go(id) {
    select(id, { scroll: true });
    closeMenu();
}
</script>

<template>
    <div class="k-menu-wrap">
        <button
            type="button"
            class="k-bb"
            :class="{ 'is-on': menu === 'details' }"
            :aria-expanded="String(menu === 'details')"
            aria-haspopup="dialog"
            :title="__('Details and outline')"
            @click="toggleMenu('details')"
        >
            <KIcon name="info" /><span class="k-sr-only">{{ __('Details and outline') }}</span>
        </button>
        <div v-if="menu === 'details'" class="k-menu k-menu--wide" role="dialog" :aria-label="__('Details and outline')">
            <dl class="k-stats">
                <div>
                    <dt>{{ __('Words') }}</dt>
                    <dd>{{ stats.words }}</dd>
                </div>
                <div>
                    <dt>{{ __('Characters') }}</dt>
                    <dd>{{ stats.chars }}</dd>
                </div>
                <div>
                    <dt>{{ __('Reading time') }}</dt>
                    <dd>{{ __('{n} min', { n: stats.minutes }) }}</dd>
                </div>
                <div>
                    <dt>{{ __('Headings') }}</dt>
                    <dd>{{ stats.headings.length }}</dd>
                </div>
                <div>
                    <dt>{{ __('Paragraphs') }}</dt>
                    <dd>{{ stats.paragraphs }}</dd>
                </div>
                <div>
                    <dt>{{ __('Blocks') }}</dt>
                    <dd>{{ stats.blocks }}</dd>
                </div>
            </dl>
            <p class="k-menu__head">{{ __('Outline') }}</p>
            <ol v-if="stats.headings.length" class="k-outline">
                <li v-for="h in stats.headings" :key="h.id" :style="{ '--lvl': h.level }">
                    <button type="button" class="k-outline__item" @click="go(h.id)">
                        <span class="k-outline__lvl">H{{ h.level }}</span
                        >{{ h.text }}
                    </button>
                </li>
            </ol>
            <p v-else class="k-menu__note">{{ __('Headings you add show up here, in order.') }}</p>
        </div>
    </div>
</template>
