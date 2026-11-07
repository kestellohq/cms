/**
 * Several blocks selected at once: Shift+click picks a run of siblings, Mod+click adds
 * or takes one away (in the layers and on the page). The selected block stays the one
 * the run started from; the rest are in picked. Removing, duplicating, grouping and
 * pasting styles then act on all of them, each one undo step. The rules are multi.js.
 */
import { computed, ref, watch } from 'vue';
import { __ } from '@kestello/sdk';
import * as T from './tree.js';
import { duplicateMany, groupMany, inOrder, rangeBetween, toggled } from './multi.js';

export function useMulti(b) {
    const picked = ref([]);
    const multi = computed(() => picked.value.length > 1);
    /** The blocks actions apply to: all picked, or the one selected. */
    const pickedIds = computed(() => (multi.value ? picked.value : b.selected.value ? [b.selected.value] : []));
    const pickedNodes = computed(() => pickedIds.value.map((id) => T.locate(b.list.value, id)?.node).filter(Boolean));

    // undo, or a change elsewhere, may take some away
    watch(b.list, (list) => {
        if (!picked.value.length) return;
        const left = inOrder(list, picked.value);
        picked.value = left.length > 1 ? left : [];
    });

    function setPicked(ids, anchor) {
        picked.value = ids;
        b.selected.value = anchor;
        b.sendMarks?.(false);
        b.announce(__('{n} blocks selected.', { n: ids.length }));
    }
    /** Shift+click (range) or Mod+click (toggle). */
    function selectMore(id, mode) {
        const anchor = b.selected.value;
        if (!anchor || !T.locate(b.list.value, id) || !T.locate(b.list.value, anchor)) {
            b.select(id);
            return;
        }
        const next = mode === 'range' ? rangeBetween(b.list.value, anchor, id) : toggled(b.list.value, multi.value ? picked.value : [anchor], id);
        if (next.length < 2) {
            b.select(next[0] ?? null);
            return;
        }
        setPicked(next, next.includes(anchor) ? anchor : next[0]);
    }

    const canGroupPicked = computed(() => {
        const g = b.catalog.value.get('core/group');
        const at = multi.value ? T.locate(b.list.value, picked.value[0]) : null;
        return !!g && !!at && b.fitsIn(g, at.parent) && pickedNodes.value.every((n) => b.fitsIn(b.defOf(n), { type: 'core/group' }) && !b.locked(n, 'move'));
    });

    function removePicked() {
        const ids = pickedIds.value.filter((id) => !b.locked(T.locate(b.list.value, id)?.node, 'remove'));
        if (!ids.length) {
            b.announce(__("These blocks are locked by the layout and can't be removed."));
            return;
        }
        const first = T.locate(b.list.value, ids[0]),
            last = T.locate(b.list.value, ids[ids.length - 1]);
        const after = last.list[last.index + 1] ?? first.list[first.index - 1] ?? first.parent;
        let next = b.list.value;
        for (const id of ids) next = T.remove(next, id);
        b.commit(next);
        b.announceUndoable(__('{n} blocks removed.', { n: ids.length }));
        b.select(after?.id ?? null, { focus: true });
    }
    function duplicatePicked() {
        const { list, ids } = duplicateMany(b.list.value, pickedIds.value, T.insert);
        if (!ids.length) return;
        b.commit(list);
        setPicked(ids, ids[0]);
        b.announce(__('{n} blocks duplicated.', { n: ids.length }));
    }
    function groupPicked() {
        if (!canGroupPicked.value) return;
        const group = b.make('core/group');
        const n = picked.value.length;
        b.commit(groupMany(b.list.value, picked.value, group, T));
        b.select(group.id);
        b.announce(__('{n} blocks put in a group.', { n }));
    }

    return { picked, multi, pickedIds, pickedNodes, selectMore, canGroupPicked, removePicked, duplicatePicked, groupPicked };
}
