import { test } from 'node:test';
import assert from 'node:assert/strict';
import { prepareTree } from '../../core/resources/js/ui/builder/prepare.js';

test('only blocks not seen in this form go to the server; invalid ones are skipped', async () => {
    const asked = [];
    const server = async (blocks) => {
        asked.push(blocks.map((b) => b.id));
        return blocks.filter((b) => b.props.ok).map((b) => ({ ...b, prepared: true }));
    };
    const a = { id: 'a', type: 't', props: { ok: true } };
    const b = { id: 'b', type: 't', props: { ok: false } };
    const first = await prepareTree([a, b], 'doc1', server);
    assert.deepEqual(
        first.map((x) => x.id),
        ['a'],
    );
    const again = await prepareTree([b, a], 'doc1', server);
    assert.deepEqual(
        again.map((x) => x.id),
        ['a'],
    );
    assert.deepEqual(asked, [['a', 'b']], 'the second time came from the cache');
    await prepareTree([{ ...a, props: { ok: true, more: 1 } }], 'doc1', server);
    await prepareTree([a], 'doc2', server);
    assert.deepEqual(asked.slice(1), [['a'], ['a']], 'a change or another scope asks again');
});
