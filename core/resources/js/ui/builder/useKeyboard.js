/**
 * Keys: the editor's own (anywhere in it), the layers tree's, and the block
 * commands that work the same when typed on the page (shared/canvas-protocol.js).
 */
import { nextTick, ref } from 'vue';
import { blockCommand } from '../../shared/canvas-protocol.js';
import { keyLabel } from './shortcuts.js';
import * as T from './tree.js';

export function useKeyboard(b, { props, emit, root }) {
    const shortcutsOpen = ref(false);
    const mod = (e) => e.metaKey || e.ctrlKey;

    /** A block command, from a key here or on the page. */
    function runCommand(command, id = b.selected.value, rect = null) {
        if (command === 'undo') b.undo();
        else if (command === 'redo') b.redo();
        else if (command === 'save') emit('save');
        else if (command === 'find') b.openFind();
        else if (!id || props.disabled) return;
        else if (command === 'duplicate') b.multi.value ? b.duplicatePicked() : b.duplicateNode(id);
        else if (command === 'remove-block') b.multi.value ? b.removePicked() : b.removeNode(id);
        else if (command === 'insert-before') b.openQuick({ mode: 'before', id, rect });
        else if (command === 'insert-after') b.openQuick({ mode: 'after', id, rect });
        else if (command === 'copy-styles') b.copyStyles(id);
        else if (command === 'paste-styles') b.pasteStyles(b.multi.value ? b.pickedIds.value : id);
    }

    function globalKey(event) {
        const target = event.target;
        const typing = target instanceof HTMLElement && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName));
        // panels, from anywhere
        if (mod(event) && event.shiftKey && event.key === ',') {
            event.preventDefault();
            b.right.value = !b.right.value;
            return;
        }
        if (event.altKey && event.shiftKey && event.code === 'KeyO') {
            event.preventDefault();
            b.toggleLeft('layers');
            return;
        }
        if (mod(event) && event.shiftKey && event.code === 'Backslash') {
            event.preventDefault();
            b.toggleFocus();
            return;
        }
        if (blockCommand(event) === 'find') {
            event.preventDefault();
            b.openFind();
            return;
        }
        if (typing) return;
        if (event.key === 'Escape' && b.multi.value) {
            event.preventDefault();
            b.select(b.selected.value);
            return;
        }
        if (event.key === '?' && !mod(event)) {
            event.preventDefault();
            shortcutsOpen.value = true;
            return;
        }
        if (event.key === '/' && !mod(event) && !props.disabled) {
            event.preventDefault();
            b.openQuick({ mode: 'after', id: b.selected.value });
            return;
        }
        const command = blockCommand(event);
        // saving belongs to the page around the editor, which listens for it itself
        if (command && command !== 'save') {
            event.preventDefault();
            runCommand(command);
        }
    }

    /** The layers tree: arrows move between rows, Alt+arrows move the block, Mod+D/C/V. */
    function rowKey(event, row, i) {
        const id = row.node.id;
        const rows = b.rows.value;
        const focusRow = (r) => {
            if (r) b.select(r.node.id, { focus: true });
        };
        // locks guide editors in the block editor; people in the builder may still move locked blocks
        if (event.altKey && !props.disabled) {
            const actions = { ArrowUp: () => b.shift(id, -1), ArrowDown: () => b.shift(id, 1), ArrowLeft: () => b.outdent(id), ArrowRight: () => b.indent(id) };
            if (actions[event.key]) {
                event.preventDefault();
                event.stopPropagation();
                actions[event.key]();
                return;
            }
        }
        if (mod(event) && !event.shiftKey && !props.disabled) {
            const actions = { d: () => b.duplicateNode(id), c: () => b.copyNode(id), v: () => b.paste() };
            const act = actions[event.key.toLowerCase()];
            if (act) {
                event.preventDefault();
                event.stopPropagation();
                act();
                return;
            }
        }
        // Shift+Up/Down: the selection grows (or shrinks) to the sibling above or below
        if (event.shiftKey && !mod(event) && !event.altKey && (event.key === 'ArrowUp' || event.key === 'ArrowDown')) {
            event.preventDefault();
            const at = T.locate(b.list.value, id);
            const to = at?.list[at.index + (event.key === 'ArrowUp' ? -1 : 1)];
            if (to) {
                b.selectMore(to.id, 'range');
                nextTick(() => root.value?.querySelector(`[data-row="${to.id}"]`)?.focus());
            }
            return;
        }
        const hasChildren = (row.node.children ?? []).length > 0;
        switch (event.key) {
            case 'ArrowDown':
                event.preventDefault();
                focusRow(rows[i + 1]);
                break;
            case 'ArrowUp':
                event.preventDefault();
                focusRow(rows[i - 1]);
                break;
            case 'Home':
                event.preventDefault();
                focusRow(rows[0]);
                break;
            case 'End':
                event.preventDefault();
                focusRow(rows[rows.length - 1]);
                break;
            case 'ArrowRight':
                event.preventDefault();
                if (hasChildren && b.collapsed.has(id)) b.collapsed.delete(id);
                else focusRow(rows[i + 1]?.depth > row.depth ? rows[i + 1] : null);
                break;
            case 'ArrowLeft': {
                event.preventDefault();
                if (hasChildren && !b.collapsed.has(id)) b.collapsed.add(id);
                else if (row.depth > 0)
                    focusRow(
                        rows
                            .slice(0, i)
                            .reverse()
                            .find((r) => r.depth < row.depth),
                    );
                break;
            }
            case 'Delete':
            case 'Backspace':
                if (!props.disabled) {
                    event.preventDefault();
                    event.stopPropagation();
                    if (b.multi.value) b.removePicked();
                    else b.removeNode(id);
                }
                break;
            case 'Escape':
                event.preventDefault();
                if (b.multi.value) {
                    event.stopPropagation();
                    b.select(b.selected.value, { focus: true });
                    break;
                }
                b.select(null);
                b.rightTab.value = 'document';
                break;
            default:
        }
    }

    return { shortcutsOpen, runCommand, globalKey, rowKey, K: keyLabel };
}
