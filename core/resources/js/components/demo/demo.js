/**
 * A public demo (config app.demo) tells every page so through the "demo"
 * page prop: the account to sign in with and when the site resets.
 */

/**
 * Whole minutes until the next reset, at least 1; null when it is not known
 * (before the first snapshot) or overdue (the reset runs with the next
 * scheduled task).
 *
 * @param {?number} nextReset unix seconds
 * @param {number} now unix milliseconds
 */
export function minutesUntil(nextReset, now) {
    if (typeof nextReset !== 'number' || !Number.isFinite(nextReset)) return null;
    const seconds = nextReset - Math.floor(now / 1000);
    return seconds <= 0 ? null : Math.max(1, Math.ceil(seconds / 60));
}
