<script setup>
import { computed, ref, watch } from 'vue';
import { useApi, __ } from '@kestello/sdk';
import KButton from './KButton.vue';
import KMediaGrid from './KMediaGrid.vue';
import KModal from './KModal.vue';
import KSelect from './KSelect.vue';
import KUploadZone from './KUploadZone.vue';

/**
 * Choose files from the media library (or upload new ones).
 *   <KMediaPicker v-model:open="open" :kinds="['image']" multiple :selected="ids" @select="(items) => …" />
 * Sites can swap it: $cms->admin->replace('media.picker', '@acme/MyPicker') gets the same props and events.
 */
const open = defineModel('open', { type: Boolean, default: false });
const props = defineProps({
    kinds: { type: Array, default: () => [] },
    multiple: Boolean,
    selected: { type: Array, default: () => [] },
    title: { type: String, default: '' },
});
const emit = defineEmits(['select']);
const api = useApi();

const items = ref([]);
const page = ref(1);
const last = ref(1);
const loading = ref(false);
const failed = ref(null);
const search = ref('');
const kind = ref('');
const chosen = ref([]); // media objects, in pick order

const kindOptions = computed(() =>
    props.kinds.length === 1
        ? []
        : [
              { value: '', label: __('All files') },
              ...['image', 'document', 'audio', 'video', 'archive']
                  .filter((k) => !props.kinds.length || props.kinds.includes(k))
                  .map((k) => ({
                      value: k,
                      label: { image: __('Images'), document: __('Documents'), audio: __('Audio'), video: __('Video'), archive: __('Archives') }[k],
                  })),
          ],
);
const effectiveKind = computed(() => kind.value || (props.kinds.length === 1 ? props.kinds[0] : ''));

async function load(reset = false) {
    loading.value = true;
    failed.value = null;
    try {
        let next = reset ? 1 : page.value + 1;
        let found = reset ? [] : [...items.value];
        // with several allowed kinds the server can't filter: read on until a page has some
        for (let tries = 0; tries < 10; tries++) {
            const res = await api.get('/media', { page: next, per_page: 40, search: search.value || undefined, kind: effectiveKind.value || undefined });
            const allowed = props.kinds.length ? res.data.filter((m) => props.kinds.includes(m.kind)) : res.data;
            found = [...found, ...allowed];
            page.value = res.meta.page;
            last.value = res.meta.last_page;
            if (allowed.length || res.meta.page >= res.meta.last_page) break;
            next = res.meta.page + 1;
        }
        items.value = found;
    } catch (e) {
        failed.value = e;
    } finally {
        loading.value = false;
    }
}
let timer;
watch(search, () => {
    clearTimeout(timer);
    timer = setTimeout(() => load(true), 250);
});
watch(kind, () => load(true));
watch(
    open,
    (on) => {
        if (!on) return;
        chosen.value = [];
        load(true).then(() => {
            chosen.value = items.value.filter((m) => props.selected.includes(m.id));
        });
    },
    { immediate: true },
);

const ids = computed(() => chosen.value.map((m) => m.id));
function pick(m) {
    if (!props.multiple) {
        chosen.value = ids.value.includes(m.id) ? [] : [m];
        return;
    }
    chosen.value = ids.value.includes(m.id) ? chosen.value.filter((x) => x.id !== m.id) : [...chosen.value, m];
}
function uploaded(m) {
    if (props.kinds.length && !props.kinds.includes(m.kind)) return;
    items.value = [m, ...items.value];
    pick(m);
}
function confirmPick() {
    emit('select', chosen.value);
    open.value = false;
}
</script>

<template>
    <KModal
        v-model:open="open"
        :title="title || (multiple ? __('Choose files') : kinds.length === 1 && kinds[0] === 'image' ? __('Choose an image') : __('Choose a file'))"
        size="xl"
        @close="open = false"
    >
        <div class="k-mpicker">
            <KUploadZone compact :kind="kinds.length === 1 ? kinds[0] : ''" @uploaded="uploaded" />
            <div class="k-mpicker__bar">
                <label class="k-inline-field k-mpicker__search"
                    ><span>{{ __('Search') }}</span
                    ><input v-model="search" type="search" class="k-input" :aria-label="__('Search by title, file name or alt text')"
                /></label>
                <KSelect v-if="kindOptions.length" v-model="kind" :options="kindOptions" :aria-label="__('Kind of file')" class="k-mpicker__kind" />
            </div>
            <KMediaGrid v-if="items.length" :items="items" :selected="ids" selectable :label="__('Media library')" @pick="pick" />
            <p v-else-if="failed" class="k-mpicker__empty" role="alert">{{ failed.message || __('The media library could not be loaded.') }}</p>
            <p v-else-if="!loading" class="k-muted k-mpicker__empty">
                {{ search ? __('Nothing matches “{q}”.', { q: search }) : __('No files yet. Upload one above.') }}
            </p>
            <div v-if="page < last" class="k-mpicker__more">
                <KButton :loading="loading" @click="load()">{{ __('Show more') }}</KButton>
            </div>
        </div>
        <template #footer>
            <span class="k-muted k-small k-mpicker__count">{{ multiple ? __('{n} selected', { n: chosen.length }) : (chosen[0]?.title ?? '') }}</span>
            <KButton @click="open = false">{{ __('Cancel') }}</KButton>
            <KButton variant="primary" :disabled="!chosen.length" @click="confirmPick">{{
                multiple ? __('Use {n} files', { n: chosen.length }) : __('Use this file')
            }}</KButton>
        </template>
    </KModal>
</template>
