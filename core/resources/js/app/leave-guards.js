/**
 * Unsaved work holds a page: the router asks before a visit that would leave
 * it (a link, the menu, Back), and the page asks the browser on reload or close.
 * Pages add a guard with useLeaveGuard (@kestello/sdk); the router only checks.
 */

/** A set of guards: each answers whether its page has unsaved work right now. */
export function createLeaveGuards() {
    const guards = new Set();

    return {
        /** @param {() => unknown} guard @returns {() => void} removes it */
        add(guard) {
            guards.add(guard);
            return () => guards.delete(guard);
        },
        /** True while any guard reports unsaved work. A guard that throws counts as none. */
        holding() {
            for (const guard of guards) {
                try {
                    if (guard()) return true;
                } catch {
                    // a broken guard must not trap people on the page
                }
            }
            return false;
        },
    };
}

/**
 * Whether a visit replaces the screen: a GET to another path. The same path with
 * another query or hash, a refresh that keeps state, and sent forms stay.
 */
export function leavesPage(from, to, { method = 'get', preserveState = false } = {}) {
    return String(method).toLowerCase() === 'get' && !preserveState && (from.origin !== to.origin || from.pathname !== to.pathname);
}
