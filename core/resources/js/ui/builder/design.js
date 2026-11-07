import { PROPS, SCREENS } from '../../shared/block-design.js';

/**
 * The editor's side of block design (the styles panel, BuilderDesign.vue): what
 * someone types becomes a value BlockDesign accepts, and the preview device
 * picks the screen size being styled. What the values mean on the page is in
 * shared/block-design.js.
 */

/** The screen size styled while the canvas shows a device: desktop styles everything (base). */
export const screenOf = (device) => (device === 'tablet' || device === 'phone' ? device : 'base');

/** The device that shows a screen size's styles. */
export const deviceOf = (screen) => (screen === 'base' ? 'desktop' : screen);

const LENGTH = /^(\d{1,4}(?:\.\d{1,3})?)(px|rem|em|%|vw)?$/;

/**
 * A length as typed: "24" is 24px, "1.5rem" stays, "0" is 0, spaces and case don't matter.
 * Null when it isn't one (the field then says so, and nothing changes).
 * @param {string} text
 * @param {string[]} units  the units this style takes
 */
export function parseLength(text, units = ['px', 'rem', 'em', '%', 'vw']) {
    const m = LENGTH.exec(
        String(text ?? '')
            .trim()
            .toLowerCase(),
    );
    if (!m) return null;
    if (Number(m[1]) === 0) return '0';
    const unit = m[2] ?? 'px';
    return units.includes(unit) ? `${m[1]}${unit}` : null;
}

/** A colour as typed or picked: "#1E3A8A", "1e3a8a", "#abc". Null when it isn't one. */
export function parseColor(text) {
    const t = String(text ?? '')
        .trim()
        .replace(/^#?/, '#')
        .toLowerCase();
    return /^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/.test(t) ? t : null;
}

/** A 3-digit colour as 6 digits, for <input type="color">, which takes only those. */
export function fullHex(color) {
    if (!/^#[0-9a-f]{3}$/i.test(color ?? '')) return color;
    return `#${color
        .slice(1)
        .split('')
        .map((c) => c + c)
        .join('')}`;
}

/** Are all four sides the same (padding shown as one control)? */
export function sameSides(values) {
    const [a, ...rest] = values;
    return rest.every((v) => (v ?? null) === (a ?? null));
}

/** The design without one screen size's styles; clearing desktop's (base) clears them all. Null when nothing is left. */
export function withoutScreen(design, screen) {
    if (!design || screen === 'base') return null;
    const { [screen]: _gone, ...rest } = design;
    return Object.keys(rest).length ? rest : null;
}

/**
 * The styles one block can take from another (Paste styles): only those in the groups
 * the receiving block supports, on every screen size. Null when none are left.
 * @param {object|null} design   the copied block's design
 * @param {string[]} supports    the receiving block's groups (BlockDefinition::supports)
 */
export function stylesFitting(design, supports) {
    const out = {};
    for (const screen of SCREENS) {
        const values = Object.entries(design?.[screen] ?? {}).filter(([prop]) => supports.includes(PROPS[prop]?.group));
        if (values.length) out[screen] = Object.fromEntries(values);
    }
    return Object.keys(out).length ? out : null;
}
