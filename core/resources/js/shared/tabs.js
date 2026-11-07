/**
 * Keyboard moves in a row of tabs (the ARIA tabs pattern): arrows go to the next or
 * previous tab and wrap around, Home and End to the first and last.
 * @returns {number|null} the tab to show, or null when the key isn't one of these
 */
export function tabFromKey(key, current, count) {
    if (count < 1) return null;
    switch (key) {
        case 'ArrowRight':
        case 'ArrowDown':
            return (current + 1) % count;
        case 'ArrowLeft':
        case 'ArrowUp':
            return (current - 1 + count) % count;
        case 'Home':
            return 0;
        case 'End':
            return count - 1;
        default:
            return null;
    }
}

/** The ids that tie a tab to its panel, from the tabs block's id. */
export const tabId = (group, i) => `k-tab-${group}-${i}`;
export const panelId = (group, i) => `k-panel-${group}-${i}`;

/** What the tabs block gives its panels (provide/inject). */
export const TABS = Symbol('k-tabs');
