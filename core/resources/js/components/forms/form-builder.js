/**
 * The form builder's changes to a form's fields (FormBuilder.vue): adding a field of a
 * type or a ready-made one, moving, duplicating and removing. Pure: arrays in, arrays
 * out, names kept unique (answers are stored under them). Labels come from the caller.
 */
import { blankField, machine } from '../content-types/fields.js';

/** The palette: each type a form takes, with its icon, in the order people reach for them. */
export const TYPE_ICONS = {
    text: 'text-t',
    textarea: 'text-align-left',
    email: 'envelope-simple',
    url: 'link-simple',
    number: 'hash',
    select: 'caret-circle-down',
    multi_select: 'check-square',
    boolean: 'toggle-left',
    date: 'calendar-blank',
    file: 'paperclip',
};

/** Ready-made fields most forms need. label: the caller's (translated) label. */
export const PRESETS = [
    { key: 'name', type: 'text', name: 'name', width: 'half', required: true, icon: 'user' },
    { key: 'email', type: 'email', name: 'email', width: 'half', required: true, icon: 'envelope-simple' },
    { key: 'phone', type: 'text', name: 'phone', width: 'half', icon: 'phone' },
    { key: 'message', type: 'textarea', name: 'message', required: true, icon: 'chat-text', options: { rows: 5 } },
    { key: 'consent', type: 'boolean', name: 'consent', required: true, icon: 'check-circle' },
];

/** A name not yet taken: email, email_2, email_3… */
export function uniqueName(base, fields, except = null) {
    const taken = new Set(fields.filter((f) => f !== except).map((f) => f.name));
    const root = machine(base) || 'field';
    if (!taken.has(root)) return root;
    for (let n = 2; ; n++) {
        const name = `${root.slice(0, 60)}_${n}`;
        if (!taken.has(name)) return name;
    }
}

/**
 * A new field: of a type ({ type }) or ready-made ({ preset }), labelled, named uniquely.
 * @param {{ type?: string, preset?: object, label: string, allowed?: object }} what  allowed: the type's options (meta.options[type])
 */
export function newFormField(fields, { type, preset, label, allowed = {} }) {
    const t = preset?.type ?? type;
    const f = blankField(t);
    f.label = label;
    f.name = uniqueName(preset?.name ?? label, fields);
    f._namedByHand = !!preset; // a ready-made one keeps its name (name, email) when relabelled
    if (preset) Object.assign(f, { width: preset.width ?? 'full', required: !!preset.required, options: { ...(preset.options ?? {}) } });
    if (allowed.choices)
        f.options.choices = [
            { value: 'first', label: '' },
            { value: 'second', label: '' },
        ];
    if (allowed.display) f.options.display = 'radios';
    return f;
}

/** The fields with one inserted at a place (the end when out of range). */
export function insertField(fields, field, index = fields.length) {
    const at = Math.max(0, Math.min(index, fields.length));
    return [...fields.slice(0, at), field, ...fields.slice(at)];
}

/** The fields with one moved so it lands before what is now at `to` (to = length: the end). */
export function moveField(fields, from, to) {
    if (from < 0 || from >= fields.length) return fields;
    const target = to > from ? to - 1 : to;
    if (target === from) return fields;
    const next = fields.slice();
    const [f] = next.splice(from, 1);
    next.splice(Math.max(0, Math.min(target, next.length)), 0, f);
    return next;
}

/** A copy right after the original: a fresh id and name, never the original's answers. */
export function duplicateField(fields, index, newId) {
    const f = fields[index];
    if (!f) return { fields, copy: null };
    const copy = { ...JSON.parse(JSON.stringify(f)), id: newId(), name: uniqueName(f.name, fields), _new: true, _namedByHand: true };
    return { fields: insertField(fields, copy, index + 1), copy };
}

/**
 * Removing a field: the fields left, and the names of those whose conditions pointed at it
 * (their conditions on it are dropped, so the form still saves).
 */
export function removeField(fields, index) {
    const gone = fields[index];
    if (!gone) return { fields, touched: [] };
    const touched = [];
    const rest = fields
        .filter((_, i) => i !== index)
        .map((f) => {
            const rules = f.conditions?.rules;
            if (!Array.isArray(rules) || !rules.some((r) => r.field === gone.name)) return f;
            touched.push(f.name);
            const left = rules.filter((r) => r.field !== gone.name);
            return { ...f, conditions: left.length ? { ...f.conditions, rules: left } : null };
        });
    return { fields: rest, touched };
}

/** Where a drop lands, from the pointer over a field: before it or after it (across for half-width fields). */
export function dropSide(rect, x, y, width = 'full') {
    return width === 'half' ? (x < rect.left + rect.width / 2 ? 'before' : 'after') : y < rect.top + rect.height / 2 ? 'before' : 'after';
}

/** Fields whose conditions point at a field that isn't above them (a condition reads answers already given). */
export function misplacedConditions(fields) {
    const seen = new Set();
    const out = new Set();
    for (const f of fields) {
        if ((f.conditions?.rules ?? []).some((r) => !seen.has(r.field))) out.add(f.id);
        seen.add(f.name);
    }
    return out;
}
