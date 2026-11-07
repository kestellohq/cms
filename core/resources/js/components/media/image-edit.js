/**
 * The image editor's model, pure so it can be tested in Node.
 *
 * Matches the server (ImageEdit.php): the original is turned clockwise
 * (rotate), then mirrored (flipH, flipV), then cropped. The crop is x, y,
 * w, h as fractions of the turned and mirrored picture, the one on screen.
 */

const MIN_PX = 8;
const clamp = (n, lo, hi) => Math.min(hi, Math.max(lo, n));
const FULL = Object.freeze({ x: 0, y: 0, w: 1, h: 1 });

/** Editor state from the API's `edit` (or null for an unedited image). */
export function fromMedia(edit) {
    if (!edit) return { rotate: 0, flipH: false, flipV: false, crop: { ...FULL } };
    const c = edit.crop;
    return {
        rotate: edit.rotate ?? 0,
        flipH: !!edit.flip_horizontal,
        flipV: !!edit.flip_vertical,
        crop: c ? { x: c.x, y: c.y, w: c.width, h: c.height } : { ...FULL },
    };
}

/** [width, height] of the original once turned. */
export function orientedSize(width, height, rotate) {
    return rotate % 180 === 0 ? [width, height] : [height, width];
}

/**
 * A quarter turn, clockwise or not. The crop turns with the picture; a
 * flip made before changes axis, since the server turns first.
 */
export function rotated(s, clockwise) {
    const { x, y, w, h } = s.crop;
    const crop = clockwise ? { x: 1 - (y + h), y: x, w: h, h: w } : { x: y, y: 1 - (x + w), w: h, h: w };
    return { rotate: (s.rotate + (clockwise ? 90 : 270)) % 360, flipH: s.flipV, flipV: s.flipH, crop };
}

/** Mirror left to right (horizontal) or top to bottom; the crop follows. */
export function flipped(s, horizontal) {
    const { x, y, w, h } = s.crop;
    return horizontal ? { ...s, flipH: !s.flipH, crop: { x: 1 - (x + w), y, w, h } } : { ...s, flipV: !s.flipV, crop: { x, y: 1 - (y + h), w, h } };
}

/**
 * The largest centred crop with this width-to-height ratio (null keeps
 * the crop as it is), for a turned picture of width × height pixels.
 */
export function withAspect(s, ratio, width, height) {
    if (!ratio) return s;
    let w = 1;
    let h = width / (ratio * height);
    if (h > 1) {
        h = 1;
        w = (ratio * height) / width;
    }
    return { ...s, crop: { x: (1 - w) / 2, y: (1 - h) / 2, w, h } };
}

/**
 * The crop after dragging a handle ('move', 'n', 'ne', 'e', 'se', 's',
 * 'sw', 'w', 'nw') by dx, dy (fractions of the picture). Stays inside
 * the picture and at least 8 pixels; with a ratio, corners keep the shape.
 *
 * @param {{width: number, height: number, ratio?: number|null}} size the turned picture in pixels
 */
export function dragged(start, handle, dx, dy, { width, height, ratio = null }) {
    const minW = Math.min(1, MIN_PX / width);
    const minH = Math.min(1, MIN_PX / height);
    if (handle === 'move') {
        return { ...start, x: clamp(start.x + dx, 0, 1 - start.w), y: clamp(start.y + dy, 0, 1 - start.h) };
    }
    let left = start.x;
    let top = start.y;
    let right = start.x + start.w;
    let bottom = start.y + start.h;
    if (handle.includes('w')) left = clamp(left + dx, 0, right - minW);
    if (handle.includes('e')) right = clamp(right + dx, left + minW, 1);
    if (handle.includes('n')) top = clamp(top + dy, 0, bottom - minH);
    if (handle.includes('s')) bottom = clamp(bottom + dy, top + minH, 1);
    if (ratio && handle.length === 2) {
        // h (fraction) for a w (fraction) at this ratio: w·width / (h·height) = ratio
        const hFor = (w) => (w * width) / (ratio * height);
        const anchorX = handle.includes('w') ? start.x + start.w : start.x;
        const anchorY = handle.includes('n') ? start.y + start.h : start.y;
        const roomW = handle.includes('w') ? anchorX : 1 - anchorX;
        const roomH = handle.includes('n') ? anchorY : 1 - anchorY;
        let w = Math.max(right - left, minW);
        w = Math.min(w, roomW, (roomH * ratio * height) / width);
        const h = hFor(w);
        left = handle.includes('w') ? anchorX - w : anchorX;
        top = handle.includes('n') ? anchorY - h : anchorY;
        right = left + w;
        bottom = top + h;
    }
    return { x: left, y: top, w: right - left, h: bottom - top };
}

/** Pixel size of the result, for an original of width × height. */
export function resultSize(s, width, height) {
    const [w, h] = orientedSize(width, height, s.rotate);
    return [Math.max(1, Math.round(s.crop.w * w)), Math.max(1, Math.round(s.crop.h * h))];
}

const isFull = (c) => c.x < 1e-6 && c.y < 1e-6 && c.w > 1 - 1e-6 && c.h > 1 - 1e-6;

/** Whether the state leaves the picture as it is. */
export function isIdentity(s) {
    return s.rotate === 0 && !s.flipH && !s.flipV && isFull(s.crop);
}

const r6 = (n) => Math.round(n * 1e6) / 1e6;

/** The body for POST /media/{id}/edit. */
export function toBody(s) {
    const c = s.crop;
    return {
        rotate: s.rotate,
        flip_horizontal: s.flipH,
        flip_vertical: s.flipV,
        crop: isFull(c) ? null : { x: r6(c.x), y: r6(c.y), width: r6(Math.min(c.w, 1 - c.x)), height: r6(Math.min(c.h, 1 - c.y)) },
    };
}
