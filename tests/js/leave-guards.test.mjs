import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createLeaveGuards, leavesPage } from '../../core/resources/js/app/leave-guards.js';

const here = new URL('http://site.test/admin/forms/30');

test('a guard holds the page while its work is unsaved, and lets go once removed', () => {
    const guards = createLeaveGuards();
    let dirty = true;
    const remove = guards.add(() => dirty);
    assert.equal(guards.holding(), true);
    dirty = false;
    assert.equal(guards.holding(), false);
    dirty = true;
    remove();
    assert.equal(guards.holding(), false);
});

test('a guard that throws does not trap people on the page', () => {
    const guards = createLeaveGuards();
    guards.add(() => {
        throw new Error('broken');
    });
    assert.equal(guards.holding(), false);
});

test('opening another address leaves the page; reloading it, or a changed query, does not', () => {
    assert.equal(leavesPage(here, new URL('http://site.test/admin/content/page')), true);
    assert.equal(leavesPage(here, new URL('http://site.test/admin/forms/30?tab=form')), false);
    assert.equal(leavesPage(here, new URL('http://site.test/admin/forms/30#field')), false);
    assert.equal(leavesPage(here, new URL('http://site.test/admin/content/page'), { method: 'post' }), false, 'sending a form is asked for');
    assert.equal(leavesPage(here, new URL('http://site.test/admin/x'), { preserveState: true }), false, 'a refresh of the same screen');
});
