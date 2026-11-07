<script setup>
import { computed, ref, watch } from 'vue';
import { KImage } from '@kestello/app';
import { useApi, useReplacement, __ } from '@kestello/sdk';
import { KButton, KExtension, KIcon, KMediaPicker } from '@kestello/ui';

const model = defineModel({ default: null });
const props = defineProps({
    field: { type: Object, required: true },
    id: { type: String, default: '' },
    invalid: Boolean,
    disabled: Boolean,
    errors: { type: Object, default: () => ({}) },
    path: { type: String, default: '' },
});
const api = useApi();
// A plugin can provide the picker ($cms->admin->replace('media.picker', …)); it gets the same props and emits "select".
const replacement = useReplacement('media.picker');
const multiple = computed(() => !!props.field.options.multiple);
const kinds = computed(() => props.field.options.kinds ?? []);
const ids = computed(() => (model.value === null || model.value === undefined ? [] : Array.isArray(model.value) ? model.value : [model.value]));

const known = ref({}); // id => media
const items = computed(() => ids.value.map((id) => known.value[id] ?? { id, title: '…', kind: 'document', ext: '' }));
watch(
    ids,
    async (list) => {
        const missing = list.filter((id) => !known.value[id]);
        if (!missing.length) return;
        let res;
        try {
            res = await api.get('/media', { ids: missing.join(','), per_page: 100 });
        } catch {
            // the tiles keep their ids and say so, instead of "…" forever
            for (const id of missing) known.value[id] ??= { id, title: __('Could not load this file'), kind: 'missing', ext: '' };
            return;
        }
        known.value = { ...known.value, ...Object.fromEntries(res.data.map((m) => [m.id, m])) };
        for (const id of missing) if (!known.value[id]) known.value[id] = { id, title: __('Deleted file'), kind: 'missing', ext: '' };
    },
    { immediate: true },
);

const open = ref(false);
function select(picked) {
    for (const m of picked) known.value[m.id] = m;
    const chosen = picked.map((m) => m.id);
    model.value = multiple.value ? [...ids.value, ...chosen.filter((id) => !ids.value.includes(id))] : (chosen[0] ?? null);
}
function remove(id) {
    model.value = multiple.value ? ids.value.filter((x) => x !== id) : null;
}
function move(i, delta) {
    const list = [...ids.value];
    [list[i], list[i + delta]] = [list[i + delta], list[i]];
    model.value = list;
}
const label = computed(() =>
    multiple.value
        ? __('Add files')
        : ids.value.length
          ? __('Replace')
          : kinds.value.length === 1 && kinds.value[0] === 'image'
            ? __('Choose an image')
            : __('Choose a file'),
);
</script>

<template>
    <div :id="id" class="k-mfield" :class="{ 'is-invalid': invalid }">
        <ul v-if="items.length" class="k-mfield__list">
            <li v-for="(m, i) in items" :key="m.id" class="k-mfield__item">
                <span class="k-mfield__thumb">
                    <KImage v-if="(m.kind === 'image' || m.sizes?.thumb) && m.url" :media="m" size="thumb" sizes="56px" />
                    <KIcon v-else :name="m.kind === 'missing' ? 'warning' : 'file'" />
                </span>
                <span class="k-mfield__meta">
                    <span class="k-mfield__title">{{ m.title }}</span>
                    <span class="k-muted k-small">{{
                        m.kind === 'missing'
                            ? __('Removed from the library')
                            : [m.ext?.toUpperCase(), m.width ? `${m.width} × ${m.height}` : ''].filter(Boolean).join(' · ')
                    }}</span>
                </span>
                <span v-if="!disabled" class="k-mfield__tools">
                    <template v-if="multiple && items.length > 1">
                        <KButton size="sm" variant="ghost" icon="arrow-up" :disabled="i === 0" :aria-label="__('Move up')" @click="move(i, -1)" />
                        <KButton
                            size="sm"
                            variant="ghost"
                            icon="arrow-down"
                            :disabled="i === items.length - 1"
                            :aria-label="__('Move down')"
                            @click="move(i, 1)"
                        />
                    </template>
                    <KButton size="sm" variant="ghost" icon="x" :aria-label="__('Remove {title}', { title: m.title })" @click="remove(m.id)" />
                </span>
            </li>
        </ul>
        <KButton v-if="!disabled" size="sm" :icon="multiple || !ids.length ? 'plus' : 'arrows-clockwise'" @click="open = true">{{ label }}</KButton>
        <KExtension
            v-if="replacement"
            v-model:open="open"
            :name="replacement"
            :kinds="kinds"
            :multiple="multiple"
            :selected="ids"
            :field="field"
            @select="select"
        />
        <KMediaPicker v-else v-model:open="open" :kinds="kinds" :multiple="multiple" :selected="ids" :title="field.label" @select="select" />
    </div>
</template>
