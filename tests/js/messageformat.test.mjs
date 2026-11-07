import test from 'node:test';
import assert from 'node:assert/strict';
import { format, parse } from '../../core/resources/js/sdk/messageformat.js';

test('plain text and {name} arguments', () => {
    assert.equal(format('Save changes'), 'Save changes');
    assert.equal(format('Hello, {name}!', { name: 'Ana' }), 'Hello, Ana!');
    assert.equal(format('Hello, {name}!', {}), 'Hello, {name}!', 'a missing argument stays visible');
    assert.equal(format('{a} and {b}', { a: 1, b: 'two' }), '1 and two');
});

test('plural: exact cases, categories per locale, # and offset', () => {
    const files = '{count, plural, =0 {No files} one {# file} other {# files}}';
    assert.equal(format(files, { count: 0 }), 'No files');
    assert.equal(format(files, { count: 1 }), '1 file');
    assert.equal(format(files, { count: 1234 }), '1,234 files', '# is a formatted number');
    const sr = '{count, plural, one {# fajl} few {# fajla} other {# fajlova}}';
    assert.deepEqual(
        [1, 3, 5, 21, 22].map((count) => format(sr, { count }, 'sr')),
        ['1 fajl', '3 fajla', '5 fajlova', '21 fajl', '22 fajla'],
    );
    const others = '{n, plural, offset:1 =0 {Nobody} =1 {Only {name}} one {{name} and # other} other {{name} and # others}}';
    assert.equal(format(others, { n: 1, name: 'Ana' }), 'Only Ana');
    assert.equal(format(others, { n: 2, name: 'Ana' }), 'Ana and 1 other');
    assert.equal(format(others, { n: 4, name: 'Ana' }), 'Ana and 3 others');
    assert.equal(format('{n, selectordinal, one {#st} two {#nd} few {#rd} other {#th}}', { n: 22 }), '22nd');
});

test('select, numbers and dates', () => {
    const role = '{role, select, admin {Administrator} editor {Editor} other {Member}}';
    assert.equal(format(role, { role: 'admin' }), 'Administrator');
    assert.equal(format(role, { role: 'guest' }), 'Member');
    assert.equal(format('{n, number, percent}', { n: 0.25 }), '25%');
    assert.equal(format('{n, number, integer}', { n: 3.7 }), '4');
    assert.equal(format('{n, number}', { n: 1234.5 }, 'de'), '1.234,5');
    assert.equal(format('{d, date, short}', { d: new Date(Date.UTC(2026, 10, 6, 12)) }, 'en-GB'), '06/11/2026');
});

test('apostrophes quote braces, and two make one', () => {
    assert.equal(format("Use '{name}' literally", { name: 'x' }), 'Use {name} literally');
    assert.equal(format("It''s {name}''s", { name: 'Ana' }), "It's Ana's");
    assert.equal(format("Don't worry"), "Don't worry", 'a lone apostrophe is text');
    assert.equal(format("{n, plural, other {'#' is # }}", { n: 2 }), '# is 2 ');
});

test('broken messages fall back to {name} replacement', () => {
    const warn = console.warn;
    const seen = [];
    console.warn = (m) => seen.push(m);
    try {
        assert.equal(format('{count, plural, one {# file}} for {name}', { count: 2, name: 'Ana' }), '{count, plural, one {# file}} for Ana');
        assert.equal(format('{oops', { oops: 1 }), '{oops');
        format('{oops', {});
    } finally {
        console.warn = warn;
    }
    assert.equal(seen.length, 2, 'each broken message is reported once');
    assert.throws(() => parse('{n, plural, one {x}}'), /needs an "other" case/);
    assert.throws(() => parse('a } b'), /unexpected "}"/);
});
