/**
 * The server prepares blocks for the canvas (checks, cleans and fills them in).
 * Each top-level block is prepared on its own terms and remembered by its
 * content, so only blocks it hasn't seen in this form are sent: undo, redo and
 * moving things around need no round trip.
 */
import { preparedGet, preparedSet } from './cache.js';

/**
 * @param {object[]} nodes  the tree's top-level blocks
 * @param {string} scope    what else the result depends on (the document, the entry previewed with, the revision)
 * @param {(blocks: object[]) => Promise<object[]>} fetchPrepared  prepares blocks on the server
 * @returns {Promise<object[]>} the prepared blocks, in order; a block the server left out (it didn't validate) is skipped
 */
export async function prepareTree(nodes, scope, fetchPrepared) {
    const keys = nodes.map((n) => `${scope}:${JSON.stringify(n)}`);
    const found = keys.map((k) => preparedGet(k));
    const missing = nodes.filter((_, i) => found[i] === undefined);
    if (missing.length) {
        const out = await fetchPrepared(JSON.parse(JSON.stringify(missing)));
        const byId = new Map((Array.isArray(out) ? out : []).map((n) => [n?.id, n]));
        nodes.forEach((n, i) => {
            if (found[i] !== undefined) return;
            found[i] = byId.get(n.id) ?? null;
            preparedSet(keys[i], found[i]);
        });
    }
    return found.filter(Boolean);
}
