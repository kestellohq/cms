import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    dragged,
    flipped,
    fromMedia,
    isIdentity,
    orientedSize,
    resultSize,
    rotated,
    toBody,
    withAspect,
} from '../../core/resources/js/components/media/image-edit.js';

const close = (actual, expected, message) => {
    for (const k of Object.keys(expected)) assert.ok(Math.abs(actual[k] - expected[k]) < 1e-9, `${message}: ${k} is ${actual[k]}, expected ${expected[k]}`);
};

test('an unedited image starts whole and upright', () => {
    const s = fromMedia(null);
    assert.deepEqual(s, { rotate: 0, flipH: false, flipV: false, crop: { x: 0, y: 0, w: 1, h: 1 } });
    assert.ok(isIdentity(s));
    assert.deepEqual(toBody(s), { rotate: 0, flip_horizontal: false, flip_vertical: false, crop: null }, 'a full crop is sent as none');
    const back = fromMedia({ rotate: 90, flip_horizontal: true, flip_vertical: false, crop: { x: 0.1, y: 0.2, width: 0.5, height: 0.6 } });
    assert.deepEqual(back, { rotate: 90, flipH: true, flipV: false, crop: { x: 0.1, y: 0.2, w: 0.5, h: 0.6 } }, 'the editor reopens where it was left');
});

test('turning swaps the sides and carries the crop with the picture', () => {
    assert.deepEqual(orientedSize(1200, 800, 90), [800, 1200]);
    assert.deepEqual(orientedSize(1200, 800, 180), [1200, 800]);
    const s = { rotate: 0, flipH: false, flipV: false, crop: { x: 0, y: 0, w: 0.5, h: 0.25 } };
    const cw = rotated(s, true);
    assert.equal(cw.rotate, 90);
    close(cw.crop, { x: 0.75, y: 0, w: 0.25, h: 0.5 }, 'the top-left box is now top-right');
    const back = rotated(cw, false);
    assert.equal(back.rotate, 0);
    close(back.crop, s.crop, 'turning back restores it');
    assert.equal(rotated(rotated(rotated(rotated(s, true), true), true), true).rotate, 0, 'four quarter turns are none');
    assert.equal(rotated(s, false).rotate, 270);
});

test('turned after a flip, the flip changes axis, as the server applies turns first', () => {
    const s = flipped(fromMedia(null), true);
    assert.equal(s.flipH, true);
    const t = rotated(s, true);
    assert.deepEqual([t.rotate, t.flipH, t.flipV], [90, false, true]);
    const box = flipped({ ...fromMedia(null), crop: { x: 0, y: 0.1, w: 0.25, h: 0.5 } }, true).crop;
    close(box, { x: 0.75, y: 0.1, w: 0.25, h: 0.5 }, 'mirrored left to right');
    close(flipped({ ...fromMedia(null), crop: { x: 0, y: 0.1, w: 0.25, h: 0.5 } }, false).crop, { x: 0, y: 0.4, w: 0.25, h: 0.5 }, 'top to bottom');
});

test('an aspect ratio fits the largest centred crop', () => {
    const square = withAspect(fromMedia(null), 1, 1200, 800);
    close(square.crop, { x: 1 / 6, y: 0, w: 800 / 1200, h: 1 }, 'square in a landscape picture');
    const wide = withAspect(fromMedia(null), 16 / 9, 800, 1200);
    close(wide.crop, { x: 0, y: (1 - 450 / 1200) / 2, w: 1, h: 450 / 1200 }, '16:9 in a portrait picture');
    assert.deepEqual(withAspect(square, null, 1200, 800).crop, square.crop, 'free keeps the crop');
    assert.deepEqual(resultSize(square, 1200, 800), [800, 800]);
    assert.deepEqual(resultSize(rotated(fromMedia(null), true), 1200, 800), [800, 1200]);
});

test('dragging moves or resizes the crop and keeps it inside the picture', () => {
    const start = { x: 0.25, y: 0.25, w: 0.5, h: 0.5 };
    const size = { width: 1000, height: 1000 };
    close(dragged(start, 'move', 0.1, -0.1, size), { x: 0.35, y: 0.15, w: 0.5, h: 0.5 }, 'moved');
    close(dragged(start, 'move', 0.9, 0, size), { x: 0.5, y: 0.25, w: 0.5, h: 0.5 }, 'stops at the edge');
    close(dragged(start, 'se', 0.1, 0.2, size), { x: 0.25, y: 0.25, w: 0.6, h: 0.7 }, 'bottom-right corner');
    close(dragged(start, 'nw', -0.5, -0.5, size), { x: 0, y: 0, w: 0.75, h: 0.75 }, 'top-left corner, stopped at the edge');
    close(dragged(start, 'w', 0.6, 0, size), { x: 0.742, y: 0.25, w: 0.008, h: 0.5 }, 'never smaller than 8 pixels');
    close(dragged(start, 'se', 0.25, 0, { ...size, ratio: 1 }), { x: 0.25, y: 0.25, w: 0.75, h: 0.75 }, 'a locked ratio keeps its shape');
    close(dragged(start, 'se', 0.5, 0.5, { ...size, ratio: 1 }), { x: 0.25, y: 0.25, w: 0.75, h: 0.75 }, 'and shrinks to fit');
    close(dragged(start, 'nw', -0.1, 0, { ...size, ratio: 1 }), { x: 0.15, y: 0.15, w: 0.6, h: 0.6 }, 'from the top-left corner too');
});

test('the request body rounds and names things as the API does', () => {
    const s = { rotate: 270, flipH: false, flipV: true, crop: { x: 1 / 3, y: 0, w: 1 / 3, h: 1 } };
    assert.deepEqual(toBody(s), { rotate: 270, flip_horizontal: false, flip_vertical: true, crop: { x: 0.333333, y: 0, width: 0.333333, height: 1 } });
    assert.ok(!isIdentity(s));
});
