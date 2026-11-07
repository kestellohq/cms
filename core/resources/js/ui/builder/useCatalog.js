/**
 * The blocks this editor offers, what data they can show, and saved patterns.
 * Seen before in this tab: drawn at once from the cache and refreshed behind the scenes.
 */
import { computed, nextTick, ref, shallowRef } from 'vue';
import { __ } from '@kestello/sdk';
import { catalogCache } from './cache.js';
import * as T from './tree.js';
import { createBlock, fromPattern, patternDef } from './blocks.js';

// what each kind of document may hold: parts don't hold parts; patterns hold neither parts,
// synced patterns nor the entry's body; an entry's blocks don't hold its body either
const HIDDEN = {
    template: [],
    part: ['core/template-part'],
    pattern: ['core/entry-content', 'core/template-part', 'core/pattern'],
    entry: ['core/entry-content', 'core/template-part'],
};

export function useCatalog({ api, props, emit }) {
    const catalog = shallowRef(new Map());
    const definitions = shallowRef([]);
    const bindings = shallowRef([]);
    const patterns = shallowRef([]);
    const designSpec = shallowRef(null); // the styles blocks can be given, and the theme's presets (BlockDesign::describe)
    const loading = ref(true);
    const failed = ref(false);

    function use({ blocks, bindings: b, patterns: p, design: d }) {
        designSpec.value = d ?? null;
        definitions.value = blocks;
        catalog.value = new Map(blocks.map((d) => [d.name, d]));
        bindings.value = b;
        patterns.value = p;
    }

    async function loadCatalog() {
        const cached = catalogCache.value;
        if (cached) use(cached);
        loading.value = !cached;
        failed.value = false;
        const fresh = Promise.all([api.get('/blocks'), api.get('/design/patterns').catch(() => ({ data: { patterns: [] } }))]).then(([b, p]) => {
            catalogCache.value = { blocks: b.data.blocks, bindings: b.data.bindings, design: b.data.design, patterns: p.data.patterns };
            use(catalogCache.value);
        });
        try {
            if (!cached) await fresh;
            else
                fresh.catch(() => {
                    /* the cached catalog stays */
                });
        } catch {
            failed.value = true;
        } finally {
            loading.value = false;
        }
        // after the panes are drawn: the page may put things into the document slot
        if (!failed.value) nextTick(() => emit('ready'));
    }

    const hidden = computed(() => HIDDEN[props.kind] ?? []);
    const defOf = (node) => catalog.value.get(node?.type) ?? null;
    /** May a block of this kind go into this parent (null: the top level, where allowed limits it)? */
    const fitsIn = (def, parent) => T.fits(def, parent ? defOf(parent) : null, parent ? null : props.allowed, hidden.value);
    const nameOf = (node) => defOf(node)?.label ?? node?.type ?? '';
    const make = (name) => (catalog.value.has(name) ? createBlock(catalog.value.get(name), catalog.value) : null);
    const create = (def) => createBlock(def, catalog.value);
    const makePattern = (p) => fromPattern(p, catalog.value);
    const defOfPattern = (p) => patternDef(p, catalog.value);
    const bindingLabel = (path) => {
        const exact = bindings.value.find((b) => b.path === path);
        if (exact) return `${path.startsWith('site.') ? __('Site') : __('Entry')}: ${exact.label}`;
        if (path.startsWith('entry.fields.')) return `${__('Entry')}: ${path.slice(13) || __('a field')}`;
        return path;
    };

    loadCatalog();

    return {
        catalog,
        definitions,
        bindings,
        patterns,
        designSpec,
        loading,
        failed,
        loadCatalog,
        hidden,
        defOf,
        fitsIn,
        nameOf,
        make,
        create,
        makePattern,
        defOfPattern,
        bindingLabel,
    };
}
