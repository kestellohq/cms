<script setup>
import { computed } from 'vue';
import { useResource, __ } from '@kestello/sdk';
import { KInput } from '@kestello/ui';
import { codes, prefixes, setText, texts } from './language-list.js';

/**
 * Settings → General → each content type's and taxonomy's address in the
 * site's other languages: /projects as /es/proyectos. The value is the
 * setting itself, JSON by language. An empty field keeps the main
 * language's address, shown as the placeholder.
 */
const model = defineModel({ type: String, default: '' });
const props = defineProps({
    id: { type: String, default: undefined },
    languages: { type: String, default: '' }, // the other languages as being edited: "es,de"
    invalid: Boolean,
    disabled: Boolean,
});

const info = useResource('/content/languages');
const types = useResource('/content/types');
const taxonomies = useResource('/taxonomies');
const known = computed(() => [...(info.data.value?.languages ?? []), ...(info.data.value?.choices ?? [])]);
const nameOf = (c) => known.value.find((l) => l.code === c)?.name ?? c;
const list = computed(() => codes(props.languages));
const paths = computed(() => prefixes(types.data.value, taxonomies.data.value));
const values = computed(() => texts(model.value));
const shown = (c, path) => String(values.value[c]?.[path] ?? '').replace(/^\/+/, '');
const set = (c, path, text) => (model.value = setText(model.value, c, path, text));
</script>

<template>
    <div :id="id" class="k-path-texts">
        <p v-if="!list.length" class="k-muted k-small">{{ __('Add another language above to give its addresses their own words.') }}</p>
        <p v-else-if="!paths.length" class="k-muted k-small">{{ __('No content type or category has an address of its own yet.') }}</p>
        <fieldset v-for="c in paths.length ? list : []" :key="c" class="k-path-texts__language" :disabled="disabled">
            <legend class="k-path-texts__legend">{{ nameOf(c) }}</legend>
            <div v-for="p in paths" :key="p.path" class="k-path-texts__row">
                <label class="k-path-texts__label" :for="`k-path-${c}-${p.path}`">{{ p.label }}</label>
                <span class="k-path-texts__address">
                    <code class="k-path-texts__prefix">/{{ c }}/</code>
                    <KInput
                        :id="`k-path-${c}-${p.path}`"
                        :model-value="shown(c, p.path)"
                        :placeholder="p.path.replace(/^\//, '')"
                        :invalid="invalid"
                        :lang="c"
                        maxlength="80"
                        autocomplete="off"
                        spellcheck="false"
                        @update:model-value="set(c, p.path, $event)"
                    />
                </span>
            </div>
        </fieldset>
    </div>
</template>

<style scoped>
.k-path-texts {
    display: grid;
    gap: 16px;
}
.k-path-texts__language {
    display: grid;
    gap: 8px;
    margin: 0;
    padding: 0;
    border: 0;
}
.k-path-texts__legend {
    padding: 0;
    margin-bottom: 4px;
    font-weight: 600;
}
.k-path-texts__row {
    display: grid;
    grid-template-columns: minmax(8rem, 12rem) 1fr;
    align-items: center;
    gap: 4px 12px;
}
.k-path-texts__label {
    font-size: 13px;
    color: var(--k-ink-2);
}
.k-path-texts__address {
    display: flex;
    align-items: center;
    gap: 4px;
    min-width: 0;
}
.k-path-texts__prefix {
    color: var(--k-ink-3);
}
@media (max-width: 560px) {
    .k-path-texts__row {
        grid-template-columns: 1fr;
    }
}
</style>
