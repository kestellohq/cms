<script setup>
import { computed, onBeforeUnmount, ref, watch, useId } from 'vue';
import { __ } from '@kestello/sdk';
import KIcon from './KIcon.vue';
import { dropdownPlace } from './dropdown.js';
import { wantsSuggestions } from './link-target.js';

/**
 * An address field that also finds pages: type /about or https://… as usual,
 * or type words ("contact") and pick a page from the list to fill in its
 * address.
 *
 *   <KLinkInput v-model="href" :search="(q) => api.get('/content/lookup', { linkable: 1, search: q })…" />
 *
 * search(text) returns [{ id: address, label: title, meta? }]. The model is
 * always the text in the field: picking a page puts its address there.
 */
const model = defineModel({ type: String, default: '' });
const props = defineProps({
    search: { type: Function, required: true },
    id: { type: String, default: '' },
    placeholder: { type: String, default: '' },
    describedBy: { type: String, default: '' },
    invalid: Boolean,
    disabled: Boolean,
});

const uid = useId();
const listId = `k-link-${uid}`;
const open = ref(false);
const busy = ref(false);
const results = ref([]);
const active = ref(-1);
let timer = null;
let seq = 0;

async function run(text) {
    const mine = ++seq;
    busy.value = true;
    try {
        const found = await props.search(text.trim());
        if (mine === seq) {
            results.value = Array.isArray(found) ? found.slice(0, 8) : [];
            active.value = results.value.length ? 0 : -1;
        }
    } catch {
        if (mine === seq) results.value = [];
    } finally {
        if (mine === seq) busy.value = false;
    }
}

function input(e) {
    model.value = e.target.value;
    clearTimeout(timer);
    if (!wantsSuggestions(model.value)) {
        seq++;
        results.value = [];
        busy.value = false;
        open.value = false;
        return;
    }
    open.value = true;
    timer = setTimeout(() => run(model.value), 200);
}

function choose(item) {
    model.value = item.id;
    open.value = false;
    results.value = [];
}

function key(e) {
    const n = results.value.length;
    if ((e.key === 'ArrowDown' || e.key === 'ArrowUp') && n) {
        e.preventDefault();
        open.value = true;
        active.value = (active.value + (e.key === 'ArrowDown' ? 1 : -1) + n) % n;
    } else if (e.key === 'Enter' && open.value && results.value[active.value]) {
        e.preventDefault();
        choose(results.value[active.value]);
    } else if (e.key === 'Escape' && open.value) {
        e.stopPropagation();
        open.value = false;
    }
}

function blur() {
    setTimeout(() => {
        open.value = false;
    }, 120);
}

const shown = computed(() => open.value && (busy.value || results.value.length > 0 || wantsSuggestions(model.value)));

// the list floats over the page (position: fixed), so a panel never cuts it off
const field = ref(null);
const place = ref(null);
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
onBeforeUnmount(() => {
    clearTimeout(timer);
    follow(false);
});
</script>

<template>
    <div ref="field" class="k-picker k-link-input" :class="{ 'is-open': shown }">
        <input
            :id="id"
            :value="model"
            class="k-input"
            role="combobox"
            autocomplete="off"
            spellcheck="false"
            aria-autocomplete="list"
            :aria-expanded="shown ? 'true' : 'false'"
            :aria-controls="listId"
            :aria-activedescendant="shown && active >= 0 && results[active] ? `${listId}-${active}` : undefined"
            :aria-describedby="describedBy || undefined"
            :aria-invalid="invalid ? 'true' : undefined"
            :placeholder="placeholder || undefined"
            :disabled="disabled"
            maxlength="2048"
            @input="input"
            @keydown="key"
            @blur="blur"
        />
        <ul v-show="shown" :id="listId" class="k-picker__results" role="listbox" :aria-label="__('Pages')" :style="place">
            <li v-if="busy && !results.length" class="k-picker__hint">{{ __('Searching…') }}</li>
            <li v-else-if="!results.length" class="k-picker__hint">{{ __('No published page has that title. Type an address instead, such as /about.') }}</li>
            <li
                v-for="(item, i) in results"
                :id="`${listId}-${i}`"
                :key="item.id"
                role="option"
                class="k-picker__option"
                :aria-selected="i === active ? 'true' : 'false'"
                @mousedown.prevent="choose(item)"
                @mouseenter="active = i"
            >
                <span class="k-picker__label"><KIcon name="file-text" /> {{ item.label }}</span>
                <span v-if="item.meta" class="k-picker__meta">{{ item.meta }}</span>
            </li>
        </ul>
    </div>
</template>
