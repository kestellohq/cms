import { test } from 'node:test';
import assert from 'node:assert/strict';
import { covers, currentEntry, readFolded, toggleFolded, unfold, visibleGroups, FOLDED_BY_DEFAULT } from '../../core/resources/js/components/admin/nav.js';

const item = (id, href, match = href) => ({ id, label: id, href, icon: 'circle', match });
const groups = [
    { id: 'main', label: '', items: [item('core.home', '/admin')] },
    {
        id: 'content',
        label: 'Content',
        items: [item('core.content.post', '/admin/content/post'), item('core.taxonomy.category', '/admin/taxonomies/category')],
    },
    { id: 'people', label: 'People', items: [item('core.users', '/admin/users'), item('core.profile', '/admin/profile')] },
    { id: 'site', label: 'Site', items: [item('core.settings', '/admin/settings')] },
    { id: 'system', label: 'System', items: [item('core.system', '/admin/system', '/admin/system'), item('core.system.health', '/admin/system/health')] },
    { id: 'plugins', label: 'Plugins', items: [item('acme.docs', 'https://docs.acme.test/admin/settings')] },
];

test('the overview covers only itself, other entries their section', () => {
    assert.equal(covers(groups[0].items[0], '/admin'), true);
    assert.equal(covers(groups[0].items[0], '/admin/users'), false);
    assert.equal(covers(groups[2].items[0], '/admin/users/7'), true);
    assert.equal(covers(groups[2].items[0], '/admin/users-archive'), false, 'a prefix of the name is not the section');
});

test('the longest match is the current entry, with its group', () => {
    assert.equal(currentEntry(groups, '/admin/system/health/details').item.id, 'core.system.health');
    assert.equal(currentEntry(groups, '/admin/system/health/details').group.id, 'system');
    assert.equal(currentEntry(groups, '/admin/settings').group.id, 'site');
    assert.equal(currentEntry(groups, '/admin/nowhere'), null);
});

test('links to other sites are never the current entry', () => {
    const external = [{ id: 'x', label: 'X', items: [item('acme.x', 'https://evil.test/admin', '/admin')] }];
    assert.equal(currentEntry(external, '/admin'), null);
});

test('the profile lives in the footer, and empty groups go', () => {
    const shown = visibleGroups(groups);
    assert.deepEqual(
        shown.find((g) => g.id === 'people').items.map((i) => i.id),
        ['core.users'],
    );
    const onlyProfile = visibleGroups([{ id: 'people', label: 'People', items: [item('core.profile', '/admin/profile')] }]);
    assert.deepEqual(onlyProfile, [], 'a group with nothing left is not shown');
    assert.equal(groups[2].items.length, 2, 'the menu itself is left alone');
});

test('folded groups come from storage, else the defaults', () => {
    assert.deepEqual(readFolded(null), [...FOLDED_BY_DEFAULT]);
    assert.deepEqual(readFolded('not json'), [...FOLDED_BY_DEFAULT]);
    assert.deepEqual(readFolded('{"system":true}'), [...FOLDED_BY_DEFAULT]);
    assert.deepEqual(readFolded('[]'), [], 'someone who opened everything keeps it open');
    assert.deepEqual(readFolded('["content","content",3,"<b>","system"]'), ['content', 'system'], 'only group ids, once each');
});

test('folding, opening, and a visited page opening its group', () => {
    assert.deepEqual(toggleFolded(['system'], 'system'), []);
    assert.deepEqual(toggleFolded([], 'content'), ['content']);
    const folded = ['system', 'content'];
    assert.deepEqual(unfold(folded, 'system'), ['content']);
    assert.equal(unfold(folded, 'people'), folded, 'unchanged when already open');
    assert.equal(unfold(folded, undefined), folded);
});
