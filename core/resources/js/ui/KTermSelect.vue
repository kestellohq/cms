<script setup>
import { computed, ref, watch } from 'vue';
import { useApi, __ } from '@kestello/sdk';
import KPicker from './KPicker.vue';

/**
 * Terms of one taxonomy for an entry. Hierarchical taxonomies show a
 * checklist; flat ones (tags) a picker where typing a new name adds it.
 * New terms stay names in the model until the entry is saved (the server
 * creates them), so abandoning the edit leaves nothing behind.
 *
 *   <KTermSelect v-model="form.terms.category" :taxonomy="{ name, singular, plural, hierarchical, can }" />
 */
const model = defineModel({ type: Array, default: () => [] });
const props = defineProps({
    taxonomy: { type: Object, required: true },
    disabled: Boolean,
    id: { type: String, default: '' },
});
const api = useApi();
const base = computed(() => `/taxonomies/${props.taxonomy.name}/terms`);
const item = (t) => ({ id: t.id, label: t.name });

// ── checklist (hierarchical) ─────────────────────────────────────────
const all = ref([]);
const filter = ref('');
const adding = ref('');
const loaded = ref(false);
async function loadAll() {
    try {
        all.value = (await api.get(base.value)).data;
    } catch {
        all.value = [];
    }
    loaded.value = true;
}
watch(
    () => props.taxonomy.name,
    () => {
        if (props.taxonomy.hierarchical) loadAll();
    },
    { immediate: true },
);
const shown = computed(() => {
    const q = filter.value.trim().toLowerCase();
    return q ? all.value.filter((t) => t.name.toLowerCase().includes(q)) : all.value;
});
const pending = computed(() => model.value.filter((v) => typeof v === 'string'));
function toggle(id, on) {
    model.value = on ? [...model.value, id] : model.value.filter((v) => v !== id);
}
function addNew() {
    const name = adding.value.trim();
    if (!name) return;
    const existing = all.value.find((t) => t.name.toLowerCase() === name.toLowerCase());
    if (existing) {
        if (!model.value.includes(existing.id)) toggle(existing.id, true);
    } else if (!pending.value.includes(name)) model.value = [...model.value, name];
    adding.value = '';
}

// ── picker (flat) ────────────────────────────────────────────────────
const search = async (q) => (await api.get(base.value, { search: q || undefined, limit: 20 })).data.map(item);
const resolve = async (ids) => {
    const numeric = ids.filter((v) => typeof v === 'number');
    const named = ids.filter((v) => typeof v === 'string').map((v) => ({ id: v, label: v, meta: __('new') }));
    return [...(numeric.length ? (await api.get(base.value, { ids: numeric.join(',') })).data.map(item) : []), ...named];
};
const create = computed(() => (props.taxonomy.can?.create ? (name) => ({ id: name, label: name, meta: __('new') }) : null));
</script>

<template>
    <div v-if="taxonomy.hierarchical" class="k-termlist">
        <input
            v-if="all.length > 8"
            v-model="filter"
            type="search"
            class="k-input k-termlist__filter"
            :title="__('Filter {plural}', { plural: taxonomy.plural.toLowerCase() })"
            :aria-label="__('Filter {plural}', { plural: taxonomy.plural.toLowerCase() })"
        />
        <ul v-if="shown.length || pending.length" :id="id" class="k-termlist__items">
            <li v-for="t in shown" :key="t.id" :style="{ '--k-depth': filter ? 0 : t.depth }">
                <label class="k-choice">
                    <input type="checkbox" :checked="model.includes(t.id)" :disabled="disabled" @change="toggle(t.id, $event.target.checked)" />
                    <span>{{ t.name }}</span>
                </label>
            </li>
            <li v-for="name in pending" :key="`new-${name}`">
                <label class="k-choice">
                    <input type="checkbox" checked :disabled="disabled" @change="toggle(name, false)" />
                    <span
                        >{{ name }} <span class="k-muted k-small">{{ __('new') }}</span></span
                    >
                </label>
            </li>
        </ul>
        <p v-else-if="loaded" class="k-muted k-small k-termlist__empty">{{ __('No {plural} yet.', { plural: taxonomy.plural.toLowerCase() }) }}</p>
        <form v-if="taxonomy.can?.create && !disabled" class="k-termlist__add" @submit.prevent="addNew">
            <label class="k-inline-field"
                ><span>{{ __('New {item}', { item: taxonomy.singular.toLowerCase() }) }}</span
                ><input v-model="adding" class="k-input" maxlength="191"
            /></label>
            <button type="submit" class="k-btn k-btn--secondary k-btn--sm" :disabled="!adding.trim()">{{ __('Add') }}</button>
        </form>
    </div>
    <KPicker
        v-else
        :id="id"
        v-model="model"
        multiple
        :search="search"
        :resolve="resolve"
        :create="create"
        :disabled="disabled"
        :placeholder="
            taxonomy.can?.create
                ? __('Find or add {plural}', { plural: taxonomy.plural.toLowerCase() })
                : __('Find {plural}', { plural: taxonomy.plural.toLowerCase() })
        "
    />
</template>
