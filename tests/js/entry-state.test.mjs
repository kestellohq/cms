import { test } from 'node:test';
import assert from 'node:assert/strict';
import { isNewEntry } from '../../core/resources/js/components/content/entry-state.js';

test('an entry is new until its first save, whatever the address says', () => {
    assert.equal(isNewEntry('new', null), true);
    assert.equal(isNewEntry('new', { id: 154 }), false, 'created: the next save updates it');
    assert.equal(isNewEntry('154', { id: 154 }), false);
    assert.equal(isNewEntry('154', null), false, 'an existing entry still loading');
});
