<script setup>
import { computed, ref } from 'vue';
import { useResource, __ } from '@kestello/sdk';
import { KAlert, KButton, KInput, KSelect } from '@kestello/ui';
import { add, code, codes, dropped, remove } from './language-list.js';

/**
 * Settings → General → Other languages: the languages content is also
 * written in, as a list to add to and remove from. The value is the
 * setting itself ("es,pt-br"). Removing a language that has entries says
 * what happens to them before anything is saved.
 */
const model = defineModel({ type: String, default: '' });
const props = defineProps({
    id: { type: String, default: undefined },
    saved: { type: String, default: '' }, // the stored value, to warn about languages being removed
    main: { type: String, default: '' }, // the main language's code
    invalid: Boolean,
    disabled: Boolean,
});

const info = useResource('/content/languages');
const known = computed(() => [...(info.data.value?.languages ?? []), ...(info.data.value?.choices ?? [])]);
const nameOf = (c) => known.value.find((l) => l.code === c)?.name ?? c;
const chosen = computed(() => codes(model.value));
const options = computed(() => [
    { value: '', label: __('Choose a language') },
    ...(info.data.value?.choices ?? []).filter((l) => !chosen.value.includes(l.code)).map((l) => ({ value: l.code, label: `${l.name} (${l.code})` })),
    { value: '*', label: __('Another language…') },
]);
const picked = ref('');
const typed = ref('');
const typedValid = computed(() => code(typed.value) !== '');
const removing = computed(() => dropped(props.saved, model.value, info.data.value?.languages ?? []));

// (an ICU plural's closing braces would end a {{ }} in the template)
const removalText = (l) =>
    __(
        '{n, plural, one {# entry is} other {# entries are}} written in {language}. They stay, but visitors no longer see them. Add the language again to bring them back.',
        { n: l.entries, language: l.name },
    );

function addPicked() {
    const next = picked.value === '*' ? typed.value : picked.value;
    model.value = add(model.value, next, props.main);
    picked.value = '';
    typed.value = '';
}
</script>

<template>
    <div class="k-languages">
        <ul v-if="chosen.length" class="k-languages__list">
            <li v-for="c in chosen" :key="c" class="k-languages__item">
                <span class="k-languages__name">{{ nameOf(c) }}</span>
                <code class="k-languages__prefix">/{{ c }}</code>
                <KButton
                    variant="ghost"
                    size="sm"
                    icon="x"
                    :disabled="disabled"
                    :aria-label="__('Remove {language}', { language: nameOf(c) })"
                    @click="model = remove(model, c)"
                />
            </li>
        </ul>
        <p v-else class="k-muted k-small">{{ __('None yet: the site is in its main language only.') }}</p>

        <div class="k-languages__add">
            <KSelect :id="id" v-model="picked" :options="options" :invalid="invalid" :disabled="disabled" :aria-label="__('Add a language')" />
            <KInput
                v-if="picked === '*'"
                v-model="typed"
                :placeholder="__('Its code, such as gd')"
                :aria-label="__('Language code')"
                :disabled="disabled"
                autocomplete="off"
                spellcheck="false"
            />
            <KButton :disabled="disabled || !picked || (picked === '*' && !typedValid)" icon="plus" @click="addPicked">{{ __('Add') }}</KButton>
        </div>

        <KAlert v-for="l in removing" :key="l.code" tone="warning">{{ removalText(l) }}</KAlert>
    </div>
</template>

<style scoped>
.k-languages {
    display: grid;
    gap: 12px;
}
.k-languages__list {
    display: grid;
    gap: 4px;
    margin: 0;
    padding: 0;
    list-style: none;
}
.k-languages__item {
    display: flex;
    align-items: center;
    gap: 8px;
}
.k-languages__name {
    font-weight: 500;
}
.k-languages__prefix {
    color: var(--k-ink-3);
}
.k-languages__add {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}
.k-languages__add > :first-child {
    flex: 1 1 14rem;
}
</style>
