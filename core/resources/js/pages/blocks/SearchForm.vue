<script setup>
import { ref, useId } from 'vue';
import { useRouter } from '@kestello/app';

// core/search-form: a plain GET form to /search, so it works before scripts load too.
const props = defineProps({ label: { type: String, default: 'Search' }, button: { type: String, default: 'Search' }, type: { type: String, default: '' } });
const router = useRouter();
const q = ref('');
const id = `k-b-search-${useId()}`;
function submit() {
    if (q.value.trim()) router.get('/search', { q: q.value.trim(), type: props.type || undefined });
}
</script>

<template>
    <form class="k-b-search" role="search" action="/search" method="get" @submit.prevent="submit">
        <label class="k-b-search__label" :for="id">{{ label }}</label>
        <div class="k-b-search__bar">
            <input :id="id" v-model="q" class="k-b-search__input" type="search" name="q" maxlength="200" autocomplete="off" enterkeyhint="search" />
            <input v-if="type" type="hidden" name="type" :value="type" />
            <button type="submit" class="k-b-search__button">{{ button }}</button>
        </div>
    </form>
</template>

<style scoped>
.k-b-search__label {
    display: block;
    font-weight: 600;
    margin-bottom: 0.5rem;
}
.k-b-search__bar {
    display: flex;
    gap: 0.5rem;
}
.k-b-search__input {
    flex: 1;
    min-width: 0;
    min-height: 2.75rem;
    padding: 0.5rem 0.9rem;
    font: inherit;
    color: inherit;
    background: var(--block-field-bg, #fff);
    border: 1px solid color-mix(in srgb, currentColor 30%, transparent);
    border-radius: var(--block-radius, 6px);
}
.k-b-search__input:focus-visible {
    outline: 3px solid var(--block-accent, #2448c9);
    outline-offset: 1px;
    border-color: transparent;
}
.k-b-search__button {
    min-height: 2.75rem;
    padding: 0.5rem 1.2rem;
    border: 0;
    border-radius: var(--block-button-radius, 999px);
    background: var(--block-accent, #2448c9);
    color: var(--block-accent-ink, #fff);
    font: inherit;
    font-weight: 700;
    cursor: pointer;
}
.k-b-search__button:hover {
    background: var(--block-accent-deep, #1a379e);
}
.k-b-search__button:focus-visible {
    outline: 3px solid var(--block-accent, #2448c9);
    outline-offset: 3px;
}
</style>
