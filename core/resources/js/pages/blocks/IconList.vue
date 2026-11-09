<script setup>
import Icon from '../../shared/Icon.vue';
import { ICONS } from '../../shared/icons.js';

// core/icon-list: short points, each after an icon (names: Kestello\Cms\Blocks\Icons::CONTENT).
defineProps({ items: { type: Array, default: () => [] }, columns: { type: [String, Number], default: '1' }, tone: { type: String, default: 'accent' } });
</script>

<template>
    <ul
        v-if="items.length"
        class="k-b-iconlist"
        :class="[`is-cols-${[1, 2, 3].includes(Number(columns)) ? Number(columns) : 1}`, tone === 'text' ? 'is-tone-text' : 'is-tone-accent']"
    >
        <li v-for="(item, i) in items" :key="i">
            <Icon class="k-b-iconlist__icon" :d="ICONS[item?.icon] ?? ICONS.check" />
            <span :data-k-field="`items.${i}.text`" data-placeholder="Point">{{ item?.text }}</span>
        </li>
    </ul>
</template>

<style scoped>
:where(.k-b-iconlist) {
    margin-block: 0;
}
.k-b-iconlist {
    display: grid;
    gap: 0.65rem 2rem;
    padding: 0;
    list-style: none;
    font-size: 1.075rem;
    line-height: 1.5;
}
@media (min-width: 600px) {
    .k-b-iconlist.is-cols-2 {
        grid-template-columns: repeat(2, 1fr);
    }
    .k-b-iconlist.is-cols-3 {
        grid-template-columns: repeat(3, 1fr);
    }
}
.k-b-iconlist li {
    display: flex;
    align-items: flex-start;
    gap: 0.6rem;
}
.k-b-iconlist__icon {
    flex: none;
    width: 1.25em;
    height: 1.25em;
    margin-top: 0.125em;
}
.k-b-iconlist.is-tone-accent .k-b-iconlist__icon {
    color: var(--block-accent, #2448c9);
}
</style>
