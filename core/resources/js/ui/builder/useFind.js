/**
 * Find and replace (the left panel's third view, BuilderFind.vue): what is searched for,
 * the blocks that match, and replacing in one block or all at once (one undo step each).
 * The searching itself is find.js.
 */
import { computed, nextTick, ref } from 'vue';
import { __ } from '@kestello/sdk';
import { findMatches, replaceMatches } from './find.js';

export function useFind(b, { root }) {
    const findQuery = ref('');
    const replaceWith = ref('');
    const matchCase = ref(false);

    const findResults = computed(() => findMatches(b.list.value, b.defOf, findQuery.value, { matchCase: matchCase.value }));
    const findTotal = computed(() => findResults.value.reduce((n, r) => n + r.count, 0));

    function openFind() {
        b.left.value = 'find';
        nextTick(() => {
            const input = root.value?.querySelector('.k-find__query');
            input?.focus();
            input?.select();
        });
    }
    function replaceFound(ids = null) {
        if (b.props.disabled) return;
        const { list, count } = replaceMatches(b.list.value, b.defOf, findQuery.value, replaceWith.value, {
            ids,
            matchCase: matchCase.value,
            skip: (node) => b.locked(node, 'edit'),
        });
        if (!count) {
            b.announce(__('Nothing was replaced. Blocks locked by the layout are left as they are.'));
            return;
        }
        b.commit(list);
        b.announceUndoable(count === 1 ? __('1 replaced.') : __('{n} replaced.', { n: count }));
    }

    return { findQuery, replaceWith, matchCase, findResults, findTotal, openFind, replaceFound };
}
