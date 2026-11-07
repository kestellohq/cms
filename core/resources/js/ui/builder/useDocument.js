/**
 * The tree being edited: changing it (each change one undo step), selecting
 * blocks, and the actions on them (move, duplicate, remove, group, turn into).
 * Screen readers hear what each action did; steps easy to regret also show a toast with
 * Undo (undo-notice.js), which is then what is heard.
 */
import { computed, nextTick, onBeforeUnmount, reactive, ref, shallowRef, toRaw, watch } from 'vue';
import { __, dismissToast, notify } from '@kestello/sdk';
import { createUndoNotice } from './undo-notice.js';
import * as T from './tree.js';
import { createHistory } from './history.js';
import { createPlacement } from './placement.js';
import { stylesFitting } from './design.js';
import { blockIcon, documentStats, errorFromMessage, errorTarget, isKind, summarize, TEXTUAL, turnInto as turned, upgradeTree } from './blocks.js';

const CLIPBOARD = 'kestello.builder.clipboard';
const STYLE_CLIPBOARD = 'kestello.builder.styles';

const TURN = () => [
    ['core/text', null, __('Text'), 'text-align-left'],
    ['core/heading', '2', __('Heading 2'), 'text-h-two'],
    ['core/heading', '3', __('Heading 3'), 'text-h-three'],
    ['core/heading', '4', __('Heading 4'), 'text-h-four'],
    ['core/list', 'bullets', __('List'), 'list-bullets'],
    ['core/list', 'ordered', __('Numbered list'), 'list-numbers'],
    ['core/quote', null, __('Quote'), 'quotes'],
    ['core/code', null, __('Code'), 'code-block'],
    ['core/details', null, __('Details'), 'caret-circle-down'],
];

