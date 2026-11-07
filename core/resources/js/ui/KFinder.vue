<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { useRouter } from '@kestello/app';
import { useApi, __ } from '@kestello/sdk';
import KIcon from './KIcon.vue';
import KModal from './KModal.vue';
import KSpinner from './KSpinner.vue';

/**
 * The admin finder (Ctrl/Cmd+K): type a few letters, move with the arrow
 * keys, press Enter to open. Results come from GET /admin/find, already
 * limited to what the user may open. A combobox with a grouped listbox.
 */
const open = defineModel('open', { type: Boolean, default: false });
const api = useApi();
const router = useRouter();

const q = ref('');
const groups = ref([]);
const loading = ref(false);
const failed = ref(false);
const active = ref(0);
const input = ref(null);
const LABELS = { screens: __('Screens'), entries: __('Content'), media: __('Media'), people: __('People') };

const flat = computed(() => groups.value.flatMap((g) => g.items.map((item) => ({ ...item, group: g.id }))));
const optionId = (i) => `k-finder-opt-${i}`;
const indexOf = (gi, ii) => groups.value.slice(0, gi).reduce((n, g) => n + g.items.length, 0) + ii;

let timer = null;
let seq = 0;
watch(q, (value) => {
    clearTimeout(timer);
    const text = value.trim();
    if (!text) {
        groups.value = [];
        loading.value = false;
        return;
    }
    loading.value = true;
    timer = setTimeout(async () => {
        const mine = ++seq;
        try {
            const res = await api.get('/admin/find', { q: text.slice(0, 100) });
            if (mine !== seq) return; // a newer search is on its way
            groups.value = res.data.groups;
            failed.value = false;
            active.value = 0;
        } catch {
            if (mine === seq) failed.value = true;
        } finally {
            if (mine === seq) loading.value = false;
        }
    }, 160);
});
watch(open, async (value) => {
    if (value) {
        q.value = '';
        groups.value = [];
        await nextTick();
        input.value?.focus();
    }
});

function go(item) {
    if (!item) return;
    open.value = false;
    router.visit(item.href);
}
function onKey(event) {
    const n = flat.value.length;
    if (event.key === 'ArrowDown' && n) {
        event.preventDefault();
        active.value = (active.value + 1) % n;
        scroll();
    } else if (event.key === 'ArrowUp' && n) {
        event.preventDefault();
        active.value = (active.value - 1 + n) % n;
        scroll();
    } else if (event.key === 'Home' && n && event.ctrlKey) {
        event.preventDefault();
        active.value = 0;
        scroll();
    } else if (event.key === 'Enter') {
        event.preventDefault();
        go(flat.value[active.value]);
    }
}
function scroll() {
    nextTick(() => document.getElementById(optionId(active.value))?.scrollIntoView({ block: 'nearest' }));
}
const status = computed(() => {
    if (!q.value.trim()) return '';
    if (loading.value) return __('Searching…');
    if (failed.value) return __('Search failed. Try again.');
    return flat.value.length === 0
        ? __('Nothing found for “{q}”.', { q: q.value.trim() })
        : flat.value.length === 1
          ? __('1 result')
          : __('{n} results', { n: flat.value.length });
});
</script>

<template>
    <KModal v-model:open="open" :title="__('Find')" size="md">
        <div class="k-finder">
            <div class="k-finder__bar">
                <KIcon name="magnifying-glass" class="k-finder__glass" />
                <input
                    ref="input"
                    v-model="q"
                    class="k-finder__input"
                    type="text"
                    autocomplete="off"
                    spellcheck="false"
                    maxlength="100"
                    autofocus
                    role="combobox"
                    aria-autocomplete="list"
                    :aria-expanded="flat.length ? 'true' : 'false'"
                    aria-controls="k-finder-list"
                    :aria-activedescendant="flat.length ? optionId(active) : undefined"
                    :aria-label="__('Find screens, content, media and people')"
                    :placeholder="__('Screens, content, media, people')"
                    @keydown="onKey"
                />
                <KSpinner v-if="loading" class="k-finder__spin" />
            </div>
            <p class="k-finder__status" role="status" aria-live="polite">{{ status }}</p>
            <div id="k-finder-list" class="k-finder__list" role="listbox" :aria-label="__('Results')">
                <div v-for="(group, gi) in groups" :key="group.id" class="k-finder__group" role="group" :aria-labelledby="`k-finder-g-${group.id}`">
                    <p :id="`k-finder-g-${group.id}`" class="k-finder__heading" role="presentation">{{ LABELS[group.id] ?? group.label }}</p>
                    <div
                        v-for="(item, ii) in group.items"
                        :id="optionId(indexOf(gi, ii))"
                        :key="item.href"
                        class="k-finder__item"
                        role="option"
                        :aria-selected="indexOf(gi, ii) === active ? 'true' : 'false'"
                        @mousemove="active = indexOf(gi, ii)"
                        @click="go(item)"
                    >
                        <KIcon :name="item.icon" class="k-finder__icon" />
                        <span class="k-finder__label">{{ item.label }}</span>
                        <span class="k-finder__detail">{{ item.detail }}</span>
                    </div>
                </div>
            </div>
            <p v-if="!q.trim()" class="k-finder__hint">{{ __('Type a few letters of a title, file name, person or screen.') }}</p>
        </div>
        <template #footer>
            <p class="k-finder__keys">
                <kbd :aria-label="__('Up arrow')"><KIcon name="arrow-up" /></kbd><kbd :aria-label="__('Down arrow')"><KIcon name="arrow-down" /></kbd>
                {{ __('to move') }} <kbd>Enter</kbd> {{ __('to open') }} <kbd>Esc</kbd> {{ __('to close') }}
            </p>
        </template>
    </KModal>
</template>
