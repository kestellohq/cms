/**
 * The block library: blocks by category, and patterns, filtered by a search.
 */
import { computed, ref } from 'vue';
import { __ } from '@kestello/sdk';

export function useLibrary(b, { props }) {
    const query = ref('');
    const CATEGORIES = [
        ['text', __('Text')],
        ['media', __('Media')],
        ['layout', __('Layout')],
        ['data', __('Entries and data')],
        ['widgets', __('Widgets')],
    ];
    const matches = (text) => {
        const q = query.value.trim().toLowerCase();
        return !q || text.toLowerCase().includes(q);
    };

    const library = computed(() => {
        // blocks that only go inside others (a column) aren't offered on their own; at the top level of a
        // document that limits its blocks, the others still show once a block is selected to put them in
        const usable = b.definitions.value.filter(
            (d) =>
                !b.hidden.value.includes(d.name) &&
                d.parents === null &&
                (!props.allowed || props.allowed.includes(d.name) || !!b.selected.value) &&
                matches(`${d.label} ${d.description} ${d.name}`),
        );
        return CATEGORIES.map(([key, label]) => ({ key, label, blocks: usable.filter((d) => d.category === key) })).filter((g) => g.blocks.length);
    });
    // patterns this document may hold: synced ones only where a synced pattern may go, never itself
    const usablePatterns = computed(() =>
        b.patterns.value.filter((p) => (!p.synced || !b.hidden.value.includes('core/pattern')) && !(props.kind === 'pattern' && p.key === props.docKey)),
    );
    // in the panel: the site's and theme's own (core's are in the browser), or every match while searching
    const patternChoices = computed(() =>
        query.value.trim()
            ? usablePatterns.value.filter((p) => matches(`${p.title} ${p.description} ${p.category}`))
            : usablePatterns.value.filter((p) => p.source !== 'core' && !p.layout),
    );

    return { query, library, usablePatterns, patternChoices };
}
