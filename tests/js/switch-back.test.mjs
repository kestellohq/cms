import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readSwitchBackNote, switchBackNote, switchBackOutcome } from '../../core/resources/js/components/updates/switch-back.js';

test('a note survives the reload as it was written', () => {
    assert.deepEqual(readSwitchBackNote(switchBackNote('plugin:ledger', 'Ledger', '1.0.0')), { owner: 'plugin:ledger', name: 'Ledger', version: '1.0.0' });
    assert.deepEqual(readSwitchBackNote(switchBackNote('core', 'Kestello', '0.2.0')).owner, 'core');
});

test('anything else in storage is not a note', () => {
    for (const raw of [
        null,
        undefined,
        '',
        'not json',
        '[]',
        '{}',
        '{"owner":"core","name":"K"}',
        '{"owner":"../x","name":"K","version":"1"}',
        '{"owner":"core","name":"","version":"1"}',
    ]) {
        assert.equal(readSwitchBackNote(raw), null, String(raw));
    }
    assert.equal(readSwitchBackNote(JSON.stringify({ owner: 'core', name: 'x'.repeat(201), version: '1' })), null, 'not unbounded');
});

test('what the page says after the reload', () => {
    const note = { owner: 'plugin:ledger', version: '1.0.0' };
    assert.equal(switchBackOutcome(note, '1.0.0', null), 'done');
    assert.equal(switchBackOutcome(note, '1.1.0', null), 'not-done');
    assert.equal(switchBackOutcome(note, '1.1.0', { owner: 'plugin:ledger' }), 'stopped', 'the stopped-update notice says it');
    assert.equal(switchBackOutcome(note, '1.0.0', { owner: 'core' }), 'done', 'another stopped update is not this one');
});
