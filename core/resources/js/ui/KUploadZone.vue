<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { formatBytes, notify, uploadMedia, useApi, useResource, __ } from '@kestello/sdk';
import KIcon from './KIcon.vue';

/**
 * Drop files here (or anywhere on the page with `page`) to add them to the
 * media library. Emits `uploaded` with each new media item.
 *   <KUploadZone kind="image" @uploaded="add" />
 */
const props = defineProps({
    kind: { type: String, default: '' }, // restrict the file chooser (image, document…)
    page: Boolean, // accept drops anywhere in the window
    compact: Boolean,
});
const emit = defineEmits(['uploaded']);
const api = useApi();
const limits = useResource('/media/limits');
const input = ref(null);
const over = ref(false);
const queue = reactive([]); // { key, name, size, progress, error, controller }
let key = 0;

const accept = computed(() => {
    const l = limits.data.value;
    if (!l) return '';
    return props.kind ? (l.accept_by_kind?.[props.kind] ?? '') : l.accept;
});
const busy = computed(() => queue.some((q) => !q.error && q.progress < 1));
const hint = computed(() => {
    const l = limits.data.value;
    return l ? __('Up to {size} per file.', { size: formatBytes(l.max_bytes) }) : '';
});

async function add(files) {
    const l = limits.data.value;
    const allowed = accept.value.split(',').filter(Boolean);
    for (const file of files) {
        const ext = '.' + (file.name.split('.').pop() ?? '').toLowerCase();
        const item = reactive({ key: ++key, name: file.name, size: file.size, progress: 0, error: '', controller: new AbortController() });
        queue.unshift(item);
        if (allowed.length && !allowed.includes(ext)) {
            item.error = __('This type of file is not allowed here.');
            continue;
        }
        if (l && file.size > l.max_bytes) {
            item.error = __('Larger than {size}.', { size: formatBytes(l.max_bytes) });
            continue;
        }
        try {
            const media = await uploadMedia(api, file, {
                signal: item.controller.signal,
                onProgress: (f) => {
                    item.progress = Math.min(0.99, f);
                },
            });
            item.progress = 1;
            emit('uploaded', media);
            setTimeout(() => {
                const i = queue.indexOf(item);
                if (i >= 0) queue.splice(i, 1);
            }, 2500);
        } catch (e) {
            if (e.name === 'AbortError') {
                queue.splice(queue.indexOf(item), 1);
                continue;
            }
            item.error = Object.values(e.fieldErrors?.() ?? {})[0] ?? e.message;
        }
    }
}
function choose() {
    input.value?.click();
}
function picked(e) {
    add([...e.target.files]);
    e.target.value = '';
}
function dismiss(item) {
    item.controller.abort();
    const i = queue.indexOf(item);
    if (i >= 0) queue.splice(i, 1);
}

// Page-wide drop target: highlight while files are dragged over the window.
let depth = 0;
const hasFiles = (e) => [...(e.dataTransfer?.types ?? [])].includes('Files');
function enter(e) {
    if (!hasFiles(e)) return;
    depth++;
    over.value = true;
}
function leave() {
    depth = Math.max(0, depth - 1);
    if (!depth) over.value = false;
}
function dragover(e) {
    if (hasFiles(e)) e.preventDefault();
}
function drop(e) {
    if (!hasFiles(e)) return;
    e.preventDefault();
    depth = 0;
    over.value = false;
    if (limits.data.value && !limits.data.value.can_upload) return notify.error(__("You can't upload files."));
    add([...e.dataTransfer.files]);
}
function beforeUnload(e) {
    if (busy.value) {
        e.preventDefault();
        e.returnValue = '';
    }
}
onMounted(() => {
    window.addEventListener('beforeunload', beforeUnload);
    if (!props.page) return;
    window.addEventListener('dragenter', enter);
    window.addEventListener('dragleave', leave);
    window.addEventListener('dragover', dragover);
    window.addEventListener('drop', drop);
});
onBeforeUnmount(() => {
    window.removeEventListener('beforeunload', beforeUnload);
    window.removeEventListener('dragenter', enter);
    window.removeEventListener('dragleave', leave);
    window.removeEventListener('dragover', dragover);
    window.removeEventListener('drop', drop);
});
defineExpose({ choose });
</script>

<template>
    <div
        class="k-upload"
        :class="{ 'is-over': over, 'is-compact': compact }"
        @dragenter.prevent="!page && enter($event)"
        @dragleave="!page && leave()"
        @dragover="dragover"
        @drop="!page && drop($event)"
    >
        <input ref="input" type="file" multiple class="k-sr-only" :accept="accept" tabindex="-1" aria-hidden="true" @change="picked" />
        <div class="k-upload__target">
            <KIcon name="cloud-arrow-up" />
            <p class="k-upload__text">
                <button type="button" class="k-link-btn" :disabled="limits.data.value && !limits.data.value.can_upload" @click="choose">
                    {{ __('Choose files') }}
                </button>
                {{ __('or drop them here.') }}
                <span class="k-muted k-small">{{ hint }}</span>
            </p>
        </div>
        <ul v-if="queue.length" class="k-upload__queue" aria-live="polite">
            <li v-for="q in queue" :key="q.key" class="k-upload__item" :class="{ 'has-error': q.error, 'is-done': q.progress === 1 }">
                <span class="k-upload__name">{{ q.name }}</span>
                <span class="k-muted k-small k-num">{{ formatBytes(q.size) }}</span>
                <span v-if="q.error" class="k-upload__error">{{ q.error }}</span>
                <span v-else-if="q.progress === 1" class="k-upload__done"><KIcon name="check" /> {{ __('Added') }}</span>
                <progress v-else class="k-upload__bar" max="1" :value="q.progress" :aria-label="__('Uploading {name}', { name: q.name })"></progress>
                <button v-if="q.progress < 1" type="button" class="k-upload__x" :aria-label="q.error ? __('Dismiss') : __('Cancel upload')" @click="dismiss(q)">
                    <KIcon name="x" />
                </button>
            </li>
        </ul>
    </div>
</template>
