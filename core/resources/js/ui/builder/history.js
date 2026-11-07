/**
 * Undo and redo: snapshots of the tree. Changes in one group (typing into one
 * block's field) within a moment of each other are one step. Each step keeps
 * which block was selected before it, so undoing it can select that again.
 */
const LIMIT = 200;
const GROUP_MS = 1200;

export function createHistory(state = { stack: [], at: -1, group: '', time: 0 }, now = () => Date.now()) {
    state.selections ??= []; // per step: the block selected before it (null: none)
    const snap = (tree) => JSON.stringify(tree ?? []);
    return {
        state,
        reset(tree) {
            state.stack = [snap(tree)];
            state.selections = [null];
            state.at = 0;
            state.group = '';
        },
        /** Is this tree the one the history is at? (a tree replaced from outside isn't) */
        isCurrent: (tree) => snap(tree) === state.stack[state.at],
        record(tree, group = '', selected = null) {
            const s = snap(tree);
            const t = now();
            if (group && group === state.group && t - state.time < GROUP_MS && state.at === state.stack.length - 1) {
                state.stack[state.at] = s;
            } else {
                state.stack = state.stack.slice(0, state.at + 1);
                state.selections = state.selections.slice(0, state.at + 1);
                state.stack.push(s);
                state.selections.push(selected);
                if (state.stack.length > LIMIT) {
                    state.stack.shift();
                    state.selections.shift();
                }
                state.at = state.stack.length - 1;
            }
            state.group = group;
            state.time = t;
        },
        canUndo: () => state.at > 0,
        canRedo: () => state.at < state.stack.length - 1,
        /** The tree one step back (or forward), or null. */
        undo() {
            return this.undoStep()?.tree ?? null;
        },
        /** One step back: the tree, and the block that was selected before the step undone; or null. */
        undoStep() {
            if (state.at <= 0) return null;
            const selected = state.selections[state.at] ?? null;
            state.at--;
            state.group = '';
            return { tree: JSON.parse(state.stack[state.at]), selected };
        },
        redo() {
            if (state.at >= state.stack.length - 1) return null;
            state.at++;
            state.group = '';
            return JSON.parse(state.stack[state.at]);
        },
    };
}
