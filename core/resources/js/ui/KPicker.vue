<script setup>
import { computed, onBeforeUnmount, ref, watch, useId } from 'vue';
import { __ } from '@kestello/sdk';
import KIcon from './KIcon.vue';
import { dropdownPlace } from './dropdown.js';

/**
 * Pick one or more records from a search (entries, people, terms…).
 *
 *   <KPicker v-model="ids" multiple :search="q => api.get('/x', { search: q })" :resolve="ids => …" />
 *
 * search(query) and resolve(ids) return [{ id, label, meta? }]. The model
 * holds ids only; labels for stored ids come from resolve(). With
 * create(text), typing a name that isn't found offers to add it; create
 * returns the new { id, label } (or the raw text, kept as-is in the model).
 */
const model = defineModel({ default: null });
const props = defineProps({
    search: { type: Function, required: true },
    resolve: { type: Function, required: true },
    multiple: Boolean,
    disabled: Boolean,
    invalid: Boolean,
    id: { type: String, default: '' },
    placeholder: { type: String, default: '' },
    create: { type: Function, default: null },
});

const uid = useId();
const listId = `k-picker-${uid}`;
const ids = computed(() => (props.multiple ? (Array.isArray(model.value) ? model.value : []) : model.value ? [model.value] : []));
const known = ref(new Map());
const query = ref('');
const results = ref([]);
const open = ref(false);
const active = ref(-1);
const busy = ref(false);
let seq = 0;
let timer = null;

watch(
    ids,
    async (list) => {
        const missing = list.filter((id) => !known.value.has(id));
        if (!missing.length) return;
        try {
            for (const item of await props.resolve(missing)) known.value.set(item.id, item);
            known.value = new Map(known.value);
        } catch {
            /* labels stay as ids */
        }
    },
    { immediate: true },
);

async function run() {
    const mine = ++seq;
    busy.value = true;
    try {
        const items = await props.search(query.value.trim());
        if (mine !== seq) return;
        results.value = items;
        active.value = items.length || props.create ? 0 : -1;
    } catch {
        if (mine === seq) results.value = [];
    } finally {
        if (mine === seq) busy.value = false;
    }
}
watch(query, () => {
    clearTimeout(timer);
    timer = setTimeout(run, 200);
    open.value = true;
});

function focus() {
    open.value = true;
    if (!results.value.length) run();
}
function blur() {
    setTimeout(() => {
        open.value = false;
    }, 120);
}

function choose(item) {
    known.value = new Map(known.value).set(item.id, item);
    if (props.multiple) {
        if (!ids.value.includes(item.id)) model.value = [...ids.value, item.id];
    } else {
        model.value = item.id;
        open.value = false;
    }
    query.value = '';
}
function remove(id) {
    model.value = props.multiple ? ids.value.filter((x) => x !== id) : null;
}
function key(e) {
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        open.value = true;
        const n = options.value.length;
        if (n) active.value = (active.value + (e.key === 'ArrowDown' ? 1 : -1) + n) % n;
    } else if (e.key === 'Enter' && open.value && options.value[active.value]) {
        e.preventDefault();
        pick(options.value[active.value]);
    } else if (e.key === 'Escape') {
        open.value = false;
    } else if (e.key === 'Backspace' && query.value === '' && props.multiple && ids.value.length) {
        remove(ids.value[ids.value.length - 1]);
    }
}
// "Add “x”" as the last option when nothing matches exactly.
const offer = computed(() => {
    const text = query.value.trim();
    if (!props.create || text === '' || busy.value) return null;
    return results.value.some((r) => String(r.label).toLowerCase() === text.toLowerCase()) ? null : text;
});
const options = computed(() => (offer.value ? [...results.value, { id: '__create', label: offer.value, create: true }] : results.value));
async function pick(item) {
    if (!item.create) return choose(item);
    try {
        const made = await props.create(item.label);
        choose(typeof made === 'object' && made !== null ? made : { id: made, label: item.label });
    } catch {
        /* the caller reports it */
    }
}

// ── the results float over the page (position: fixed), so a card or a scrolling panel never
// cuts them off; while open they follow the field as the page scrolls or resizes
const field = ref(null);
const place = ref(null);
const shown = computed(() => open.value && (options.value.length || busy.value));
function reposition() {
    if (!field.value) return;
    const at = dropdownPlace(field.value.getBoundingClientRect(), { width: window.innerWidth, height: window.innerHeight });
    const px = (n) => (n === undefined ? 'auto' : `${Math.round(n)}px`);
    place.value = { top: px(at.top), bottom: px(at.bottom), left: px(at.left), width: px(at.width), maxHeight: px(at.maxHeight) };
}
function follow(on) {
    const how = on ? 'addEventListener' : 'removeEventListener';
    window[how]('scroll', reposition, { capture: true, passive: true });
    window[how]('resize', reposition, { passive: true });
}
watch(shown, (on) => {
    if (on) reposition();
    follow(on);
});
onBeforeUnmount(() => follow(false));

const label = (id) => known.value.get(id)?.label ?? `#${id}`;
const meta = (id) => known.value.get(id)?.meta ?? '';
</script>

<template>
    <div class="k-picker" :class="{ 'is-open': shown }">
        <ul v-if="ids.length" class="k-picker__chosen">
            <li v-for="cid in ids" :key="cid" class="k-picker__item">
                <span class="k-picker__label">{{ label(cid) }}</span>
                <span v-if="meta(cid)" class="k-picker__meta">{{ meta(cid) }}</span>
                <button v-if="!disabled" type="button" class="k-picker__remove" :aria-label="__('Remove {name}', { name: label(cid) })" @click="remove(cid)">
                    <KIcon name="x" />
                </button>
            </li>
        </ul>
        <div v-if="!disabled && (multiple || !ids.length)" ref="field" class="k-picker__field">
            <KIcon name="magnifying-glass" />
            <input
                :id="id"
                v-model="query"
                class="k-input"
                role="combobox"
                autocomplete="off"
                :aria-expanded="open ? 'true' : 'false'"
                :aria-controls="listId"
                :aria-activedescendant="open && active >= 0 ? `${listId}-${active}` : undefined"
                :aria-invalid="invalid ? 'true' : undefined"
                :placeholder="placeholder || undefined"
                @focus="focus"
                @blur="blur"
                @keydown="key"
            />
            <ul v-show="shown" :id="listId" class="k-picker__results" role="listbox" :style="place">
                <li v-if="busy && !options.length" class="k-picker__hint">{{ __('Searching…') }}</li>
                <li
                    v-for="(item, i) in options"
                    :id="`${listId}-${i}`"
                    :key="item.id"
                    role="option"
                    class="k-picker__option"
                    :aria-selected="i === active ? 'true' : 'false'"
                    :class="{ 'is-chosen': ids.includes(item.id), 'is-create': item.create }"
                    @mousedown.prevent="pick(item)"
                    @mouseenter="active = i"
                >
                    <span v-if="item.create" class="k-picker__label"><KIcon name="plus" /> {{ __('Add “{name}”', { name: item.label }) }}</span>
                    <span v-else class="k-picker__label">{{ item.label }}</span>
                    <span v-if="item.meta || ids.includes(item.id)" class="k-picker__meta">{{
                        [item.meta, ids.includes(item.id) ? __('added') : ''].filter(Boolean).join(' · ')
                    }}</span>
                </li>
            </ul>
        </div>
    </div>
</template>
