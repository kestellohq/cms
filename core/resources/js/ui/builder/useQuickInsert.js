/**
 * The quick inserter: / on the page (or + on the toolbar) opens a search for a
 * block to add before, after or instead of the one the caret is in.
 */
import { computed, nextTick, reactive, ref, watch } from 'vue';
import { __ } from '@kestello/sdk';
import * as T from './tree.js';

const SHOWN = 12;
const ORDER = ['text', 'media', 'layout', 'data', 'widgets'];

export function useQuickInsert(b, { props, root }) {
    const quick = reactive({ open: false, mode: 'after', id: null, query: '', style: null });
    const active = ref(0);

    /** @param {{ mode: 'before'|'after'|'replace', id: string|null, rect?: object }} options  rect: the field typed in, as the canvas reported it */
    function openQuick({ mode, id, rect = null }) {
        if (props.disabled) return;
        quick.mode = mode;
        quick.id = id ?? null;
        quick.query = '';
        quick.style = b.under(rect) ?? (b.toolbarStyle.value && !b.toolbarDocked.value ? { ...b.toolbarStyle.value } : { top: '16px', left: '16px' });
        quick.open = true;
        nextTick(() => root.value?.querySelector('.k-builder__quick input')?.focus());
    }

    const parent = computed(() => (quick.id ? (T.locate(b.list.value, quick.id)?.parent ?? null) : null));
    const choices = computed(() => {
        const q = quick.query.trim().toLowerCase();
        const matches = (text) => !q || text.toLowerCase().includes(q);
        // names that start with what was typed first, then by kind: text before media before layout
        const rank = (d) => (q && d.label.toLowerCase().startsWith(q) ? 0 : 10) + Math.max(0, ORDER.indexOf(d.category));
        const blocks = b.definitions.value
            .filter((d) => b.fitsIn(d, parent.value) && matches(`${d.label} ${d.description} ${d.name}`))
            .map((d, i) => ({ d, i, r: rank(d) }))
            .sort((x, y) => x.r - y.r || x.i - y.i)
            .map(({ d }) => ({ key: d.name, label: d.label, icon: d.icon, make: () => b.create(d) }));
        const patterns = b.patternChoices.value
            .filter((p) => matches(`${p.title} ${p.category}`) && b.fitsIn(b.defOfPattern(p), parent.value))
            .map((p) => ({ key: `pattern:${p.key}`, label: p.title, icon: p.synced ? 'star' : 'bookmark', make: () => b.makePattern(p) }));
        return [...blocks, ...patterns].slice(0, SHOWN);
    });
    watch(
        () => quick.query,
        () => {
            active.value = 0;
        },
    );

    function pick(choice) {
        if (!choice) return;
        const node = choice.make();
        const at = quick.id ? T.locate(b.list.value, quick.id) : null;
        quick.open = false;
        b.continueTyping(node);
        if (at && quick.mode === 'replace') {
            b.commit(T.insert(T.remove(b.list.value, at.node.id), at.parent?.id ?? null, at.index, node));
            b.announce(__('{block} added.', { block: b.nameOf(node) }));
        } else if (at) {
            b.insertAt(node, at.parent?.id ?? null, at.index + (quick.mode === 'before' ? 0 : 1));
        } else {
            b.insertAt(node, null, b.list.value.length);
        }
        b.select(node.id);
    }

    function quickKey(event) {
        const n = Math.max(1, choices.value.length);
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            active.value = (active.value + 1) % n;
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            active.value = (active.value - 1 + n) % n;
        } else if (event.key === 'Enter') {
            event.preventDefault();
            pick(choices.value[active.value]);
        } else if (event.key === 'Escape') {
            event.preventDefault();
            quick.open = false;
            if (quick.id) b.focusOn(quick.id);
        }
    }

    return { quick, quickChoices: choices, quickActive: active, openQuick, pickQuick: pick, quickKey };
}
