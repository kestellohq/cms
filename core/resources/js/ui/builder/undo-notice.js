/**
 * "Undo" on a toast after a step that is easy to regret (removing blocks, adding a layout,
 * replacing all). The offer holds only while the page is exactly as that step left it:
 * any later change, an undo from the keyboard included, takes the toast away, so its
 * Undo can never undo something else. One offer at a time.
 *
 * Pure: the toast and the version counter come from outside (useDocument).
 * @param {{show: (message: string, action: {label: string, run: () => void}) => any, dismiss: (id: any) => void, label: string}} o
 */
export function createUndoNotice({ show, dismiss, label }) {
    let current = null; // { id, version }
    const clear = () => {
        if (current) dismiss(current.id);
        current = null;
    };
    return {
        /** After a step: the page is now at `version`; `undo` takes that step back. */
        offer(message, version, undo) {
            clear();
            const mine = { id: null, version };
            mine.id = show(message, {
                label,
                run: () => {
                    if (current !== mine) return; // the page changed since: not this step any more
                    current = null;
                    undo();
                },
            });
            current = mine;
        },
        /** The page changed: an offer made for another version goes away. */
        changed(version) {
            if (current && current.version !== version) clear();
        },
        clear,
        get open() {
            return current !== null;
        },
    };
}
