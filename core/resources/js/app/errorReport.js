// Errors the admin hits in the browser go to the site (POST /api/v1/system/client-errors),
// so they reach its log and Site Health instead of one person's console. The same error is
// sent once, at most `limit` per page load, and sending never throws: reporting an error
// must not cause another.

const MAX = { message: 500, screen: 300, where: 200, stack: 4000 };

const cut = (value, length) => String(value ?? '').slice(0, length);

/** A line that says what was thrown: an Error, a string, or anything else a promise rejected with. */
function describe(error) {
    if (error === undefined || error === null) return '';
    if (error instanceof Error) return `${error.name}: ${error.message}`;
    if (typeof error === 'string') return error;
    try {
        return JSON.stringify(error) ?? String(error);
    } catch {
        return String(error);
    }
}

/** The fields the endpoint takes, from whatever was thrown. */
export function errorPayload(error, where, screen) {
    const message = describe(error);
    const payload = { message: cut(message || 'Unknown error', MAX.message), screen: cut(screen, MAX.screen) };
    if (where) payload.where = cut(where, MAX.where);
    if (error instanceof Error && error.stack) payload.stack = cut(error.stack, MAX.stack);
    return payload;
}

/**
 * @param {{ send: (payload: object) => Promise<unknown> | unknown, screen?: () => string, limit?: number }} options
 * @returns {(error: unknown, where?: string) => boolean} true when the error was sent
 */
export function createErrorReporter({ send, screen = () => globalThis.location?.pathname ?? '', limit = 10 }) {
    const seen = new Set();
    let sent = 0;
    return (error, where = '') => {
        try {
            const payload = errorPayload(error, where, screen());
            const key = `${payload.message}|${payload.where ?? ''}|${payload.screen}`;
            if (seen.has(key) || sent >= limit) return false;
            seen.add(key);
            sent++;
            Promise.resolve()
                .then(() => send(payload))
                .catch(() => {});
            return true;
        } catch {
            return false;
        }
    };
}
