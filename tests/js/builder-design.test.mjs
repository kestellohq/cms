import { test } from 'node:test';
import assert from 'node:assert/strict';
import { deviceOf, fullHex, parseColor, parseLength, sameSides, screenOf, stylesFitting, withoutScreen } from '../../core/resources/js/ui/builder/design.js';

test('the device shown is the screen size styled', () => {
    assert.equal(screenOf('desktop'), 'base');
    assert.equal(screenOf('tablet'), 'tablet');
    assert.equal(screenOf('phone'), 'phone');
    assert.equal(screenOf('nonsense'), 'base');
    assert.equal(deviceOf('base'), 'desktop');
    assert.equal(deviceOf('phone'), 'phone');
});

test('lengths as typed', () => {
    assert.equal(parseLength('24'), '24px');
    assert.equal(parseLength(' 1.5REM '), '1.5rem');
    assert.equal(parseLength('0rem'), '0');
    assert.equal(parseLength('50%', ['px']), null, 'a unit the style does not take');
    for (const bad of ['', '-4', '1e3', '12pt', 'calc(1px)', '24 px', null]) assert.equal(parseLength(bad), null, String(bad));
});

test('colours as typed', () => {
    assert.equal(parseColor('1E3A8A'), '#1e3a8a');
    assert.equal(parseColor('#abc'), '#abc');
    assert.equal(parseColor('red'), null);
    assert.equal(parseColor('#12345'), null);
    assert.equal(fullHex('#abc'), '#aabbcc');
    assert.equal(fullHex('#1e3a8a'), '#1e3a8a');
});

test('sides and clearing', () => {
    assert.equal(sameSides(['md', 'md', 'md', 'md']), true);
    assert.equal(sameSides([null, undefined, null, null]), true);
    assert.equal(sameSides(['md', 'md', 'lg', 'md']), false);
    const d = { base: { radius: 'md' }, phone: { radius: '0' } };
    assert.deepEqual(withoutScreen(d, 'phone'), { base: { radius: 'md' } });
    assert.equal(withoutScreen(d, 'base'), null, 'desktop styles everything: clearing it clears all');
    assert.equal(withoutScreen({ phone: { radius: '0' } }, 'phone'), null);
});

test('pasted styles keep only what the receiving block supports, on every screen size', () => {
    const copied = { base: { background: 'cream', 'padding-top': 'lg', 'font-size': 'xl' }, phone: { 'padding-top': 'sm', radius: '4px' } };
    assert.deepEqual(stylesFitting(copied, ['spacing', 'type']), { base: { 'padding-top': 'lg', 'font-size': 'xl' }, phone: { 'padding-top': 'sm' } });
    assert.deepEqual(stylesFitting(copied, ['color']), { base: { background: 'cream' } });
    assert.equal(stylesFitting(copied, []), null, 'a block without style settings takes none');
    assert.equal(stylesFitting(null, ['color']), null, 'nothing copied: nothing to give');
    assert.equal(stylesFitting({ base: { 'made-up': '1px' } }, ['color', 'spacing']), null, 'unknown styles are never passed on');
});
