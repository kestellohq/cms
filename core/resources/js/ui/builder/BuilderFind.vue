<script setup>
import { computed } from 'vue';
import { __ } from '@kestello/sdk';
import KButton from '../KButton.vue';
import KIcon from '../KIcon.vue';
import { useBuilder } from './context.js';
import { highlight } from './find.js';
import { locate } from './tree.js';

/** Find and replace: the words on the page, block by block. Choosing a result selects its block. */
const { props, findQuery, replaceWith, matchCase, findResults, findTotal, replaceFound, select, selected, nameOf, iconOf, locked, list } = useBuilder();
const results = computed(() =>
    findResults.value.map((r) => {
        const node = locate(list.value, r.id)?.node ?? null;
        return {
            ...r,
            node,
            name: nameOf(node),
            icon: node ? iconOf(node) : 'square',
            parts: highlight(r.snippet, findQuery.value, { matchCase: matchCase.value }),
        };
    }),
);
</script>

<template>
    <div class="k-pane__scroll k-find">
        <form class="k-find__form" @submit.prevent="replaceFound()">
            <label class="k-find__field">
                <span>{{ __('Find') }}</span>
                <input v-model="findQuery" class="k-input k-find__query" type="search" maxlength="200" autocomplete="off" spellcheck="false" />
            </label>
            <label class="k-find__field">
                <span>{{ __('Replace with') }}</span>
                <input v-model="replaceWith" class="k-input" type="text" maxlength="1000" autocomplete="off" :disabled="props.disabled" />
            </label>
            <label class="k-find__case"><input v-model="matchCase" type="checkbox" />{{ __('Match upper and lower case') }}</label>
            <div class="k-find__actions">
                <p class="k-find__count" role="status">
                    <template v-if="findQuery.trim()">{{
                        findTotal === 0
                            ? __('No matches')
                            : findTotal === 1
                              ? __('1 match')
                              : __('{n} matches in {b} blocks', { n: findTotal, b: findResults.length })
                    }}</template>
                </p>
                <KButton v-if="!props.disabled" type="submit" size="sm" variant="primary" :disabled="!findTotal">{{ __('Replace all') }}</KButton>
            </div>
        </form>
        <ul v-if="results.length" class="k-find__results">
            <li v-for="r in results" :key="r.id" class="k-find__result" :class="{ 'is-selected': selected === r.id }">
                <button type="button" class="k-find__go" @click="select(r.id, { scroll: true })">
                    <span class="k-find__name"
                        ><KIcon :name="r.icon" />{{ r.name }}<span v-if="r.count > 1" class="k-find__n">{{ r.count }}</span></span
                    >
                    <span class="k-find__snippet"
                        ><template v-for="(p, i) in r.parts" :key="i"
                            ><mark v-if="p.hit">{{ p.text }}</mark
                            ><template v-else>{{ p.text }}</template></template
                        ></span
                    >
                </button>
                <button
                    v-if="!props.disabled && !locked(r.node, 'edit')"
                    type="button"
                    class="k-find__one"
                    :title="__('Replace in this block')"
                    :aria-label="__('Replace in {block}', { block: r.name })"
                    @click="replaceFound([r.id])"
                >
                    <KIcon name="arrows-left-right" />
                </button>
            </li>
        </ul>
        <p v-else-if="!findQuery.trim()" class="k-pane__empty">{{ __('Searches the words on this page: headings, text, lists, tables and buttons.') }}</p>
    </div>
</template>
