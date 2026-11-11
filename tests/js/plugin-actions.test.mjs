import { test } from 'node:test';
import assert from 'node:assert/strict';
import { pluginActions } from '../../core/resources/js/components/packages/plugin-actions.js';

test('an inactive plugin can be activated or deleted', () => {
    assert.deepEqual(pluginActions({ status: 'inactive' }), { activate: true, deactivate: false, remove: true });
});

test('an active plugin can be deactivated', () => {
    assert.deepEqual(pluginActions({ status: 'active' }), { activate: false, deactivate: true, remove: false });
});

test('a suspended plugin can be tried again, or deactivated', () => {
    // POST /plugins/{slug}/activate boots it again and lifts the suspension when it starts
    assert.deepEqual(pluginActions({ status: 'suspended' }), { activate: true, deactivate: true, remove: false });
});

test('a plugin that failed to start can be deactivated', () => {
    assert.deepEqual(pluginActions({ status: 'failed' }), { activate: false, deactivate: true, remove: false });
});
