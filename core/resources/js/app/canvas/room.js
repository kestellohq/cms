/**
 * Is there free room just above and just below the selected block for the editor's
 * toolbar, or would it cover something a visitor reads? The canvas answers this (it
 * has the page); ui/builder/toolbar-spot.js then decides where the toolbar goes.
 *
 * Pure but for hitsAt(x, y): the elements under a point, topmost first
 * (document.elementsFromPoint), so it is tested with plain objects.
 */

/** Elements that show something by themselves, even without text. */
const MEDIA = new Set(['IMG', 'PICTURE', 'VIDEO', 'SVG', 'svg', 'IFRAME', 'CANVAS', 'INPUT', 'BUTTON', 'SELECT', 'TEXTAREA']);

/** Would covering this element hide content? Backgrounds of the block's own ancestors don't count. */
export function isContent(el, block) {
    if (!el || el === block || el.contains?.(block)) return false;
    if (MEDIA.has(el.tagName)) return true;
    for (const n of el.childNodes ?? []) {
        if (n.nodeType === 3 && n.textContent.trim() !== '') return true;
    }
    return false;
}

/**
 * How much content each strip of a band holds: the band is `height` px tall from `from`,
 * cut into columns `step` px wide across the page; each column is 0 (free) to 1 (covered at
 * every probe), and columns off screen or outside `within` count as covered.
 * @param {number} from  the band's top, viewport px
 * @param {(x:number, y:number) => Element[]} hitsAt
 * @param {object} block
 * @param {{height:number, step:number, viewport:{width:number,height:number}, within?:[number,number]|null}} o
 * @returns {number[]}
 */
export function bandColumns(from, hitsAt, block, { height, step, viewport, within = null }) {
    const ys = [from + 4, from + height / 2, from + height - 4];
    const offScreen = ys.some((y) => y < 0 || y > viewport.height);
    const cols = [];
    for (let x = step / 2; x < viewport.width; x += step) {
        if (offScreen || (within && (x < within[0] || x > within[1]))) {
            cols.push(1);
            continue;
        }
        let hit = 0;
        for (const y of ys) {
            for (const el of hitsAt(x, y)) {
                if (el === block || el.contains?.(block)) break; // past the content: only the block's own backgrounds below
                if (isContent(el, block)) {
                    hit++;
                    break;
                }
            }
        }
        cols.push(Math.round((hit / ys.length) * 100) / 100);
    }
    return cols;
}

/**
 * The bands the toolbar could take: just above the block, just below it, and inside its top
 * edge (a section's empty padding; only for a block tall enough to hold it), as columns of
 * how much content each strip holds (bandColumns). ui/builder/toolbar-spot.js picks from them.
 * @param {{top:number,left:number,width:number,height:number}} box  the block, in viewport px
 * @returns {{step:number, above:number[], below:number[], inside:number[]|null}}
 */
export function freeRoom(box, hitsAt, block, { height = 44, step = 20, viewport = { width: 1280, height: 800 } } = {}) {
    const o = { height, step, viewport };
    return {
        step,
        above: bandColumns(box.top - height, hitsAt, block, o),
        below: bandColumns(box.top + box.height, hitsAt, block, o),
        inside: box.height >= height * 2 ? bandColumns(box.top, hitsAt, block, { ...o, within: [box.left, box.left + box.width] }) : null,
    };
}
