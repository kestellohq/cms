/**
 * Field definitions as the content type and form editors change them (FieldList,
 * FieldSettings, the form builder). Pure: no translations, no Vue; labels are in
 * the components.
 */

/** Types that hold other fields (or blocks): not offered deeper than the third level. */
export const NESTING = ['group', 'repeater', 'blocks'];
/** Types a value can be sorted and filtered by (top-level fields only). */
export const INDEXABLE = ['text', 'email', 'url', 'color', 'number', 'boolean', 'date', 'datetime', 'select', 'entry', 'term', 'user'];
/** Types without a typed default value. */
export const NO_DEFAULT = ['group', 'repeater', 'blocks', 'media', 'entry', 'term', 'user', 'multi_select', 'rich_text', 'file'];

export const newId = () => Array.from(crypto.getRandomValues(new Uint8Array(4)), (b) => b.toString(16).padStart(2, '0')).join('');

/** A label as a field name: "Your e-mail" → "your_e_mail"; starts with a letter, at most 64 characters. */
export const machine = (label) =>
    String(label ?? '')
        .toLowerCase()
        .normalize('NFKD')
        .replace(/[̀-ͯ]/g, '')
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '')
        .replace(/^(\d)/, 'f_$1')
        .slice(0, 64);

/** A fresh field of a type, with no name yet (the label names it). */
export function blankField(type = 'text') {
    return {
        id: newId(),
        name: '',
        label: '',
        type,
        help: '',
        required: false,
        indexed: false,
        internal: false,
        searchable: null,
        translatable: null,
        width: 'full',
        default: null,
        options: {},
        children: [],
        conditions: null,
        _new: true,
    };
}

/**
 * A field after its type changed: only the options the new type understands are kept,
 * and what no longer makes sense is reset. Changes the field in place.
 * @param {object} allowed  the new type's options (meta.options[type])
 */
export function adjustForType(f, allowed, { taxonomies = [], depth = 1 } = {}) {
    f.options = Object.fromEntries(Object.entries(f.options ?? {}).filter(([k]) => k in allowed));
    if (allowed.choices && !Array.isArray(f.options.choices)) f.options.choices = [{ value: '', label: '' }];
    if (f.type === 'term' && !f.options.taxonomy) f.options.taxonomy = taxonomies[0]?.name ?? '';
    if (!['group', 'repeater'].includes(f.type)) f.children = [];
    if (!INDEXABLE.includes(f.type) || depth > 1) f.indexed = false;
    if (NO_DEFAULT.includes(f.type)) f.default = null;
    return f;
}

/** A new field's name follows its label until someone types a name of their own. */
export function followLabel(f) {
    if (f._new && !f._namedByHand) f.name = machine(f.label);
    return f;
}

/** A choice's value follows its label while it was empty or still the label's. */
export function setChoiceLabel(c, label) {
    if (c.value === '' || c.value === machine(c.label)) c.value = machine(label);
    c.label = label;
    return c;
}

/**
 * Switch "several" on or off. A field holding several values cannot be indexed,
 * and a file field that held one (the server keeps max: 1) forgets that limit,
 * so the default number of files applies instead of still one.
 */
export function setMultiple(f, on) {
    f.options.multiple = on;
    f.indexed = false;
    if (on && f.type === 'file' && Number(f.options.max) <= 1) f.options.max = null;
    return f;
}

/** Field types whose value every translation shares unless a field says otherwise (as Field::SHARED_TYPES). */
export const SHARED_TYPES = ['number', 'boolean', 'date', 'datetime', 'color', 'select', 'multi_select', 'media', 'user'];

/** A field's "In other languages" choice for a select: '' (as its type has it), 'own' or 'shared'. */
export const translationChoice = (f) => (f.translatable === true ? 'own' : f.translatable === false ? 'shared' : '');

/** The field with a choice from translationChoice() applied. Changes it in place. */
export function setTranslation(f, choice) {
    f.translatable = choice === 'own' ? true : choice === 'shared' ? false : null;
}
