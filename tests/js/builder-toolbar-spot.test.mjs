import { test } from 'node:test';
import assert from 'node:assert/strict';
import { bestLeft, toolbarSpot } from '../../core/resources/js/ui/builder/toolbar-spot.js';
import { bandColumns, freeRoom, isContent } from '../../core/resources/js/app/canvas/room.js';

// a page 800px wide at x 100 in the canvas section; columns of 20px
const frame = { top: 0, left: 100, width: 800, height: 700 };
const size = { width: 300, height: 38 };
const COLS = 40;
const free = () => Array(COLS).fill(0);
const full = () => Array(COLS).fill(1);
/** columns covered between two page x positions (relative to the frame) */
const busy = (from, to) => free().map((_, i) => (i * 20 + 10 >= from && i * 20 + 10 <= to ? 1 : 0));
const room = (above, below, inside = null) => ({ step: 20, above, below, inside });
const spot = (block, r) => toolbarSpot({ block, frame, size, room: r });
const B = { top: 300, left: 150, width: 500, height: 80 };

test('above the block when that covers nothing, at its left edge', () => {
    assert.deepEqual(spot(B, room(free(), free())), { top: 256, left: 150, side: 'above', cover: 0 });
    assert.equal(spot(B, null).side, 'above', 'no report: above, as before');
});

test('below when above is taken and below is free', () => {
    assert.deepEqual(spot(B, room(full(), free())), { top: 386, left: 150, side: 'below', cover: 0 });
});

test('it slides sideways to a free stretch of a band', () => {
    // a heading fills the band above; below, buttons sit in the middle (page x 320 to 600): the free stretch on the left
    const r = spot({ top: 300, left: 110, width: 700, height: 40 }, room(full(), busy(320, 600)));
    assert.equal(r.side, 'below');
    assert.equal(r.cover, 0);
    assert.ok(r.left + size.width <= 100 + 320, `left of the buttons: ${r.left}`);
});

test("inside the block's top edge when above and below are taken and the padding is free", () => {
    assert.deepEqual(spot({ ...B, height: 300 }, room(full(), full(), free())), { top: 306, left: 156, side: 'inside', cover: 0 });
    assert.notEqual(spot({ ...B, height: 300 }, room(full(), full(), full())).side, 'inside', "not over the block's own content");
    assert.equal(spot({ ...B, height: 300 }, room(full(), full(), busy(0, 100))).side, 'inside', 'it slides past content in its padding');
});

test('when everything covers something: the least, above on a tie', () => {
    assert.equal(spot(B, room(busy(0, 800), busy(0, 200))).side, 'below');
    assert.equal(spot(B, room(busy(0, 800), busy(0, 800))).side, 'above');
});

test('no band on screen: pinned to the top for a block taller than the screen', () => {
    assert.equal(spot({ top: 10, left: 150, width: 500, height: 80 }, room(free(), free())).side, 'below', 'no room above on screen');
    assert.deepEqual(spot({ top: -400, left: 150, width: 500, height: 1500 }, room(free(), free())), { top: 4, left: 150, side: 'pinned', cover: 1 });
});

test('always inside the page horizontally', () => {
    assert.equal(spot({ top: 300, left: 750, width: 100, height: 40 }, null).left, 100 + 800 - 300 - 4, 'pulled in from the right');
    assert.equal(spot({ top: 300, left: 20, width: 100, height: 40 }, null).left, 104, 'and from the left');
});

test('a toolbar wider than the page (phone preview) is centred over it', () => {
    const phone = { top: 0, left: 300, width: 390, height: 700 };
    const r = toolbarSpot({ block: { top: 300, left: 310, width: 370, height: 60 }, frame: phone, size: { width: 410, height: 38 }, room: null });
    assert.equal(r.left, 290, 'reaching 10px past each side');
});

test('bestLeft: least cover, nearest the preferred edge among equals', () => {
    const cols = [1, 1, 0, 0, 0, 0, 1, 1]; // 20px columns from x 0
    assert.deepEqual(bestLeft(cols, 20, 0, 80, 0, 0, 80), { left: 40, cover: 0 });
    assert.deepEqual(bestLeft(cols, 20, 0, 80, 60, 0, 80), { left: 40, cover: 0 }, 'the nearest free one');
    assert.deepEqual(bestLeft([0, 0, 0, 0], 20, 0, 40, 20, 0, 40), { left: 20, cover: 0 }, 'stays where it is when that is free');
});

// fake elements for the canvas probe
const text = (t) => ({ nodeType: 3, textContent: t });
function el(tag, kids = [], inner = []) {
    const e = { tagName: tag, childNodes: kids, inner };
    e.contains = (x) => x === e || inner.some((i) => i === x || i.contains?.(x));
    return e;
}

test('isContent: text and media count; the block and its own backgrounds do not', () => {
    const block = el('DIV', [text('Hello')]);
    const section = el('SECTION', [text('  ')], [block]);
    assert.equal(isContent(el('H2', [text('Spring opening')]), block), true);
    assert.equal(isContent(el('IMG'), block), true);
    assert.equal(isContent(el('DIV', [text('\n  ')]), block), false, 'whitespace only');
    assert.equal(isContent(section, block), false, 'an ancestor: its background');
    assert.equal(isContent(block, block), false);
});

test('bandColumns and freeRoom: each strip says how much content is under it', () => {
    const block = el('DIV', [text('Body')]);
    const section = el('SECTION', [], [block]);
    const title = el('H1', [text('Spring opening')]);
    const button = el('A', [text('Order')]);
    const viewport = { width: 200, height: 800 };
    const box = { top: 300, left: 0, width: 200, height: 200 };
    // the title above; below, a button over the right half; the block's top padding is just the section
    const hitsAt = (x, y) => (y < 300 ? [title, section] : y < 500 ? (y < 340 ? [section] : [block, section]) : x > 100 ? [button, section] : [section]);
    const r = freeRoom(box, hitsAt, block, { viewport });
    assert.equal(r.step, 20);
    assert.deepEqual(r.above, Array(10).fill(1));
    assert.deepEqual(r.below, [0, 0, 0, 0, 0, 1, 1, 1, 1, 1]);
    assert.deepEqual(r.inside, Array(10).fill(0));
    assert.equal(freeRoom({ ...box, height: 60 }, hitsAt, block, { viewport }).inside, null, 'too short to hold it inside');
    assert.deepEqual(
        bandColumns(-30, () => [section], block, { height: 44, step: 20, viewport }),
        Array(10).fill(1),
        'off screen is covered',
    );
    assert.deepEqual(
        bandColumns(0, () => [section], block, { height: 44, step: 20, viewport, within: [50, 130] }),
        [1, 1, 0, 0, 0, 0, 0, 1, 1, 1],
        'outside the block (x 50 to 130), for inside',
    );
});
