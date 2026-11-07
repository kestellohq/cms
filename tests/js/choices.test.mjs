import { test } from 'node:test';
import assert from 'node:assert/strict';
import { choiceLabel } from '../../core/resources/js/shared/choices.js';

test('a choice without a label shows its value, as the saved form does', () => {
    assert.equal(choiceLabel({ value: 'first', label: '' }), 'first');
    assert.equal(choiceLabel({ value: 'first', label: '   ' }), 'first');
    assert.equal(choiceLabel({ value: 'first' }), 'first');
    assert.equal(choiceLabel({ value: 'first', label: 'First choice' }), 'First choice');
});
