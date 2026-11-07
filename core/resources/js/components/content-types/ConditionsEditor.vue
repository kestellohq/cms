<script setup>
import { computed } from 'vue';
import { __ } from '@kestello/sdk';
import { KButton, KInput, KSelect, KSwitch } from '@kestello/ui';

/**
 * "Show this field only when…" for one field. Rules look at the fields
 * above it ($earlier). Edits field.conditions in place; null means always shown.
 */
const props = defineProps({
    field: { type: Object, required: true },
    earlier: { type: Array, required: true }, // fields above, [{ name, label, type, options }]
    error: { type: String, default: '' },
});

const OPS = [
    { value: 'is', label: __('is') },
    { value: 'is_not', label: __('is not') },
    { value: 'contains', label: __('contains') },
    { value: 'filled', label: __('has a value') },
    { value: 'empty', label: __('is empty') },
];
const on = computed({
    get: () => !!props.field.conditions,
    set: (v) => {
        props.field.conditions = v ? { match: 'all', rules: [blank()] } : null;
    },
});
const candidates = computed(() => props.earlier.filter((f) => f.name && !['group', 'repeater', 'blocks', 'file'].includes(f.type)));
const blank = () => ({ field: candidates.value.at(-1)?.name ?? '', op: 'is', value: '' });
const target = (rule) => props.earlier.find((f) => f.name === rule.field);
const choicesOf = (f) => {
    const c = f?.options?.choices;
    if (!c) return null;
    return (Array.isArray(c) ? c : Object.entries(c).map(([value, label]) => ({ value, label }))).filter((x) => x.value !== '');
};
const opsFor = (rule) =>
    target(rule)?.type === 'boolean'
        ? OPS.filter((o) => ['filled', 'empty'].includes(o.value)).map((o) => ({ ...o, label: o.value === 'filled' ? __('is ticked') : __('is not ticked') }))
        : OPS;
function retarget(rule) {
    if (target(rule)?.type === 'boolean' && !['filled', 'empty'].includes(rule.op)) rule.op = 'filled';
    rule.value = '';
}
</script>

<template>
    <div class="k-cond">
        <KSwitch v-model="on" :disabled="!candidates.length && !on" :label="__('Show only when…')" />
        <span v-if="!candidates.length && !on" class="k-muted k-small">{{ __('Possible for fields below another field.') }}</span>
        <div v-if="field.conditions" class="k-cond__body">
            <div v-if="field.conditions.rules.length > 1" class="k-cond__match">
                <KSelect
                    v-model="field.conditions.match"
                    :aria-label="__('Which rules must hold')"
                    :options="[
                        { value: 'all', label: __('all of these hold') },
                        { value: 'any', label: __('any of these holds') },
                    ]"
                />
            </div>
            <div v-for="(rule, i) in field.conditions.rules" :key="i" class="k-cond__rule">
                <KSelect
                    v-model="rule.field"
                    :aria-label="__('Field')"
                    :options="candidates.map((f) => ({ value: f.name, label: f.label || f.name }))"
                    @update:model-value="retarget(rule)"
                />
                <KSelect v-model="rule.op" :aria-label="__('Comparison')" :options="opsFor(rule)" />
                <template v-if="!['filled', 'empty'].includes(rule.op)">
                    <KSelect
                        v-if="choicesOf(target(rule))"
                        v-model="rule.value"
                        :aria-label="__('Value')"
                        :options="[{ value: '', label: __('Choose…') }, ...choicesOf(target(rule)).map((c) => ({ value: c.value, label: c.label || c.value }))]"
                    />
                    <KInput v-else v-model="rule.value" :aria-label="__('Value')" maxlength="200" />
                </template>
                <span v-else></span>
                <KButton
                    size="sm"
                    variant="ghost"
                    icon="x"
                    :aria-label="__('Remove rule {n}', { n: i + 1 })"
                    @click="
                        field.conditions.rules.splice(i, 1);
                        if (!field.conditions.rules.length) field.conditions = null;
                    "
                />
            </div>
            <KButton v-if="field.conditions.rules.length < 10" size="sm" icon="plus" @click="field.conditions.rules.push(blank())">{{
                __('Add a rule')
            }}</KButton>
        </div>
        <p v-if="error" class="k-field__error" role="alert">{{ error }}</p>
    </div>
</template>

<style scoped>
.k-cond {
    margin-top: 12px;
    padding-top: 12px;
    border-top: 1px dashed var(--k-rule);
}
.k-cond__body {
    margin-top: 10px;
}
.k-cond__match {
    max-width: 16rem;
    margin-bottom: 8px;
}
.k-cond__rule {
    display: grid;
    grid-template-columns: minmax(0, 1.2fr) minmax(0, 0.9fr) minmax(0, 1.2fr) auto;
    gap: 8px;
    align-items: center;
    margin-bottom: 6px;
    max-width: 44rem;
}
@media (max-width: 640px) {
    .k-cond__rule {
        grid-template-columns: 1fr 1fr;
    }
}
</style>
