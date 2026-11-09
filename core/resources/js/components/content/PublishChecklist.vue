<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useApi, __ } from '@kestello/sdk';
import { KCard, KIcon } from '@kestello/ui';

/**
 * "Before publishing" on the entry editor: the type's editorial checklist,
 * checked against what is typed (not only what is saved). Required items
 * that fail hold publishing back; recommended ones are advice. Shown only
 * for types that have a checklist.
 */
const props = defineProps({
    typeName: { type: String, required: true },
    entryId: { type: Number, default: null },
    form: { type: Object, required: true }, // { title, excerpt, data, terms }
    error: { type: String, default: '' }, // the server's last refusal
});
const api = useApi();
const items = ref([]);
const failed = ref(null);
let timer = null;
let seq = 0;

async function check() {
    const mine = ++seq;
    try {
        const { data } = await api.post(`/content/${props.typeName}/checklist`, {
            entry: props.entryId,
            title: props.form.title ?? '',
            excerpt: props.form.excerpt ?? null,
            data: props.form.data ?? {},
            terms: props.form.terms ?? {},
        });
        if (mine === seq) {
            items.value = data;
            failed.value = null;
        }
    } catch (e) {
        if (mine === seq) failed.value = e;
    }
}
watch(
    () => [props.entryId, props.form.title, props.form.excerpt, props.form.data, props.form.terms],
    () => {
        clearTimeout(timer);
        timer = setTimeout(check, 400);
    },
    { deep: true, immediate: true },
);
onBeforeUnmount(() => clearTimeout(timer));

const waiting = computed(() => items.value.filter((i) => i.level === 'required' && i.passed === false).length);
const summary = computed(() =>
    waiting.value === 0
        ? __('Everything required is done.')
        : __('{n, plural, one {Publishing waits for # required item.} other {Publishing waits for # required items.}}', { n: waiting.value }),
);
const icon = (i) => (i.passed === true ? 'check-circle' : i.passed === null ? 'minus-circle' : i.level === 'required' ? 'warning-circle' : 'circle');
const state = (i) =>
    i.passed === true ? __('Done') : i.passed === null ? __('Not available') : i.level === 'required' ? __('Required, not done') : __('Recommended, not done');
</script>

<template>
    <KCard :title="__('Before publishing')">
        <p class="k-small k-checklist__summary" :class="{ 'is-waiting': waiting }" aria-live="polite">{{ items.length ? summary : __('Checking…') }}</p>
        <ul v-if="items.length" class="k-checklist">
            <li v-for="(i, n) in items" :key="n" class="k-checklist__item" :class="[`is-${i.passed === true ? 'done' : i.passed === null ? 'off' : i.level}`]">
                <KIcon :name="icon(i)" class="k-checklist__icon" aria-hidden="true" />
                <span class="k-checklist__label">{{ i.label }}</span>
                <span class="k-sr-only">{{ state(i) }}</span>
                <span v-if="i.level === 'required' && i.passed !== true" class="k-checklist__tag k-small" aria-hidden="true">{{ __('Required') }}</span>
            </li>
        </ul>
        <p v-if="error" class="k-field__error" role="alert">{{ error }}</p>
        <p v-else-if="failed" class="k-muted k-small" role="alert">{{ failed.message }}</p>
    </KCard>
</template>

<style scoped>
.k-checklist__summary {
    margin: 0 0 8px;
    color: var(--k-ink-2);
}
.k-checklist__summary.is-waiting {
    color: var(--k-ink);
    font-weight: 600;
}
.k-checklist {
    list-style: none;
    margin: 0;
    padding: 0;
    display: grid;
    gap: 6px;
}
.k-checklist__item {
    display: flex;
    align-items: baseline;
    gap: 8px;
    font-size: 14px;
    line-height: 1.4;
}
.k-checklist__icon {
    flex: none;
    position: relative;
    top: 2px;
    color: var(--k-ink-3);
}
.k-checklist__item.is-done .k-checklist__icon {
    color: var(--k-success);
}
.k-checklist__item.is-required .k-checklist__icon {
    color: var(--k-warning);
}
.k-checklist__item.is-done .k-checklist__label,
.k-checklist__item.is-off .k-checklist__label {
    color: var(--k-ink-2);
}
.k-checklist__label {
    flex: 1;
    min-width: 0;
}
.k-checklist__tag {
    flex: none;
    color: var(--k-ink-2);
}
</style>
