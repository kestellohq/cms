<script setup>
import { computed } from 'vue';
import { Link } from '@kestello/app';
import { useI18n } from '@kestello/sdk';
import { credit, plateNumber, plateRows } from './rooms.js';

/**
 * A label plate: the entry's fields, printed small beside its print. The
 * title links to the entry unless the plate is on the entry's own page.
 */
const props = defineProps({
    title: { type: String, required: true },
    path: { type: String, default: null },
    fields: { type: Object, default: () => ({}) },
    media: { type: Object, default: null }, // the photograph it labels, for its credit
    heading: { type: String, default: 'h3' },
});
const { __ } = useI18n('exhibit');
const rows = computed(() => plateRows(props.fields, __));
const number = computed(() => plateNumber(props.fields, __));
const photo = computed(() => credit(props.media));
</script>

<template>
    <div class="plate">
        <span v-if="number" class="plate__no">{{ number }}</span>
        <component :is="heading" class="plate__title">
            <Link v-if="path" :href="path">{{ title }}</Link>
            <template v-else>{{ title }}</template>
        </component>
        <dl v-if="rows.length">
            <template v-for="row in rows" :key="row.key">
                <dt>{{ row.label }}</dt>
                <dd>{{ row.value }}</dd>
            </template>
        </dl>
        <p v-if="photo" class="plate__credit">{{ photo }}</p>
    </div>
</template>
