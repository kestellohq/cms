import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { cssValue, designAttrs, effective, PROPS, SCREENS, token, varName, withValue } from '../../core/resources/js/shared/block-design.js';

test("values: presets read the theme's custom properties; custom values are checked", () => {
    assert.equal(cssValue('space', 'lg'), 'var(--k-space-lg)');
    assert.equal(cssValue('color', 'plate'), 'var(--k-color-plate)');
    assert.equal(cssValue('font', '2xl'), 'var(--k-font-2xl)', 'a preset name may start with one digit');
    assert.equal(cssValue('space', '1rem'), '1rem', 'a length is a length, not a preset');
    assert.equal(cssValue('space', '24'), null, 'a bare number is neither');
    assert.equal(cssValue('radius', 'full'), 'var(--k-radius-full)');
    assert.equal(cssValue('color', '#1E3A8A'), '#1E3A8A');
    assert.equal(cssValue('space', '1.5rem'), '1.5rem');
    assert.equal(cssValue('space', '0'), '0');
    assert.equal(cssValue('width', '2px'), '2px');
    assert.equal(cssValue('width', '2rem'), null);
    assert.equal(cssValue('line', 'dashed'), 'dashed');
    for (const bad of ['red;', 'url(x)', '1px; color: red', '-1rem', '12pt', '', null, 4]) {
        assert.equal(cssValue('space', bad), null, String(bad));
        assert.equal(cssValue('color', bad), null, String(bad));
    }
});

test('designAttrs: custom properties and the list of what is set, per screen size', () => {
    const attrs = designAttrs({
        base: { background: 'cream', 'padding-top': 'lg' },
        tablet: { 'padding-top': 'md' },
        phone: { 'font-size': '18px', shadow: 'x', text: 'red; }' },
    });
    assert.deepEqual(attrs, {
        style: {
            '--kd-background': 'var(--k-color-cream)',
            '--kd-padding-top': 'var(--k-space-lg)',
            '--kd-padding-top-tablet': 'var(--k-space-md)',
            '--kd-font-size-phone': '18px',
        },
        'data-k-d': 'background padding-top padding-top@tablet font-size@phone',
    });
    assert.equal(designAttrs(null), null);
    assert.equal(designAttrs({ base: { shadow: '1px' } }), null, 'nothing valid: nothing at all');
});

test("effective: a smaller screen shows its own value, else the wider one's", () => {
    const d = { base: { 'padding-top': 'lg' }, phone: { 'padding-top': 'sm' } };
    assert.deepEqual(effective(d, 'padding-top', 'tablet'), { value: 'lg', from: 'base' });
    assert.deepEqual(effective(d, 'padding-top', 'phone'), { value: 'sm', from: 'phone' });
    assert.deepEqual(effective(d, 'radius', 'phone'), { value: null, from: null });
});

test('withValue sets and clears, and drops what is left empty', () => {
    let d = withValue(null, 'base', 'radius', 'md');
    assert.deepEqual(d, { base: { radius: 'md' } });
    d = withValue(d, 'phone', 'radius', '0');
    assert.deepEqual(d, { base: { radius: 'md' }, phone: { radius: '0' } });
    d = withValue(d, 'phone', 'radius', null);
    assert.deepEqual(d, { base: { radius: 'md' } });
    assert.equal(withValue(d, 'base', 'radius', ''), null, 'all cleared: no design');
});

test('the stylesheet applies every style on every screen size', () => {
    const css = readFileSync(new URL('../../core/resources/js/shared/block-layout.css', import.meta.url), 'utf8');
    for (const prop of Object.keys(PROPS)) {
        for (const screen of SCREENS) {
            assert.ok(css.includes(`[data-k-d~="${token(prop, screen)}"]:not(#k-d)`), `rule for ${token(prop, screen)}`);
            assert.ok(css.includes(`var(${varName(prop, screen)})`), `${varName(prop, screen)} is used`);
            assert.ok(css.includes(`@property ${varName(prop, screen)} {`), `${varName(prop, screen)} doesn't inherit`);
        }
    }
});
