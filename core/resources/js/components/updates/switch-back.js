/**
 * Switching back after an update reloads the admin: the next request runs
 * the old code and puts the database back, and the page has to come from
 * that code too. What was switched back is kept in sessionStorage across
 * the reload, so the page can say how it went.
 */

export const STORAGE_KEY = 'kestello.switchedBack';

/** What a page remembers before reloading: who was switched back, and to which version. */
export function switchBackNote(owner, name, version) {
    return JSON.stringify({ owner: String(owner), name: String(name), version: String(version) });
}

/** The note read back after the reload, or null when there is none (or it is not one of ours). */
export function readSwitchBackNote(raw) {
    if (typeof raw !== 'string') return null;
    try {
        const note = JSON.parse(raw);
        const valid =
            note &&
            typeof note === 'object' &&
            [note.owner, note.name, note.version].every((v) => typeof v === 'string' && v.length > 0 && v.length <= 200) &&
            /^(core|(plugin|theme):[a-z0-9]+(?:-[a-z0-9]+)*)$/.test(note.owner);
        return valid ? { owner: note.owner, name: note.name, version: note.version } : null;
    } catch {
        return null;
    }
}

/**
 * How it went, from what the page loaded after the reload.
 *
 * @param {{owner: string, version: string}} note
 * @param {?string} running the version of note.owner the site runs now
 * @param {?object} stopped the update that stopped halfway, if there is one
 * @returns {'done' | 'stopped' | 'not-done'}
 */
export function switchBackOutcome(note, running, stopped) {
    if (stopped && stopped.owner === note.owner) return 'stopped';
    return running === note.version ? 'done' : 'not-done';
}

/** Remember, read once, forget: storage can be missing (private windows), and that only costs the message. */
export function rememberSwitchBack(owner, name, version) {
    try {
        sessionStorage.setItem(STORAGE_KEY, switchBackNote(owner, name, version));
    } catch {
        /* no message after the reload */
    }
}

export function takeSwitchBack() {
    try {
        const raw = sessionStorage.getItem(STORAGE_KEY);
        sessionStorage.removeItem(STORAGE_KEY);
        return readSwitchBackNote(raw);
    } catch {
        return null;
    }
}
