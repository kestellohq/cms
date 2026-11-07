import test from 'node:test';
import assert from 'node:assert/strict';
import { createCatalogue } from '../../core/resources/js/sdk/catalogue.js';

test('lookup: the domain, then core, then the source text', () => {
    const c = createCatalogue();
    c.add({ Save: 'Sačuvaj', Cancel: 'Otkaži' });
    c.add({ Save: 'Snimi pozdrav', Empty: '' }, 'hello');
    assert.equal(c.lookup('Save'), 'Sačuvaj');
    assert.equal(c.lookup('Save', 'hello'), 'Snimi pozdrav', "a plugin's own wins");
    assert.equal(c.lookup('Cancel', 'hello'), 'Otkaži', "else core's");
    assert.equal(c.lookup('Empty', 'hello'), 'Empty', 'empty translations are left out');
    assert.equal(c.lookup('Nowhere', 'hello'), 'Nowhere');
});

test('load: adds every domain, refuses what is not a catalogue', async () => {
    const store = {};
    const c = createCatalogue(store);
    const reply = (status, body) => async () => ({ ok: status === 200, status, json: async () => body });
    await c.load('/admin/translations/sr?v=1', reply(200, { core: { Save: 'Sačuvaj' }, hello: { Wave: 'Mahni' } }));
    assert.deepEqual(store, { core: { Save: 'Sačuvaj' }, hello: { Wave: 'Mahni' } });
    await assert.rejects(c.load('/x', reply(404, {})), /HTTP 404 for \/x/);
    await assert.rejects(c.load('/x', reply(200, ['no'])), /not a catalogue/);
});

test('replace() leaves only the messages it is given (the SSR server renders one page after another)', () => {
    const store = {};
    const c = createCatalogue(store);
    c.add({ Save: 'Sačuvaj', Cancel: 'Otkaži' }, 'core');
    c.add({ Wave: 'Mahni' }, 'base');
    c.replace({ base: { Wave: 'Winken' } });
    assert.equal(c.lookup('Wave', 'base'), 'Winken');
    assert.equal(c.lookup('Save'), 'Save', "the last page's language is gone");
    assert.deepEqual(Object.keys(store), ['base']);
});
