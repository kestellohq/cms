<script>
// The block catalog (GET /blocks) and the patterns are the same for every blocks field on the page: load them once.
// They are kept for a minute, so parts and patterns made meanwhile in Appearance show up.
let catalogRequest = null;
let patternsRequest = null;
let loadedAt = 0;
</script>

<script setup>
import { computed, nextTick, provide, reactive, ref, shallowRef } from 'vue';
import { __, useApi } from '@kestello/sdk';
import { KBlockList, KButton, KIcon, KModal, KSpinner } from '@kestello/ui';

const model = defineModel({ default: null });
const props = defineProps({
    field: { type: Object, required: true },
    id: { type: String, default: '' },
    invalid: Boolean,
    disabled: Boolean,
    errors: { type: Object, default: () => ({}) },
    path: { type: String, default: '' },
});
const api = useApi();

const catalog = shallowRef(new Map());
const definitions = shallowRef([]);
const bindings = shallowRef([]);
const patterns = shallowRef([]);
const loading = ref(true);
const failed = ref(false);

async function load() {
    loading.value = true;
    failed.value = false;
    try {
        if (Date.now() - loadedAt > 60000) {
            catalogRequest = null;
            patternsRequest = null;
            loadedAt = Date.now();
        }
        catalogRequest ??= api.get('/blocks').then((r) => r.data);
        const data = await catalogRequest;
        definitions.value = data.blocks;
        catalog.value = new Map(data.blocks.map((b) => [b.name, b]));
        bindings.value = data.bindings;
        // patterns are a convenience: the editor works without them
        patternsRequest ??= api
            .get('/design/patterns')
            .then((r) => r.data.patterns)
            .catch(() => []);
        patterns.value = await patternsRequest;
    } catch (e) {
        catalogRequest = null;
        failed.value = true;
    } finally {
        loading.value = false;
    }
}
load();

// A field that held rich text before it became a blocks field opens as one text block.
let upgraded = null;
const tree = computed({
    get: () => {
        if (Array.isArray(model.value)) return model.value;
        if (typeof model.value === 'string' && model.value.trim() !== '') {
            if (upgraded?.html !== model.value)
                upgraded = { html: model.value, tree: [{ type: 'core/text', id: newId(), props: { text: model.value }, children: [] }] };
            return upgraded.tree;
        }
        return [];
    },
    set: (value) => {
        model.value = value.length ? value : null;
    },
});
const messages = computed(() => {
    const e = props.errors[props.path];
    return Array.isArray(e) ? e : e ? [e] : [];
});

// ── editor state shared with every nested list ─────────────────────
const opened = reactive(new Set());
const newId = () => Array.from(crypto.getRandomValues(new Uint8Array(4)), (b) => b.toString(16).padStart(2, '0')).join('');
function reid(node) {
    return { ...node, id: newId(), children: (node.children ?? []).map(reid) };
}
function create(def) {
    const values = Object.fromEntries(def.fields.filter((f) => f.default !== null && f.default !== undefined).map((f) => [f.name, f.default]));
    // a required choice starts on its first option, so a new block is valid as inserted
    for (const f of def.fields) {
        if (f.type === 'select' && f.required && !(f.name in values) && f.options.choices?.length) values[f.name] = f.options.choices[0].value;
    }
    const node = { type: def.name, id: newId(), props: values, children: [] };
    // a layout block that only holds one kind of child (columns → column) starts with two of them
    if (Array.isArray(def.children) && def.children.length === 1 && catalog.value.has(def.children[0])) {
        const child = catalog.value.get(def.children[0]);
        const count = def.name === 'core/columns' ? String(values.layout ?? '1-1').split('-').length : 2;
        node.children = Array.from({ length: count }, () => create(child));
    }
    return node;
}
const bindingLabel = (path) => {
    const exact = bindings.value.find((b) => b.path === path);
    if (exact) return `${path.startsWith('site.') ? __('Site') : __('Entry')}: ${exact.label}`;
    if (path.startsWith('entry.fields.')) return `${__('Entry')}: ${path.slice(13) || __('a field')}`;
    return path;
};

