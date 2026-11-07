<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { __ } from '@kestello/sdk';
import KButton from '../KButton.vue';
import KIcon from '../KIcon.vue';
import KModal from '../KModal.vue';
import { useBuilder } from './context.js';
import { groupPatterns } from './patterns.js';
import PatternPreview from './PatternPreview.vue';

/**
 * The pattern browser: every pattern by category with a live preview, or (for an
 * empty page) the whole-page layouts. Choosing one adds it and closes the browser.
 */
const { browsing, layoutChoice, closePatterns, choosePattern, usablePatterns, list } = useBuilder();

// core's category names, translated; a theme's own stay as the theme wrote them
const CORE_LABELS = {
    Pages: __('Pages'),
    Headers: __('Headers'),
    Features: __('Features'),
    Text: __('Text'),
    'Calls to action': __('Calls to action'),
    Testimonials: __('Testimonials'),
    Pricing: __('Pricing'),
    Questions: __('Questions'),
    About: __('About'),
    Contact: __('Contact'),
    'Lists and tables': __('Lists and tables'),
};
const label = (name) => CORE_LABELS[name] ?? name;
const layoutsOnly = computed(() => browsing.value === 'layouts');
const query = ref('');
const category = ref(''); // '' : all
const groups = computed(() => groupPatterns(usablePatterns.value, { query: query.value, layouts: layoutsOnly.value }));
const shown = computed(() => (category.value ? groups.value.filter((g) => g.name === category.value) : groups.value));
const total = computed(() => groups.value.reduce((n, g) => n + g.patterns.length, 0));
const search = ref(null);
watch(browsing, (mode) => {
    if (mode) {
        query.value = '';
        category.value = '';
        nextTick(() => search.value?.focus());
    }
});
watch(groups, (g) => {
    if (category.value && !g.some((x) => x.name === category.value)) category.value = '';
});

const open = computed({
    get: () => browsing.value !== null,
    set: (v) => {
        if (!v) closePatterns();
    },
});
</script>

<template>
    <KModal v-model:open="open" :title="layoutsOnly ? __('Start from a layout') : __('Patterns')" size="xl">
        <div class="k-patterns">
            <aside class="k-patterns__side">
                <label class="k-patterns__label" for="k-patterns-search">{{ layoutsOnly ? __('Find a layout') : __('Find a pattern') }}</label>
                <div class="k-patterns__search">
                    <KIcon name="magnifying-glass" />
                    <input id="k-patterns-search" ref="search" v-model="query" type="search" class="k-input" />
                </div>
                <nav v-if="groups.length > 1" class="k-patterns__cats" :aria-label="__('Categories')">
                    <button type="button" class="k-patterns__cat" :aria-pressed="String(category === '')" @click="category = ''">
                        {{ __('All') }}<span>{{ total }}</span>
                    </button>
                    <button
                        v-for="g in groups"
                        :key="g.name"
                        type="button"
                        class="k-patterns__cat"
                        :aria-pressed="String(category === g.name)"
                        @click="category = g.name"
                    >
                        {{ label(g.name) }}<span>{{ g.patterns.length }}</span>
                    </button>
                </nav>
                <p v-if="layoutsOnly" class="k-muted k-small">
                    {{ __('A layout fills the page with sections you then change: the words, the images, the order.') }}
                </p>
            </aside>
            <div class="k-patterns__main">
                <div v-if="layoutChoice" class="k-patterns__choice" role="group" :aria-label="__('Replace or add after')">
                    <p>{{ __('{name} is a whole page, and this page already has {n} blocks.', { name: layoutChoice.title, n: list.length }) }}</p>
                    <div>
                        <KButton size="sm" variant="primary" @click="choosePattern(layoutChoice, 'replace')">{{ __('Replace them') }}</KButton>
                        <KButton size="sm" @click="choosePattern(layoutChoice, 'append')">{{ __('Add after them') }}</KButton>
                        <KButton size="sm" variant="ghost" @click="layoutChoice = null">{{ __('Cancel') }}</KButton>
                    </div>
                </div>
                <section v-for="g in shown" :key="g.name" class="k-patterns__group">
                    <h3 v-if="shown.length > 1 || !layoutsOnly">{{ label(g.name) }}</h3>
                    <ul class="k-patterns__grid">
                        <li v-for="p in g.patterns" :key="p.key">
                            <button
                                type="button"
                                class="k-patterns__card"
                                :aria-label="p.description ? `${p.title}. ${p.description}` : p.title"
                                @click="choosePattern(p)"
                            >
                                <PatternPreview :pattern-key="p.key" />
                                <span class="k-patterns__name"
                                    >{{ p.title }}<span v-if="p.synced" class="k-patterns__note">{{ __('synced') }}</span></span
                                >
                                <span v-if="p.description" class="k-patterns__about">{{ p.description }}</span>
                            </button>
                        </li>
                    </ul>
                </section>
                <p v-if="!shown.length" class="k-patterns__empty">
                    {{ query ? __('No pattern matches “{q}”.', { q: query }) : __('There are no patterns yet.') }}
                </p>
            </div>
        </div>
    </KModal>
</template>
