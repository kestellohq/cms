/**
 * The pattern browser: open for adding a section, or for starting an empty page from a
 * layout. A layout's blocks become the page's own (one undo step); any other pattern is
 * placed like a block (next to the selection), a synced one as its reference. A layout
 * chosen for a page that has blocks first asks: replace them, or add after them.
 */
import { ref } from 'vue';
import { __ } from '@kestello/sdk';
import { layoutBlocks, needsLayoutChoice, withLayout } from './patterns.js';

export function usePatterns(b) {
    /** null: closed; 'insert': every pattern; 'layouts': whole pages, for an empty page */
    const browsing = ref(null);

    /** the layout waiting for "replace or add after" (the browser stays open meanwhile) */
    const layoutChoice = ref(null);

    const openPatterns = (mode = 'insert') => {
        browsing.value = mode;
        layoutChoice.value = null;
    };
    const closePatterns = () => {
        browsing.value = null;
        layoutChoice.value = null;
    };

    /** @param {'replace'|'append'|null} how  for a layout onto a page with blocks */
    function choosePattern(p, how = null) {
        if (!how && needsLayoutChoice(p, b.list.value)) {
            layoutChoice.value = p;
            return;
        }
        closePatterns();
        if (p.layout && !p.synced) {
            const blocks = layoutBlocks(p, b.catalog.value);
            if (!blocks.length) return;
            b.commit(withLayout(b.list.value, blocks, how ?? 'append'));
            b.select(blocks[0].id, { scroll: true });
            b.announceUndoable(
                how === 'replace'
                    ? __('The page is now {name}: {n} blocks.', { name: p.title, n: blocks.length })
                    : __('{name} added: {n} blocks.', { name: p.title, n: blocks.length }),
            );
            return;
        }
        b.place(b.makePattern(p));
    }

    return { browsing, layoutChoice, openPatterns, closePatterns, choosePattern };
}
