<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { Link } from '@kestello/app';
import { __ } from '@kestello/sdk';
import { KIcon } from '@kestello/ui';
import { STORAGE_KEY, currentEntry, readFolded, toggleFolded, unfold, visibleGroups } from './nav.js';

/**
 * The admin menu: groups that fold, each entry with its icon. The group of
 * the page you are on is always open, and its entry scrolled into view.
 * What is folded is remembered per browser.
 */
const props = defineProps({
    /** The menu from the server, already limited to what this person may open. */
    groups: { type: Array, required: true },
    /** The current path, without query or trailing slash. */
    path: { type: String, required: true },
});

const root = ref(null);
const shown = computed(() => visibleGroups(props.groups));
const current = computed(() => currentEntry(props.groups, props.path));

const folded = ref(readFolded(null));
function remember() {
    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(folded.value));
    } catch {
        /* private mode: folding lasts until the page reloads */
    }
}
function toggle(id) {
    folded.value = toggleFolded(folded.value, id);
    remember();
}
function isOpen(group) {
    return !group.label || !folded.value.includes(group.id);
}

watch(
    () => current.value?.group.id,
    (id) => {
        const next = unfold(folded.value, id);
        if (next !== folded.value) {
            folded.value = next;
            remember();
        }
    },
);

onMounted(async () => {
    try {
        folded.value = unfold(readFolded(localStorage.getItem(STORAGE_KEY)), current.value?.group.id);
    } catch {
        folded.value = unfold(folded.value, current.value?.group.id);
    }
    await nextTick();
    root.value?.querySelector('[aria-current="page"]')?.scrollIntoView({ block: 'nearest' });
});
</script>

<template>
    <nav ref="root" class="k-nav" :aria-label="__('Admin menu')">
        <div v-for="group in shown" :key="group.id" class="k-nav__group" :class="{ 'is-folded': !isOpen(group) }">
            <h2 v-if="group.label" class="k-nav__heading">
                <button
                    type="button"
                    class="k-nav__toggle"
                    :class="{ 'is-current': current?.group.id === group.id }"
                    :aria-expanded="isOpen(group) ? 'true' : 'false'"
                    :aria-controls="`k-nav-${group.id}`"
                    @click="toggle(group.id)"
                >
                    <span>{{ group.label }}</span>
                    <KIcon name="caret-down" class="k-nav__caret" />
                </button>
            </h2>
            <ul v-show="isOpen(group)" :id="`k-nav-${group.id}`">
                <li v-for="item in group.items" :key="item.id">
                    <a v-if="item.href.startsWith('https://')" :href="item.href" class="k-nav__link" target="_blank" rel="noopener noreferrer">
                        <KIcon :name="item.icon" class="k-nav__icon" /><span class="k-nav__label">{{ item.label }}</span
                        ><KIcon name="arrow-square-out" class="k-nav__external" :label="__('opens in a new tab')" />
                    </a>
                    <Link
                        v-else
                        :href="item.href"
                        class="k-nav__link"
                        :class="{ 'is-active': item === current?.item }"
                        :aria-current="item === current?.item ? 'page' : undefined"
                    >
                        <KIcon :name="item.icon" class="k-nav__icon" /><span class="k-nav__label">{{ item.label }}</span>
                    </Link>
                </li>
            </ul>
        </div>
    </nav>
</template>