export function useDocument(b, { tree, props, root }) {
    // The tree as it is now. v-model only hands a change back once the page has drawn again, and maybe
    // wrapped in a proxy; the editor reads its own copy so a change is there at once.
    const asList = (t) => (Array.isArray(t) ? upgradeTree(t) : []);
    const current = shallowRef(asList(tree.value));
    const list = computed(() => current.value);
    const placement = createPlacement({ fits: (def, parent) => b.fitsIn(def, parent), defOf: (n) => b.defOf(n), make: (name) => b.make(name) });

    // ── announcements (screen readers)
    const live = ref('');
    function announce(message) {
        live.value = '';
        nextTick(() => {
            live.value = message;
        });
    }

    // ── history
    const history = createHistory(reactive({ stack: [], at: -1, group: '', time: 0 }));
    watch(
        () => props.resetKey,
        () => history.reset(list.value),
        { immediate: true },
    );
    // a tree replaced from outside (restore, discard, a revision) is taken as it is, and starts a new history
    watch(tree, (t) => {
        if (toRaw(t) === toRaw(current.value)) return; // our own change, back from the page
        current.value = asList(t);
        if (!history.isCurrent(t)) history.reset(t);
    });
    const version = ref(0); // counts every change of the tree shown, for the Undo toast
    function show(next) {
        current.value = next;
        tree.value = next;
        version.value++;
    }
    function commit(next, group = '') {
        history.record(next, group, selected.value); // before the change shows: what was selected for it
        show(next);
    }
    const canUndo = computed(() => history.canUndo());
    const canRedo = computed(() => history.canRedo());
    function undo() {
        const step = history.undoStep();
        if (step) {
            show(step.tree);
            // back to the block that was selected before the step (gone, or none: nothing selected)
            const back = step.selected && owns(step.selected) ? step.selected : selected.value && owns(selected.value) ? selected.value : null;
            if (back !== selected.value) quietSelect(back);
            announce(__('Undone.'));
        }
    }
    function redo() {
        const t = history.redo();
        if (t) {
            show(t);
            if (selected.value && !owns(selected.value)) quietSelect(null);
            announce(__('Redone.'));
        }
    }

    // ── Undo on a toast, after a step easy to regret; gone once the page changes again
    const undoNotice = createUndoNotice({
        show: (message, action) => notify.info(message, { action, timeout: 8000 }),
        dismiss: dismissToast,
        label: __('Undo'),
    });
    watch(version, (v) => undoNotice.changed(v));
    onBeforeUnmount(() => undoNotice.clear());
    /** Says what a step did, on a toast with Undo (heard from there; just committed). */
    function announceUndoable(message) {
        undoNotice.offer(message, version.value, undo);
    }

    // ── selection
    const selected = ref(null);
    const hover = ref(null);
    const collapsed = reactive(new Set());
    const rightTab = ref(['document', 'block'].includes(props.startTab) ? props.startTab : 'document');
    const selectedAt = computed(() => (selected.value ? T.locate(list.value, selected.value) : null));
    const selectedDef = computed(() => b.defOf(selectedAt.value?.node));
    const rows = computed(() => T.flatten(list.value, collapsed));
    const trail = computed(() => (selectedAt.value ? [...selectedAt.value.path, selectedAt.value.node] : []));
    const owns = (id) => typeof id === 'string' && !!T.locate(list.value, id);

    // a block selected shows its settings, or its styles while those are open (styling one block after another)
    const showBlock = () => {
        if (rightTab.value !== 'style') rightTab.value = 'block';
    };

    /** Select a block (null: none). focus: its row in the layers; scroll: bring it into view there and on the page. */
    function select(id, { focus = false, scroll = false } = {}) {
        if (b.picked) b.picked.value = []; // one block chosen: several no longer
        selected.value = id;
        if (id) {
            showBlock();
            for (const a of T.locate(list.value, id)?.path ?? []) collapsed.delete(a.id);
            if (focus || scroll)
                nextTick(() => {
                    const row = root.value?.querySelector(`[data-row="${id}"]`);
                    if (focus) row?.focus();
                    else row?.scrollIntoView({ block: 'nearest' });
                });
        }
        b.sendMarks?.(scroll);
    }
    /** Select without moving focus or scrolling (typing on the page selects the block it is in). */
    function quietSelect(id) {
        if (b.picked) b.picked.value = [];
        selected.value = id;
        if (id) showBlock();
        b.sendMarks?.(false);
    }
    const toggleCollapse = (id) => (collapsed.has(id) ? collapsed.delete(id) : collapsed.add(id));
    const summary = (node) => summarize(node, b.defOf(node), b.bindingLabel, b.defOf) ?? __('Not available');
    const iconOf = (node) => blockIcon(node, b.defOf(node)); // a heading's icon says its level

    // ── changing blocks
    // locks guide people editing an entry; people who design (templates, parts, patterns) may still change everything
    const locked = (node, what) => props.kind === 'entry' && (node?.lock ?? []).includes(what);
    /** Change the selected block: { props } | { design } | { visibility } | { lock }; null removes a key. */
    function patchSelected(patch) {
        if (!selected.value) return;
        const clean = Object.fromEntries(Object.entries(patch).map(([k, v]) => [k, v === null && k !== 'props' ? undefined : v]));
        // changes to one block's props, or to its design, close together are one undo step
        const group = patch.props ? `props:${selected.value}` : 'design' in patch ? `design:${selected.value}` : '';
        commit(T.update(list.value, selected.value, clean), group);
    }
    function setProp(key, value) {
        const at = selectedAt.value;
        if (at) patchSelected({ props: { ...(at.node.props ?? {}), [key]: value } });
    }
    /** A field typed on the page: grouped with the typing around it into one undo step. */
    function setTyped(id, field, value) {
        const at = T.locate(list.value, id);
        if (!at || at.node.props?.[field] === value) return false;
        commit(T.update(list.value, id, { props: { ...(at.node.props ?? {}), [field]: value } }), `props:${id}`);
        return true;
    }
    /** A number set by dragging a handle on the page, kept within the field's limits. */
    function setNumber(id, prop, value) {
        const at = typeof id === 'string' ? T.locate(list.value, id) : null;
        const field = at ? b.defOf(at.node)?.fields.find((f) => f.name === prop && f.type === 'number') : null;
        if (!field || props.disabled || locked(at.node, 'edit')) return;
        const n = Math.round(Math.min(field.options?.max ?? 100, Math.max(field.options?.min ?? 0, Number(value) || 0)));
        commit(T.update(list.value, id, { props: { ...(at.node.props ?? {}), [prop]: n } }));
        announce(__('{block} resized to {n}%.', { block: b.nameOf(at.node), n }));
    }

    function insertAt(node, parentId, index, { message = true } = {}) {
        commit(T.insert(list.value, parentId, index, node));
        if (message) announce(__('{block} added.', { block: b.nameOf(node) }));
    }
    function removeNode(id, { focusRow = true } = {}) {
        const at = T.locate(list.value, id);
        if (!at) return null;
        if (locked(at.node, 'remove')) {
            announce(__("{block} is locked by the layout and can't be removed.", { block: b.nameOf(at.node) }));
            return null;
        }
        const next = at.list[at.index + 1] ?? at.list[at.index - 1] ?? at.parent;
        commit(T.remove(list.value, id));
        announceUndoable(__('{block} removed.', { block: b.nameOf(at.node) }));
        if (focusRow) select(next?.id ?? null, { focus: true });
        return at;
    }
    function duplicateNode(id) {
        const at = T.locate(list.value, id);
        if (!at) return;
        const copy = T.reid(at.node);
        commit(T.insert(list.value, at.parent?.id ?? null, at.index + 1, copy));
        announce(__('{block} duplicated.', { block: b.nameOf(at.node) }));
        select(copy.id, { focus: true });
    }
    function moveNode(id, parentId, index, { focus = true } = {}) {
        const at = T.locate(list.value, id);
        const parent = parentId === null ? null : T.locate(list.value, parentId)?.node;
        if (at && locked(at.node, 'move')) {
            announce(__("{block} is locked by the layout and can't be moved.", { block: b.nameOf(at.node) }));
            return false;
        }
        if (!at || !b.fitsIn(b.defOf(at.node), parent)) {
            announce(__("{block} can't go there.", { block: b.nameOf(at?.node) }));
            return false;
        }
        const next = T.move(list.value, id, parentId, index);
        if (next === list.value) return false;
        commit(next);
        const now = T.locate(next, id);
        announce(
            __('{block} moved to position {n} of {count}{where}.', {
                block: b.nameOf(at.node),
                n: now.index + 1,
                count: now.list.length,
                where: now.parent ? __(' in {parent}', { parent: b.nameOf(now.parent) }) : '',
            }),
        );
        if (focus) select(id, { focus: true });
        return true;
    }
    function shift(id, delta) {
        const at = T.locate(list.value, id);
        const j = at ? at.index + delta : -1;
        if (!at || j < 0 || j >= at.list.length) return;
        moveNode(id, at.parent?.id ?? null, delta > 0 ? j + 1 : j);
    }
    function outdent(id) {
        const at = T.locate(list.value, id);
        if (!at?.parent) return;
        const p = T.locate(list.value, at.parent.id);
        moveNode(id, p.parent?.id ?? null, p.index + 1);
    }
    function indent(id) {
        const at = T.locate(list.value, id);
        const prev = at?.list[at.index - 1];
        if (!prev) return;
        collapsed.delete(prev.id);
        moveNode(id, prev.id, (prev.children ?? []).length);
    }
    /** A new block next to the selection: inside it if it fits, else after it, else at the end. */
    function place(node) {
        const where = placement.near(list.value, selected.value, b.defOf(node));
        if (!where) {
            announce(__("{block} can't be placed here. Select a block it can go into first.", { block: b.nameOf(node) }));
            return;
        }
        insertAt(node, where.parentId, where.index);
        select(node.id, { scroll: true });
    }

    // copy and paste, between documents too (kept in this browser only)
    function copyNode(id) {
        const at = T.locate(list.value, id);
        if (!at) return;
        try {
            localStorage.setItem(CLIPBOARD, JSON.stringify(at.node));
        } catch {
            /* storage blocked: nothing to paste later */
        }
        announce(__('{block} copied.', { block: b.nameOf(at.node) }));
    }
    function paste() {
        let node;
        try {
            node = JSON.parse(localStorage.getItem(CLIPBOARD) ?? 'null');
        } catch {
            node = null;
        }
        if (!node || typeof node.type !== 'string' || !b.catalog.value.has(node.type)) {
            announce(__('Nothing to paste.'));
            return;
        }
        place(T.reid(node));
    }

    // copy and paste styles: one block's look given to another (kept in this browser only)
    function copyStyles(id) {
        const at = T.locate(list.value, id);
        if (!at) return;
        try {
            localStorage.setItem(STYLE_CLIPBOARD, JSON.stringify({ design: at.node.design ?? null }));
        } catch {
            /* storage blocked */
        }
        announce(
            at.node.design
                ? __('Styles of {block} copied.', { block: b.nameOf(at.node) })
                : __('{block} has no styles of its own; pasting them clears the styles of another.', { block: b.nameOf(at.node) }),
        );
    }
    function pasteStyles(target) {
        const ids = Array.isArray(target) ? target : [target];
        if (ids.length > 1) return pasteStylesMany(ids);
        const at = T.locate(list.value, ids[0]);
        if (!at) return;
        const id = ids[0];
        const copied = copiedStyles();
        if (!copied) {
            announce(__("No styles copied yet. Copy a block's styles first."));
            return;
        }
        if (locked(at.node, 'edit')) {
            announce(__('{block} is locked by the layout.', { block: b.nameOf(at.node) }));
            return;
        }
        const supports = b.defOf(at.node)?.supports ?? [];
        if (!supports.length) {
            announce(__('{block} has no style settings.', { block: b.nameOf(at.node) }));
            return;
        }
        const design = stylesFitting(copied.design, supports);
        if (copied.design && !design) {
            announce(__('None of these styles fit {block}.', { block: b.nameOf(at.node) }));
            return;
        }
        commit(T.update(list.value, id, { design: design ?? undefined }));
        announce(design ? __('Styles pasted onto {block}.', { block: b.nameOf(at.node) }) : __('Styles of {block} cleared.', { block: b.nameOf(at.node) }));
    }

    function copiedStyles() {
        try {
            const c = JSON.parse(localStorage.getItem(STYLE_CLIPBOARD) ?? 'null');
            return c && typeof c === 'object' && 'design' in c ? c : null;
        } catch {
            return null;
        }
    }
    function pasteStylesMany(ids) {
        const copied = copiedStyles();
        if (!copied) {
            announce(__("No styles copied yet. Copy a block's styles first."));
            return;
        }
        let next = list.value,
            n = 0;
        for (const id of ids) {
            const node = T.locate(next, id)?.node;
            const supports = b.defOf(node)?.supports ?? [];
            if (!node || locked(node, 'edit') || !supports.length) continue;
            const design = stylesFitting(copied.design, supports);
            if (copied.design && !design) continue;
            next = T.update(next, id, { design: design ?? undefined });
            n++;
        }
        if (!n) {
            announce(__('None of these blocks can take these styles.'));
            return;
        }
        commit(next);
        announce(__('Styles pasted onto {n} of {total} blocks.', { n, total: ids.length }));
    }

    // ── turning a text block into another
    const editLocked = computed(() => locked(selectedAt.value?.node, 'edit'));
    const turnChoices = computed(() => {
        const at = selectedAt.value;
        if (!at || !TEXTUAL.includes(at.node.type) || props.disabled || editLocked.value) return [];
        return TURN()
            .filter(([t, v]) => b.catalog.value.has(t) && b.fitsIn(b.catalog.value.get(t), at.parent) && !isKind(at.node, t, v))
            .map(([type, variant, label, icon]) => ({ type, variant, label, icon }));
    });
    function turnInto({ type, variant, label }) {
        const at = selectedAt.value;
        if (!at) return;
        const node = turned(at.node, type, variant);
        b.continueTyping?.(node);
        commit(T.insert(T.remove(list.value, at.node.id), at.parent?.id ?? null, at.index, node));
        announce(__('Turned into {block}.', { block: label }));
        select(node.id);
    }

    // ── wrapping in a group, and back out
    const canGroup = computed(() => {
        const at = selectedAt.value;
        const g = b.catalog.value.get('core/group');
        return !!at && !!g && at.node.type !== 'core/group' && b.fitsIn(g, at.parent) && b.fitsIn(b.defOf(at.node), { type: 'core/group' });
    });
    const canUngroup = computed(() => {
        const at = selectedAt.value;
        return !!at && at.node.type === 'core/group' && (at.node.children ?? []).length > 0 && at.node.children.every((k) => b.fitsIn(b.defOf(k), at.parent));
    });
    function groupNode(id) {
        const at = T.locate(list.value, id);
        if (!at || !canGroup.value) return;
        const group = { ...b.make('core/group'), children: [at.node] };
        commit(T.insert(T.remove(list.value, id), at.parent?.id ?? null, at.index, group));
        announce(__('{block} put in a group.', { block: b.nameOf(at.node) }));
        select(group.id);
    }
    function ungroupNode(id) {
        const at = T.locate(list.value, id);
        if (!at || !canUngroup.value) return;
        let next = T.remove(list.value, id);
        at.node.children.forEach((k, i) => {
            next = T.insert(next, at.parent?.id ?? null, at.index + i, k);
        });
        commit(next);
        announce(__('Group removed; its blocks stay.'));
        select(at.node.children[0].id);
    }

    // ── counts and the outline
    const stats = computed(() => {
        const s = documentStats(list.value, b.defOf);
        return { ...s, headings: s.headings.map((h) => ({ ...h, text: h.text || __('(empty heading)') })) };
    });

    // what the server refused on save, as it describes it ("Block 2 › Block 1 (Heading), Text: …")
    // each server error with the block it is about, so it can be shown
    const blockErrors = computed(() =>
        Object.entries(props.errors)
            .filter(([k]) => k.startsWith('blocks'))
            .flatMap(([k, v]) =>
                (Array.isArray(v) ? v : [v]).map((message) => {
                    // the key may lead to the block (blocks.2.props.text), or the message names it (Block 3 (Text): …)
                    const said = errorFromMessage(list.value, message);
                    const id = said?.id ?? errorTarget(list.value, k)?.id ?? null;
                    const node = id ? T.locate(list.value, id)?.node : null;
                    return node ? { message: said ? said.rest : message, id, name: b.nameOf(node) } : { message, id: null, name: null };
                }),
            ),
    );

    return {
        list,
        placement,
        locked,
        live,
        announce,
        announceUndoable,
        commit,
        iconOf,
        canUndo,
        canRedo,
        undo,
        redo,
        selected,
        hover,
        collapsed,
        rightTab,
        selectedAt,
        selectedDef,
        rows,
        trail,
        owns,
        select,
        quietSelect,
        toggleCollapse,
        summary,
        patchSelected,
        setProp,
        setTyped,
        setNumber,
        insertAt,
        removeNode,
        duplicateNode,
        moveNode,
        shift,
        outdent,
        indent,
        place,
        copyNode,
        paste,
        copyStyles,
        pasteStyles,
        editLocked,
        turnChoices,
        turnInto,
        canGroup,
        canUngroup,
        groupNode,
        ungroupNode,
        stats,
        blockErrors,
    };
}
