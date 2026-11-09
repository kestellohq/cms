<script setup>
import { computed } from 'vue';
import { __ } from '@kestello/sdk';
import { KButton, KField, KInput, KSelect } from '@kestello/ui';

/**
 * The builder's "Before publishing" list: which checks an entry of this type
 * must (required) or should (recommended) pass before it is published.
 * Edits the definition's checklist in place: [{ check, level, taxonomy?, min? }].
 */
const items = defineModel({ type: Array, default: () => [] });
const props = defineProps({
    checks: { type: Array, default: () => [] }, // [{ name, label, needs }]
    taxonomies: { type: Array, default: () => [] }, // [{ name, label }]
    errors: { type: Object, default: () => ({}) },
});
const MAX = 20;

const byName = computed(() => Object.fromEntries(props.checks.map((c) => [c.name, c])));
const checkOptions = computed(() => props.checks.map((c) => ({ value: c.name, label: c.label })));
const levelOptions = [
    { value: 'required', label: __('Required') },
    { value: 'recommended', label: __('Recommended') },
];
const taxonomyOptions = computed(() => props.taxonomies.map((t) => ({ value: t.name, label: t.label })));
const err = (i) => {
    const e = props.errors[`checklist.${i}`];
    return Array.isArray(e) ? e[0] : (e ?? '');
};

function add() {
    items.value = [...items.value, { check: 'excerpt', level: 'recommended' }];
}
function remove(i) {
    items.value = items.value.filter((_, n) => n !== i);
}
// the options a check needs, and none it doesn't
function setCheck(i, name) {
    const needs = byName.value[name]?.needs ?? null;
    const { taxonomy, min, ...rest } = items.value[i];
    const next = { ...rest, check: name };
    if (needs === 'taxonomy') next.taxonomy = taxonomy ?? props.taxonomies[0]?.name ?? '';
    if (needs === 'min') next.min = min ?? 300;
    items.value = items.value.map((it, n) => (n === i ? next : it));
}
</script>

<template>
    <div class="k-ct-checklist">
        <p class="k-muted k-small">
            {{
                __(
                    'Required items hold publishing back until they are done; recommended ones are advice in the editor. Drafts and submissions for review are never held back.',
                )
            }}
        </p>
        <ol v-if="items.length" class="k-ct-checklist__list">
            <li v-for="(item, i) in items" :key="i" class="k-ct-checklist__item">
                <div class="k-ct-checklist__row">
                    <KField v-slot="{ id }" :label="__('Check')" class="k-ct-checklist__check">
                        <KSelect :id="id" :model-value="item.check" :options="checkOptions" @update:model-value="setCheck(i, $event)" />
                    </KField>
                    <KField v-if="byName[item.check]?.needs === 'taxonomy'" v-slot="{ id }" :label="__('Taxonomy')">
                        <KSelect :id="id" v-model="item.taxonomy" :options="taxonomyOptions" />
                    </KField>
                    <KField v-if="byName[item.check]?.needs === 'min'" v-slot="{ id }" :label="__('Words')">
                        <KInput :id="id" v-model.number="item.min" type="number" min="1" max="100000" />
                    </KField>
                    <KField v-slot="{ id }" :label="__('Level')">
                        <KSelect :id="id" v-model="item.level" :options="levelOptions" />
                    </KField>
                    <KButton
                        variant="ghost"
                        size="sm"
                        icon="x"
                        class="k-ct-checklist__remove"
                        :aria-label="__('Remove {check}', { check: byName[item.check]?.label ?? item.check })"
                        @click="remove(i)"
                    />
                </div>
                <p v-if="err(i)" class="k-field__error">{{ err(i) }}</p>
            </li>
        </ol>
        <KButton size="sm" icon="plus" :disabled="items.length >= MAX" @click="add">{{ __('Add a check') }}</KButton>
        <p v-if="errors.checklist" class="k-field__error">{{ Array.isArray(errors.checklist) ? errors.checklist[0] : errors.checklist }}</p>
    </div>
</template>

<style scoped>
.k-ct-checklist {
    display: grid;
    gap: 10px;
}
.k-ct-checklist > p {
    margin: 0;
}
.k-ct-checklist__list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: grid;
    gap: 10px;
}
.k-ct-checklist__item {
    padding-bottom: 10px;
    border-bottom: 1px solid var(--k-rule-soft);
}
.k-ct-checklist__row {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: 8px;
}
.k-ct-checklist__row > * {
    flex: 1 1 8rem;
    min-width: 0;
}
.k-ct-checklist__row > .k-ct-checklist__check {
    flex-basis: 100%;
}
.k-ct-checklist__row > .k-ct-checklist__remove {
    flex: none;
}
.k-ct-checklist > .k-btn {
    justify-self: start;
}
</style>
