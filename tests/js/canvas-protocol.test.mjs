import { test } from 'node:test';
import assert from 'node:assert/strict';
import { blockCommand, linkFromInput, safeField, safeId, safeLink } from '../../core/resources/js/shared/canvas-protocol.js';
import { keyLabel } from '../../core/resources/js/ui/builder/shortcuts.js';

test('ids and field names are checked before they go into selectors', () => {
    assert.equal(safeId('a1b2c3d4'), 'a1b2c3d4');
    assert.equal(safeId('x"] , body'), null);
    assert.equal(safeId(42), null);
    assert.equal(safeField('text'), 'text');
    assert.equal(safeField('Text'), null);
    assert.equal(safeField('a-b'), null);
});

test('links: safe schemes only; a bare address becomes https', () => {
    assert.equal(safeLink(' https://a.test '), 'https://a.test');
    assert.equal(safeLink('/about'), '/about');
    assert.equal(safeLink('//evil.test'), null);
    assert.equal(safeLink('javascript:alert(1)'), null);
    assert.equal(linkFromInput('example.com/bread'), 'https://example.com/bread');
    assert.equal(linkFromInput('localhost:8080/x'), 'https://localhost:8080/x');
    assert.equal(linkFromInput('mailto:a@b.test'), 'mailto:a@b.test');
    assert.equal(linkFromInput('#top'), '#top');
    assert.equal(linkFromInput('javascript:alert(1)'), null);
    assert.equal(linkFromInput('data:text/html,x'), null);
    assert.equal(linkFromInput('   '), null);
});

test('block commands are the same keys on the page and around it', () => {
    const k = (key, mods = {}, code = '') => blockCommand({ key, code, metaKey: false, ctrlKey: false, altKey: false, shiftKey: false, ...mods });
    assert.equal(k('ç', { metaKey: true, altKey: true }, 'KeyC'), 'copy-styles', "by the key's place: Alt changes the letter on a Mac");
    assert.equal(k('√', { ctrlKey: true, altKey: true }, 'KeyV'), 'paste-styles');
    assert.equal(k('c', { metaKey: true }, 'KeyC'), null, "plain copy stays the browser's");
    assert.equal(k('f', { metaKey: true, shiftKey: true }, 'KeyF'), 'find');
    assert.equal(k('f', { metaKey: true }, 'KeyF'), null, "the browser's own find stays");
    assert.equal(k('z', { metaKey: true }), 'undo');
    assert.equal(k('Z', { ctrlKey: true, shiftKey: true }), 'redo');
    assert.equal(k('y', { ctrlKey: true }), 'redo');
    assert.equal(k('s', { metaKey: true }), 'save');
    assert.equal(k('D', { metaKey: true, shiftKey: true }), 'duplicate');
    assert.equal(k('Ω', { altKey: true, shiftKey: true }, 'KeyZ'), 'remove-block', 'by key position, whatever the layout types');
    assert.equal(k('†', { metaKey: true, altKey: true }, 'KeyT'), 'insert-before');
    assert.equal(k('¥', { metaKey: true, altKey: true }, 'KeyY'), 'insert-after');
    assert.equal(k('d', { metaKey: true }), null, "Mod+D alone is the browser's");
    assert.equal(k('a'), null);
});

test('shortcut labels follow the computer', () => {
    assert.equal(keyLabel('Mod+Shift+D', true), '⌘⇧D');
    assert.equal(keyLabel('Mod+Shift+D', false), 'Ctrl+Shift+D');
    assert.equal(keyLabel('Alt+Shift+Z', true), '⌥⇧Z');
});
