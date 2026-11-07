/**
 * What the builder keeps between visits in one browser tab:
 * - the block catalog (shown at once next time, refreshed behind the scenes)
 * - blocks the server has prepared, by their content, so undo, redo and
 *   moving things around need no round trip. Entries expire quickly: some
 *   blocks show other content (entry lists, synced patterns) that can change.
 */
export const catalogCache = { value: null };

const PREPARED_MS = 120_000;
const PREPARED_MAX = 500;
const prepared = new Map(); // key -> { at, node }

export function preparedGet(key) {
    const hit = prepared.get(key);
    if (!hit) return undefined;
    if (Date.now() - hit.at > PREPARED_MS) {
        prepared.delete(key);
        return undefined;
    }
    return hit.node;
}

export function preparedSet(key, node) {
    prepared.delete(key);
    prepared.set(key, { at: Date.now(), node });
    while (prepared.size > PREPARED_MAX) prepared.delete(prepared.keys().next().value);
}