// ── inserter ───────────────────────────────────────────────────────
const inserter = reactive({ open: false, query: '', parent: null, allowed: null, where: '', done: null });
const search = ref(null);
function insert(context, done) {
    Object.assign(inserter, { open: true, query: '', parent: context.parent, allowed: context.allowed, where: context.where, done });
    nextTick(() => search.value?.focus());
}
// Blocks that only make sense in templates (the entry's body, shared parts) stay out of entry fields.
// field.options.context: 'template' | 'part' | 'pattern' | undefined (an entry's field)
const TEMPLATE_ONLY = ['core/entry-content', 'core/template-part'];
const hidden = computed(() => {
    const ctx = props.field.options?.context;
    if (ctx === 'template') return [];
    if (ctx === 'part') return ['core/template-part']; // a part can show the entry's body, not another part
    if (ctx === 'pattern') return [...TEMPLATE_ONLY, 'core/pattern'];
    return TEMPLATE_ONLY;
});
function fits(def, parent, allowed) {
    if (hidden.value.includes(def.name)) return false;
    if (parent === null) return allowed ? allowed.includes(def.name) : def.parents === null;
    const inside = parent.children === true || (Array.isArray(parent.children) && parent.children.includes(def.name));
    return inside && (def.parents === null || def.parents.includes(parent.name));
}
const CATEGORIES = [
    ['text', __('Text')],
    ['media', __('Media')],
    ['layout', __('Layout')],
    ['data', __('Entries and data')],
    ['widgets', __('Widgets')],
];
const choices = computed(() => {
    const q = inserter.query.trim().toLowerCase();
    const allowed = inserter.parent ? null : (inserter.allowed ?? props.field.options.allowed ?? null);
    const usable = definitions.value.filter(
        (d) => fits(d, inserter.parent, allowed) && (!q || `${d.label} ${d.description} ${d.name}`.toLowerCase().includes(q)),
    );
    return CATEGORIES.map(([key, label]) => ({ key, label, blocks: usable.filter((d) => d.category === key) })).filter((g) => g.blocks.length);
});
// Patterns: a synced one goes in as a core/pattern block that stays linked; any other is copied in.
const patternChoices = computed(() => {
    const q = inserter.query.trim().toLowerCase();
    const allowed = inserter.parent ? null : (inserter.allowed ?? props.field.options.allowed ?? null);
    const synced = catalog.value.get('core/pattern');
    const group = catalog.value.get('core/group');
    return patterns.value.filter((p) => {
        if (q && !`${p.title} ${p.description} ${p.category}`.toLowerCase().includes(q)) return false;
        if (p.synced) return !!synced && fits(synced, inserter.parent, allowed);
        const tops = p.blocks.map((b) => catalog.value.get(b.type)).filter(Boolean);
        if (!tops.length) return false;
        return tops.length === 1 ? fits(tops[0], inserter.parent, allowed) : !!group && fits(group, inserter.parent, allowed);
    });
});
const count = computed(() => choices.value.reduce((n, g) => n + g.blocks.length, 0) + patternChoices.value.length);
function pickPattern(p) {
    let node;
    if (p.synced) {
        node = { type: 'core/pattern', id: newId(), props: { pattern: p.key }, children: [] };
    } else {
        const copies = p.blocks.filter((b) => catalog.value.has(b.type)).map((b) => reid(JSON.parse(JSON.stringify(b))));
        node = copies.length === 1 ? copies[0] : { ...create(catalog.value.get('core/group')), children: copies };
    }
    inserter.done?.(node);
    inserter.open = false;
}
function pick(def) {
    const node = create(def);
    inserter.done?.(node);
    inserter.open = false;
}
function pickFirst() {
    const first = choices.value[0]?.blocks[0];
    if (first) pick(first);
}

provide('kestello.blocks', {
    catalog,
    bindings,
    insert,
    reid,
    bindingLabel,
    isOpen: (id) => opened.has(id),
    open: (id) => opened.add(id),
    toggle: (id) => (opened.has(id) ? opened.delete(id) : opened.add(id)),
});
</script>

<template>
    <div :id="id" class="k-blocks-ed" :class="{ 'is-invalid': invalid }">
        <div v-if="messages.length" class="k-blocks-ed__errors" role="group" :aria-label="__('Problems with these blocks')">
            <p class="k-blocks-ed__errors-title">
                <KIcon name="warning-circle" />{{
                    messages.length === 1 ? __('One block needs attention') : __('{n} problems need attention', { n: messages.length })
                }}
            </p>
            <ul>
                <li v-for="(m, i) in messages" :key="i">{{ m }}</li>
            </ul>
        </div>
        <p v-if="loading" class="k-blocks-ed__state"><KSpinner />{{ __('Loading blocks…') }}</p>
        <p v-else-if="failed" class="k-blocks-ed__state">
            {{ __('The block list could not be loaded.') }} <KButton size="sm" variant="ghost" @click="load">{{ __('Try again') }}</KButton>
        </p>
        <KBlockList v-else v-model="tree" :allowed="field.options.allowed ?? null" :disabled="disabled" />

        <KModal
            v-model:open="inserter.open"
            :title="inserter.parent ? __('Add to {where}', { where: inserter.where || inserter.parent.label }) : __('Add a block')"
            size="lg"
        >
            <div class="k-blocks-ins">
                <label class="k-field__label" for="k-blocks-ins-search">{{ __('Search blocks') }}</label>
                <input
                    id="k-blocks-ins-search"
                    ref="search"
                    v-model="inserter.query"
                    type="search"
                    autofocus
                    class="k-input k-blocks-ins__search"
                    aria-controls="k-blocks-ins-results"
                    @keydown.enter.prevent="pickFirst"
                />
                <div id="k-blocks-ins-results" class="k-blocks-ins__results" aria-live="polite">
                    <p v-if="!count" class="k-blocks-ins__none">{{ __('No block matches “{q}”.', { q: inserter.query }) }}</p>
                    <section v-if="patternChoices.length" class="k-blocks-ins__group">
                        <h3 class="k-blocks-ins__heading">{{ __('Patterns') }}</h3>
                        <ul class="k-blocks-ins__grid">
                            <li v-for="p in patternChoices" :key="p.key">
                                <button type="button" class="k-blocks-ins__choice" @click="pickPattern(p)">
                                    <KIcon :name="p.synced ? 'star' : 'bookmark'" class="k-blocks-ins__icon" />
                                    <span class="k-blocks-ins__name">{{ p.title }}</span>
                                    <span class="k-blocks-ins__desc">{{
                                        p.synced ? __('Synced: changes everywhere it is used.') : p.description || p.category
                                    }}</span>
                                </button>
                            </li>
                        </ul>
                    </section>
                    <section v-for="group in choices" :key="group.key" class="k-blocks-ins__group">
                        <h3 class="k-blocks-ins__heading">{{ group.label }}</h3>
                        <ul class="k-blocks-ins__grid">
                            <li v-for="def in group.blocks" :key="def.name">
                                <button type="button" class="k-blocks-ins__choice" @click="pick(def)">
                                    <KIcon :name="def.icon" class="k-blocks-ins__icon" />
                                    <span class="k-blocks-ins__name">{{ def.label }}</span>
                                    <span v-if="def.description" class="k-blocks-ins__desc">{{ def.description }}</span>
                                </button>
                            </li>
                        </ul>
                    </section>
                </div>
            </div>
        </KModal>
    </div>
</template>
