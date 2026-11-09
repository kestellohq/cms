import { test } from 'node:test';
import assert from 'node:assert/strict';
import { insertMention, matchPeople, mentionAt, splitMentions } from '../../core/resources/js/components/content/mentions.js';

test('the @name being typed is found before the caret', () => {
    assert.deepEqual(mentionAt('Thanks @ma', 10), { start: 7, query: 'ma' });
    assert.deepEqual(mentionAt('@', 1), { start: 0, query: '' });
    assert.deepEqual(mentionAt('(@ed', 4), { start: 1, query: 'ed' });
    assert.equal(mentionAt('mail me@ex', 10), null, 'not in an email address');
    assert.equal(mentionAt('@maria and', 10), null, 'the caret has moved on');
});

test('people are matched by the start of their name first', () => {
    const people = [{ username: 'anna' }, { username: 'joanna' }, { username: 'annika' }, { username: 'bo' }];
    assert.deepEqual(
        matchPeople(people, 'ANN').map((p) => p.username),
        ['anna', 'annika', 'joanna'],
    );
    assert.equal(matchPeople(people, '', 2).length, 2);
});

test('picking a person completes the name and adds a space', () => {
    assert.deepEqual(insertMention('Thanks @ma', 10, 'maria'), { text: 'Thanks @maria ', caret: 14 });
    assert.deepEqual(insertMention('@ma and more', 3, 'maria'), { text: '@maria and more', caret: 7 });
    assert.deepEqual(insertMention('no mention', 4, 'maria'), { text: 'no mention', caret: 4 });
});

test('a comment is shown with its mentions marked', () => {
    assert.deepEqual(splitMentions('Hi @maria, mail x@y.example or @ab!'), [
        { text: 'Hi ' },
        { text: '@maria', mention: 'maria' },
        { text: ', mail x@y.example or @ab!' },
    ]);
    assert.deepEqual(splitMentions('@eddie'), [{ text: '@eddie', mention: 'eddie' }]);
});
