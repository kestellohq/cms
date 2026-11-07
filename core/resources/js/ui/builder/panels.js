/**
 * The side panels on a narrower screen (usePanels): one at a time, so the page keeps its
 * room instead of being covered. The one just opened stays; when both were open already
 * (the window got narrower), the settings panel stays.
 *
 * @param {{left:string, right:boolean}} prev  before the change
 * @param {{left:string, right:boolean}} next  after it
 * @param {boolean} narrow                     the screen has room for one panel only
 * @returns {{left:string, right:boolean}}
 */
export function onePanel(prev, next, narrow) {
    if (!narrow || !next.left || !next.right) return next;
    const leftJustOpened = !prev.left || prev.left !== next.left;
    const rightJustOpened = !prev.right;
    if (leftJustOpened && !rightJustOpened) return { left: next.left, right: false };
    return { left: '', right: true };
}

/** The width below which only one side panel fits beside the page (matches builder.css). */
export const ONE_PANEL_BELOW = 1180;
