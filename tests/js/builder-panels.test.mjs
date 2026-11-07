import { test } from 'node:test';
import assert from 'node:assert/strict';
import { onePanel } from '../../core/resources/js/ui/builder/panels.js';

test('on a wide screen both panels may be open', () => {
    assert.deepEqual(onePanel({ left: '', right: true }, { left: 'layers', right: true }, false), { left: 'layers', right: true });
});

test('on a narrow screen the panel just opened stays, the other closes', () => {
    assert.deepEqual(
        onePanel({ left: '', right: true }, { left: 'add', right: true }, true),
        { left: 'add', right: false },
        'opening the library closes settings',
    );
    assert.deepEqual(
        onePanel({ left: 'layers', right: false }, { left: 'layers', right: true }, true),
        { left: '', right: true },
        'opening settings closes layers',
    );
    assert.deepEqual(
        onePanel({ left: 'layers', right: true }, { left: 'find', right: true }, true),
        { left: 'find', right: false },
        'switching the left panel counts as opening it',
    );
});

test('both open when the screen gets narrower: settings stays', () => {
    assert.deepEqual(onePanel({ left: 'layers', right: true }, { left: 'layers', right: true }, true), { left: '', right: true });
});

test('closing a panel, or one panel alone, is left as it is', () => {
    assert.deepEqual(onePanel({ left: 'layers', right: false }, { left: '', right: false }, true), { left: '', right: false });
    assert.deepEqual(onePanel({ left: '', right: false }, { left: 'add', right: false }, true), { left: 'add', right: false });
});
