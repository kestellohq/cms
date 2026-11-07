/**
 * Where the floating block toolbar goes (BuilderToolbar), in the canvas section's px.
 *
 * The canvas reports three bands the toolbar could sit in (app/canvas/room.js): just above
 * the block, just below it, and inside its top edge (a section's empty padding), each as
 * columns saying how much content that strip holds. In each band the toolbar slides
 * sideways to where it covers the least, staying as close to the block's left edge as it
 * can; the band covering least wins (above, then below, then inside, on a tie). Inside is
 * used only where it covers nothing: that is the block being edited. Without a report the
 * toolbar goes above the block. A block taller than the screen, with no band on screen,
 * gets the toolbar pinned to the top of the visible page. Always inside the page, or centred
 * over it when the toolbar is the wider of the two.
 *
 * @param {object} o
 * @param {{top:number,left:number,width:number,height:number}} o.block   the selected block
 * @param {{top:number,left:number,width:number,height:number}} o.frame   the visible page (the canvas iframe)
 * @param {{width:number,height:number}} o.size                           the toolbar
 * @param {{step:number, above:number[], below:number[], inside:number[]|null}|null} o.room
 * @param {number} [o.gap]  space between toolbar and block
 * @returns {{top:number, left:number, side:'above'|'below'|'inside'|'pinned', cover:number}}
 */
export function toolbarSpot({ block, frame, size, room, gap = 6 }) {
    const minTop = frame.top + 4;
    const maxTop = frame.top + frame.height - size.height - 4;
    // wider than the page (a phone preview): centred over it, reaching into the margins on both sides
    const tooWide = size.width > frame.width - 8;
    const minLeft = tooWide ? Math.max(4, frame.left + (frame.width - size.width) / 2) : frame.left + 4;
    const maxLeft = tooWide ? minLeft : frame.left + frame.width - size.width - 4;
    const clampLeft = (x) => Math.max(minLeft, Math.min(x, maxLeft));
    const onScreen = (top) => top >= minTop && top <= maxTop;

    const bands = [
        { side: 'above', top: block.top - size.height - gap, cols: room?.above, prefer: block.left },
        { side: 'below', top: block.top + block.height + gap, cols: room?.below, prefer: block.left },
        { side: 'inside', top: block.top + gap, cols: room?.inside, prefer: block.left + gap },
    ].filter((b) => onScreen(b.top));

    if (!room) {
        const above = bands.find((b) => b.side === 'above');
        if (above) return { top: Math.round(above.top), left: Math.round(clampLeft(above.prefer)), side: 'above', cover: 0 };
    }
    const options = room
        ? bands.filter((b) => b.cols).map((b) => ({ ...b, ...bestLeft(b.cols, room.step, frame.left, size.width, clampLeft(b.prefer), minLeft, maxLeft) }))
        : [];
    const pick = options.filter((o) => o.side !== 'inside' || o.cover === 0).sort((a, b) => a.cover - b.cover)[0]; // stable: above, below, inside on a tie
    if (pick) return { top: Math.round(pick.top), left: Math.round(pick.left), side: pick.side, cover: pick.cover };

    if (block.top + block.height > minTop + size.height) return { top: Math.round(minTop), left: Math.round(clampLeft(block.left)), side: 'pinned', cover: 1 };
    return { top: Math.round(Math.min(block.top + block.height + gap, maxTop)), left: Math.round(clampLeft(block.left)), side: 'below', cover: 1 };
}

/**
 * Within one band: the left edge where a toolbar `width` wide covers the least content, the
 * nearest to `prefer` among equals. Columns are `step` px wide, starting at `origin`.
 * @returns {{left:number, cover:number}}  cover: the share of the toolbar's columns that hold content
 */
export function bestLeft(cols, step, origin, width, prefer, minLeft, maxLeft) {
    const coverAt = (left) => {
        const first = Math.max(0, Math.floor((left - origin) / step));
        const last = Math.min(cols.length - 1, Math.floor((left + width - 1 - origin) / step));
        if (last < first) return 1;
        let sum = 0;
        for (let i = first; i <= last; i++) sum += cols[i];
        return sum / (last - first + 1);
    };
    const candidates = new Set([prefer, minLeft, maxLeft]);
    for (let x = origin; x <= maxLeft; x += step) if (x >= minLeft) candidates.add(x);
    let best = { left: prefer, cover: coverAt(prefer) };
    for (const left of candidates) {
        const cover = coverAt(left);
        if (cover < best.cover - 1e-9 || (Math.abs(cover - best.cover) < 1e-9 && Math.abs(left - prefer) < Math.abs(best.left - prefer)))
            best = { left, cover };
    }
    return { left: best.left, cover: Math.round(best.cover * 100) / 100 };
}
