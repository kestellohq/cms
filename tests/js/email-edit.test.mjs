import { test } from 'node:test';
import assert from 'node:assert/strict';
import { ADDABLE, fromEdit, newBlock, previewDocument, sameEdit, toEdit } from '../../core/resources/js/components/emails/edit.js';

const SAVED = {
    subject: 'Welcome to {site}',
    blocks: [
        { type: 'heading', text: 'Hi {name}' },
        { type: 'original', name: 'link', label: 'Choose a password' },
        { type: 'original', name: 'safety' },
        { type: 'image', media: 7, alt: 'The studio' },
        { type: 'divider' },
    ],
};

test('a saved edit goes through the editor and back unchanged', () => {
    const draft = fromEdit(SAVED);
    assert.ok(
        draft.blocks.every((b) => b._key),
        'each block has a key for v-for',
    );
    assert.equal(new Set(draft.blocks.map((b) => b._key)).size, draft.blocks.length, 'keys are unique');
    assert.equal(draft.blocks[2].text, '', 'every field is there for v-model');
    assert.deepEqual(toEdit(draft), SAVED);
    assert.ok(sameEdit(toEdit(draft), SAVED));
});

test('kept blocks carry new words only when there are some', () => {
    const draft = fromEdit(SAVED);
    draft.blocks[1].label = '   ';
    draft.blocks[2].text = 'Not you? Ignore this.';
    const edit = toEdit(draft);
    assert.deepEqual(edit.blocks[1], { type: 'original', name: 'link' });
    assert.deepEqual(edit.blocks[2], { type: 'original', name: 'safety', text: 'Not you? Ignore this.' });
});

test("only each type's own fields are sent", () => {
    const block = { ...newBlock('divider'), text: 'left over' };
    assert.deepEqual(toEdit({ subject: '', blocks: [block] }).blocks, [{ type: 'divider' }]);
    assert.deepEqual(toEdit({ subject: '', blocks: [newBlock('button')] }).blocks, [{ type: 'button', label: '', url: 'https://' }]);
    assert.deepEqual(toEdit({ subject: '', blocks: [newBlock('image')] }).blocks, [{ type: 'image', media: null, alt: '' }]);
    assert.throws(() => newBlock('script'), /Unknown email block type "script"/);
    assert.deepEqual(ADDABLE, ['heading', 'paragraph', 'button', 'image', 'divider', 'note']);
});

test('a subject with spaces around it saves the same', () => {
    assert.ok(sameEdit({ subject: ' Hi ', blocks: [] }, { subject: 'Hi', blocks: [] }));
    assert.ok(!sameEdit({ subject: 'Hi', blocks: [{ type: 'divider' }] }, { subject: 'Hi', blocks: [] }));
});

test('the preview shows the email light or dark, whatever the admin uses', () => {
    const html =
        '<html><head><style>@media (prefers-color-scheme: dark){.k-card{background:#000}}</style></head><body><a href="https://x.test">x</a></body></html>';
    assert.match(previewDocument(html, 'dark'), /@media all\{\.k-card/);
    assert.match(previewDocument(html, 'light'), /@media not all\{\.k-card/);
    assert.match(previewDocument(html, 'light'), /<head><base target="_blank">/, 'links open in a new tab');
});
