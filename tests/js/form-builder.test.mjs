import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    dropSide,
    duplicateField,
    insertField,
    misplacedConditions,
    moveField,
    newFormField,
    PRESETS,
    removeField,
    uniqueName,
} from '../../core/resources/js/components/forms/form-builder.js';

const F = (name, extra = {}) => ({ id: `id${name}`, name, label: name, type: 'text', options: {}, conditions: null, ...extra });
let n = 0;
const newId = () => `c0ffee${(n++).toString(16).padStart(2, '0')}`;

test('names stay unique', () => {
    const fields = [F('email'), F('email_2')];
    assert.equal(uniqueName('Email', fields), 'email_3');
    assert.equal(uniqueName('Phone', fields), 'phone');
    assert.equal(uniqueName('', fields), 'field');
    assert.equal(uniqueName('email', fields, fields[0]), 'email', 'a field keeps its own name');
});

test('a new field: of a type, or ready-made', () => {
    const fields = [F('email')];
    const choice = newFormField(fields, { type: 'select', label: 'Your choice', allowed: { choices: 'choices', display: 'display' } });
    assert.equal(choice.name, 'your_choice');
    assert.equal(choice.options.choices.length, 2, 'a choice starts with two to fill in');
    assert.equal(choice.options.display, 'radios');
    assert.equal(choice._namedByHand, false, 'its name follows the label until typed');
    const email = newFormField(fields, { preset: PRESETS.find((p) => p.key === 'email'), label: 'Email' });
    assert.deepEqual([email.type, email.name, email.width, email.required, email._namedByHand], ['email', 'email_2', 'half', true, true]);
});

test('moving lands before what is at the target, or at the end', () => {
    const f = [F('a'), F('b'), F('c'), F('d')];
    const names = (x) => x.map((y) => y.name).join('');
    assert.equal(names(moveField(f, 0, 2)), 'bacd');
    assert.equal(names(moveField(f, 3, 0)), 'dabc');
    assert.equal(names(moveField(f, 1, 4)), 'acdb');
    assert.equal(moveField(f, 1, 2), f, 'no move: the same array');
    assert.equal(names(insertField(f, F('x'), 2)), 'abxcd');
    assert.equal(names(insertField(f, F('x'), 99)), 'abcdx');
});

test('a duplicate gets its own id and name, right after the original', () => {
    const f = [F('email', { options: { placeholder: 'x' } }), F('b')];
    const { fields, copy } = duplicateField(f, 0, newId);
    assert.deepEqual(
        fields.map((x) => x.name),
        ['email', 'email_2', 'b'],
    );
    assert.notEqual(copy.id, f[0].id);
    copy.options.placeholder = 'changed';
    assert.equal(f[0].options.placeholder, 'x', 'a deep copy');
});

test('removing a field drops the conditions that pointed at it', () => {
    const f = [
        F('kind'),
        F('detail', { conditions: { match: 'all', rules: [{ field: 'kind', op: 'is', value: 'x' }] } }),
        F('more', {
            conditions: {
                match: 'any',
                rules: [
                    { field: 'kind', op: 'is', value: 'y' },
                    { field: 'detail', op: 'filled' },
                ],
            },
        }),
    ];
    const { fields, touched } = removeField(f, 0);
    assert.deepEqual(touched, ['detail', 'more']);
    assert.equal(fields[0].conditions, null);
    assert.deepEqual(fields[1].conditions.rules, [{ field: 'detail', op: 'filled' }]);
});

test('a condition must read a field above it', () => {
    const f = [F('detail', { conditions: { rules: [{ field: 'kind' }] } }), F('kind')];
    assert.deepEqual([...misplacedConditions(f)], ['iddetail']);
    assert.deepEqual([...misplacedConditions(moveField(f, 1, 0))], []);
});

test('a drop goes before or after a field, across for half-width ones', () => {
    const r = { left: 0, top: 0, width: 200, height: 50 };
    assert.equal(dropSide(r, 150, 10), 'before');
    assert.equal(dropSide(r, 150, 40), 'after');
    assert.equal(dropSide(r, 150, 10, 'half'), 'after');
    assert.equal(dropSide(r, 20, 40, 'half'), 'before');
});
