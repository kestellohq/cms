<script setup>
import { KImage } from '@kestello/app';
import { __ } from '@kestello/sdk';
import KIcon from './KIcon.vue';

/**
 * Media library items as a grid of tiles.
 *   <KMediaGrid :items="items" :selected="ids" @pick="open" />
 * With `selectable`, tiles are checkboxes (aria-pressed); otherwise buttons.
 */
const props = defineProps({
    items: { type: Array, required: true },
    selected: { type: Array, default: () => [] },
    selectable: Boolean,
    current: { type: [Number, null], default: null },
    label: { type: String, default: '' },
});
defineEmits(['pick']);

const ICONS = {
    pdf: 'file-pdf',
    doc: 'file-doc',
    docx: 'file-doc',
    odt: 'file-doc',
    rtf: 'file-text',
    txt: 'file-text',
    csv: 'file-csv',
    xls: 'file-xls',
    xlsx: 'file-xls',
    ods: 'file-csv',
    ppt: 'file-ppt',
    pptx: 'file-ppt',
    odp: 'file-ppt',
    zip: 'file-zip',
};
const KIND_ICONS = { audio: 'file-audio', video: 'file-video', archive: 'file-zip', document: 'file' };
const icon = (m) => ICONS[m.ext] ?? KIND_ICONS[m.kind] ?? 'file';
const isOn = (m) => props.selected.includes(m.id);
</script>

<template>
    <ul class="k-media-grid" :aria-label="label || undefined">
        <li v-for="m in items" :key="m.id">
            <button
                type="button"
                class="k-media-tile"
                :class="{ 'is-selected': isOn(m), 'is-current': current === m.id }"
                :aria-pressed="selectable ? (isOn(m) ? 'true' : 'false') : undefined"
                :aria-current="!selectable && current === m.id ? 'true' : undefined"
                :title="m.filename"
                @click="$emit('pick', m)"
            >
                <span class="k-media-tile__thumb">
                    <KImage v-if="(m.kind === 'image' || m.sizes?.thumb) && m.url" :media="m" size="thumb" sizes="160px" />
                    <span v-else class="k-media-tile__file"
                        ><KIcon :name="icon(m)" /><span class="k-media-tile__ext">{{ m.ext }}</span></span
                    >
                    <span v-if="selectable && isOn(m)" class="k-media-tile__check" aria-hidden="true"><KIcon name="check" /></span>
                </span>
                <span class="k-media-tile__name">{{ m.title }}</span>
                <span v-if="m.kind === 'image' && !m.alt" class="k-media-tile__flag">{{ __('No alt text') }}</span>
            </button>
        </li>
    </ul>
</template>
