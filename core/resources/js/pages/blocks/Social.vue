<script setup>
import Icon from '../../shared/Icon.vue';
import { SOCIAL } from '../../shared/social-icons.js';

// core/social: links to profiles as their icons. The server adds each network's name
// (CoreListBlocks::social): screen readers hear it, and "Icons and names" shows it.
defineProps({
    items: { type: Array, default: () => [] },
    size: { type: String, default: 'md' },
    look: { type: String, default: 'icons' },
    align: { type: String, default: 'start' },
});
</script>

<template>
    <ul
        v-if="items.length"
        class="k-b-social"
        :class="[
            `is-${['sm', 'md', 'lg'].includes(size) ? size : 'md'}`,
            `is-${['start', 'center', 'end'].includes(align) ? align : 'start'}`,
            look === 'labels' && 'has-labels',
        ]"
    >
        <li v-for="(item, i) in items" :key="i">
            <a class="k-b-social__link" :href="item.url" rel="me noopener">
                <Icon class="k-b-social__icon" :d="SOCIAL[item.network] ?? SOCIAL.website" :label="look === 'labels' ? '' : item.label" />
                <span v-if="look === 'labels'">{{ item.label }}</span>
            </a>
        </li>
    </ul>
</template>

<style scoped>
:where(.k-b-social) {
    margin-block: 0;
}
.k-b-social {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    padding: 0;
    list-style: none;
    --k-social: 2.75rem;
}
.k-b-social.is-sm {
    --k-social: 2.25rem;
    gap: 0.35rem;
}
.k-b-social.is-lg {
    --k-social: 3.25rem;
    gap: 0.75rem;
}
.k-b-social.is-center {
    justify-content: center;
}
.k-b-social.is-end {
    justify-content: flex-end;
}
.k-b-social__link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    min-width: var(--k-social);
    height: var(--k-social);
    border-radius: 999px;
    color: inherit;
    text-decoration: none;
    transition:
        background-color 0.15s,
        color 0.15s;
}
.k-b-social__link:hover {
    background: color-mix(in srgb, currentColor 10%, transparent);
}
.k-b-social__link:focus-visible {
    outline: 2px solid var(--block-accent, #2448c9);
    outline-offset: 2px;
}
.k-b-social__icon {
    width: calc(var(--k-social) * 0.5);
    height: calc(var(--k-social) * 0.5);
}
.k-b-social.has-labels .k-b-social__link {
    padding-inline: 0.9rem 1.1rem;
    border: 1px solid color-mix(in srgb, currentColor 22%, transparent);
    font-weight: 600;
}
@media (prefers-reduced-motion: reduce) {
    .k-b-social__link {
        transition: none;
    }
}
</style>
