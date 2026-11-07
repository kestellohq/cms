import { test } from 'node:test';
import assert from 'node:assert/strict';
import { adjustForType, blankField, followLabel, machine, setChoiceLabel, setMultiple } from '../../core/resources/js/components/content-types/fields.js';

test('a label becomes a name', () => {
    assert.equal(machine('Your e-mail'), 'your_e_mail');
    assert.equal(machine('Café crème'), 'cafe_creme');
    assert.equal(machine('2nd line'), 'f_2nd_line', 'names start with a letter');
    assert.equal(machine('x'.repeat(80)).length, 64);
});

test('a new field is named by its label until named by hand', () => {
    const f = blankField('email');
    f.label = 'Work email';
    followLabel(f);
    assert.equal(f.name, 'work_email');
    f._namedByHand = true;
    f.label = 'Other';
    followLabel(f);
    assert.equal(f.name, 'work_email');
    const saved = { ...blankField(), _new: false, name: 'kept', label: 'New label' };
    assert.equal(followLabel(saved).name, 'kept', 'a saved field keeps its name: answers are stored under it');
});

test('changing type keeps only the options the new type takes', () => {
    const f = { ...blankField('text'), options: { placeholder: 'x', max: 10 }, default: 'a', indexed: true };
    f.type = 'select';
    adjustForType(f, { choices: 'choices', display: 'display' });
    assert.deepEqual(f.options, { choices: [{ value: '', label: '' }] });
    assert.equal(f.default, 'a');
    f.type = 'file';
    adjustForType(f, { kinds: 'kinds', max_size: 'int' });
    assert.deepEqual([f.options, f.default, f.indexed], [{}, null, false]);
    const deep = { ...blankField('text'), indexed: true };
    assert.equal(adjustForType(deep, {}, { depth: 2 }).indexed, false, 'only top-level fields sort');
});

test("a choice's value follows its label until set by hand", () => {
    const c = { value: '', label: '' };
    setChoiceLabel(c, 'Next week');
    assert.deepEqual(c, { value: 'next_week', label: 'Next week' });
    c.value = 'nw';
    setChoiceLabel(c, 'Next weekend');
    assert.equal(c.value, 'nw');
});

test('allowing several files after one forgets the limit of one, so the default applies', () => {
    const saved = { type: 'file', indexed: false, options: { max_size: 5, max: 1 } }; // as the server keeps a one-file field
    setMultiple(saved, true);
    assert.deepEqual(saved.options, { max_size: 5, multiple: true, max: null });
    const chosen = { type: 'file', indexed: false, options: { multiple: false, max: 3 } };
    assert.equal(setMultiple(chosen, true).options.max, 3, 'a limit someone chose stays');
});

test('a field that holds several values cannot be indexed', () => {
    const f = { type: 'entry', indexed: true, options: {} };
    setMultiple(f, true);
    assert.deepEqual([f.indexed, f.options.multiple], [false, true]);
});
