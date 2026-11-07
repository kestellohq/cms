<script setup>
import { computed } from 'vue';
import { Link } from '@kestello/app';
import { useResource, __ } from '@kestello/sdk';
import { KCard } from '@kestello/ui';

/**
 * "Linked from" on the entry editor: the entries that point at this one
 * through a relationship field (two-way relationships). Hidden while
 * nothing links here, so entries without relationships stay calm.
 */
const props = defineProps({ base: { type: String, required: true } });
const links = useResource(() => `${props.base}/linked-from`);
const rows = computed(() => links.data.value ?? []);
const STATUS = {
    draft: __('Draft'),
    pending: __('In review'),
    scheduled: __('Scheduled'),
    private: __('Private'),
};
const where = (link) => __('{type} · in {fields}', { type: link.type_label, fields: link.fields.map((f) => f.label).join(', ') });
</script>

<template>
    <KCard v-if="rows.length" :title="__('Linked from')" flush>
        <ul class="k-linked">
            <li v-for="link in rows" :key="link.id" class="k-linked__item">
                <Link :href="`/admin/content/${link.type}/${link.id}`" class="k-linked__title">{{ link.title || __('(untitled)') }}</Link>
                <span class="k-muted k-small"
                    >{{ where(link) }}<template v-if="STATUS[link.status]"> · {{ STATUS[link.status] }}</template></span
                >
            </li>
        </ul>
    </KCard>
</template>
