import { test } from 'node:test';
import assert from 'node:assert/strict';
import { minutesUntil } from '../../core/resources/js/components/demo/demo.js';

test('minutes until the demo resets', () => {
    const now = 1_800_000_000_000;
    const at = now / 1000;
    assert.equal(minutesUntil(at + 23 * 60, now), 23);
    assert.equal(minutesUntil(at + 23 * 60 + 1, now), 24, 'rounds up: never says 0 while time is left');
    assert.equal(minutesUntil(at + 5, now), 1);
    assert.equal(minutesUntil(at, now), null, 'due: the next scheduled task resets it');
    assert.equal(minutesUntil(at - 60, now), null);
    assert.equal(minutesUntil(null, now), null, 'before the first snapshot');
    assert.equal(minutesUntil('soon', now), null);
});
