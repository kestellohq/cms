// A small block catalog shaped like GET /blocks, for the editor's pure modules.
const f = (name, type, extra = {}) => ({ name, type, label: name, default: null, required: false, options: {}, ...extra });
const def = (name, fields, extra = {}) => ({
    name,
    label: name.split('/')[1],
    description: '',
    category: 'text',
    icon: 'x',
    fields,
    children: null,
    parents: null,
    ...extra,
});

export const DEFS = [
    def('core/text', [
        f('text', 'rich_text', { required: true }),
        f('align', 'select', {
            default: 'start',
            options: {
                choices: [
                    { value: 'start', label: 'Left' },
                    { value: 'center', label: 'Centre' },
                ],
            },
        }),
    ]),
    def('core/heading', [
        f('text', 'text', { required: true }),
        f('level', 'select', { default: '2', options: { choices: [{ value: '2', label: 'H2' }] } }),
        f('align', 'select', { default: 'start' }),
    ]),
    def('core/quote', [f('text', 'textarea', { required: true }), f('cite', 'text')]),
    def('core/list', [f('ordered', 'boolean'), f('items', 'repeater')]),
    def('core/code', [f('code', 'textarea'), f('language', 'text')]),
    def('core/details', [f('summary', 'text'), f('text', 'rich_text'), f('open', 'boolean')]),
    def(
        'core/image',
        [
            f('image', 'image', { required: true }),
            f('scale', 'number', { default: 100, options: { min: 10, max: 100 } }),
            f('align', 'select', { default: 'center' }),
        ],
        { category: 'media' },
    ),
    def('core/group', [], { children: true, category: 'layout' }),
    def('core/columns', [f('layout', 'select', { default: '1-1' })], { children: ['core/column'], category: 'layout' }),
    def('core/column', [], { children: true, parents: ['core/columns'], category: 'layout' }),
    def('core/pattern', [f('pattern', 'text')], { category: 'layout' }),
    def('core/button', [f('text', 'text', { required: true }), f('variant', 'select', { default: 'primary' })]),
    def('core/buttons', [f('align', 'select', { default: 'start' })], {
        children: ['core/button'],
        category: 'layout',
        starter: [{ type: 'core/button', props: {} }],
    }),
    def('core/cover', [], {
        children: true,
        category: 'layout',
        starter: [
            { type: 'core/heading', props: { level: '1' } },
            { type: 'vendor/gone', props: {} },
        ],
    }),
];
export const catalog = new Map(DEFS.map((d) => [d.name, d]));
export const defOf = (n) => catalog.get(n?.type) ?? null;

let n = 0;
export const node = (type, props = {}, children = []) => ({ type, id: `n${(++n).toString(16)}`, props, children });
