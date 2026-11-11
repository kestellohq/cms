<script setup>
import { computed, ref } from 'vue';
import { Link, useRouter } from '@kestello/app';
import { notify, useApi, __ } from '@kestello/sdk';
import { KBadge, KButton } from '@kestello/ui';
import { badge, slots } from './language-list.js';

/**
 * An entry in the site's other languages: a link to each translation, and
 * a button to start one where there is none yet (a draft with the same
 * fields, opened in the editor). Compact in the content list, one line per
 * language in the editor's Translations card.
 */
const props = defineProps({
    type: { type: String, required: true },
    entry: { type: Object, required: true }, // { id, locale, translations }
    languages: { type: Array, required: true }, // GET /content/languages → languages
    main: { type: String, default: '' }, // the main language's code, for its badge
    compact: Boolean,
    canTranslate: Boolean,
});

const api = useApi();
const router = useRouter();
const list = computed(() => slots(props.entry, props.languages));
const starting = ref(null); // the language a translation is being started in ('' is the main one)
const STATUS = { draft: __('Draft'), pending: __('In review'), scheduled: __('Scheduled'), published: __('Published'), private: __('Private') };

async function translate(slot) {
    starting.value = slot.code;
    try {
        const { data } = await api.post(`/content/${props.type}/entries/${props.entry.id}/translations`, { locale: slot.code });
        router.visit(`/admin/content/${props.type}/${data.id}`);
    } catch (e) {
        notify.error(e);
    } finally {
        starting.value = null;
    }
}
</script>

<template>
    <ul class="k-translations" :class="{ 'is-compact': compact }">
        <li v-for="slot in list" :key="slot.code" class="k-translations__item">
            <template v-if="slot.translation">
                <Link
                    :href="`/admin/content/${type}/${slot.translation.id}`"
                    class="k-translations__link"
                    :aria-label="__('{language}: {title}', { language: slot.name, title: slot.translation.title || __('(untitled)') })"
                >
                    <KBadge :tone="slot.translation.status === 'published' ? 'success' : 'neutral'">{{ badge(slot.code, main) }}</KBadge>
                    <span v-if="!compact" class="k-translations__title">{{ slot.translation.title || __('(untitled)') }}</span>
                </Link>
                <span v-if="!compact" class="k-muted k-small">{{ STATUS[slot.translation.status] ?? slot.translation.status }}</span>
            </template>
            <template v-else-if="canTranslate">
                <KButton
                    size="sm"
                    variant="ghost"
                    icon="plus"
                    :loading="starting === slot.code"
                    :disabled="starting !== null"
                    :aria-label="__('Translate into {language}', { language: slot.name })"
                    @click="translate(slot)"
                    >{{ compact ? badge(slot.code, main) : __('Translate into {language}', { language: slot.name }) }}</KButton
                >
            </template>
            <span v-else class="k-muted k-small">{{ compact ? '' : __('Not translated into {language}', { language: slot.name }) }}</span>
        </li>
    </ul>
</template>

<style scoped>
.k-translations {
    display: grid;
    gap: 8px;
    margin: 0;
    padding: 0;
    list-style: none;
}
.k-translations.is-compact {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
}
.k-translations__item {
    display: flex;
    align-items: center;
    gap: 8px;
}
.k-translations__link {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    min-width: 0;
    color: inherit;
    text-decoration: none;
}
.k-translations__link:hover .k-translations__title {
    text-decoration: underline;
}
.k-translations__title {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
</style>
