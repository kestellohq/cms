<script setup>
import { computed } from 'vue';
import { useResource, __ } from '@kestello/sdk';
import { KInput } from '@kestello/ui';
import { codes, setText, texts } from './language-list.js';

/**
 * Settings → General → the site name and tagline in each of the other
 * languages. The value is the setting itself, JSON by language. An empty
 * field shows the main language's text, which a language without its own
 * uses.
 */
const model = defineModel({ type: String, default: '' });
const props = defineProps({
    id: { type: String, default: undefined },
    languages: { type: String, default: '' }, // the other languages as being edited: "es,de"
    name: { type: String, default: '' }, // the main language's site name and tagline, shown as placeholders
    tagline: { type: String, default: '' },
    disabled: Boolean,
});

const info = useResource('/content/languages');
const known = computed(() => [...(info.data.value?.languages ?? []), ...(info.data.value?.choices ?? [])]);
const nameOf = (c) => known.value.find((l) => l.code === c)?.name ?? c;
const list = computed(() => codes(props.languages));
const values = computed(() => texts(model.value));
const set = (code, key, text) => (model.value = setText(model.value, code, key, text));
</script>

<template>
    <div :id="id" class="k-site-texts">
        <p v-if="!list.length" class="k-muted k-small">{{ __('Add another language above to name the site in it.') }}</p>
        <fieldset v-for="c in list" :key="c" class="k-site-texts__language" :disabled="disabled">
            <legend class="k-site-texts__legend">{{ nameOf(c) }}</legend>
            <label class="k-site-texts__label" :for="`k-site-name-${c}`">{{ __('Site name') }}</label>
            <KInput
                :id="`k-site-name-${c}`"
                :model-value="values[c]?.site_name ?? ''"
                :placeholder="name"
                :lang="c"
                maxlength="100"
                @update:model-value="set(c, 'site_name', $event)"
            />
            <label class="k-site-texts__label" :for="`k-site-tagline-${c}`">{{ __('Tagline') }}</label>
            <KInput
                :id="`k-site-tagline-${c}`"
                :model-value="values[c]?.site_description ?? ''"
                :placeholder="tagline"
                :lang="c"
                maxlength="300"
                @update:model-value="set(c, 'site_description', $event)"
            />
        </fieldset>
    </div>
</template>

<style scoped>
.k-site-texts {
    display: grid;
    gap: 16px;
}
.k-site-texts__language {
    display: grid;
    gap: 6px;
    margin: 0;
    padding: 0;
    border: 0;
}
.k-site-texts__legend {
    padding: 0;
    margin-bottom: 4px;
    font-weight: 600;
}
.k-site-texts__label {
    font-size: 13px;
    color: var(--k-ink-2);
}
</style>
