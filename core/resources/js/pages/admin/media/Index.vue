<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { Head, KImage, Link, usePage } from '@kestello/app';
import { ApiError, confirm, formatBytes, formatDateTime, notify, useApi, useResource, __ } from '@kestello/sdk';
import { KAlert, KButton, KCard, KEmpty, KField, KIcon, KInput, KMediaGrid, KPageHeader, KTabs, KUploadZone } from '@kestello/ui';
import ImageEditor from '../../../components/media/ImageEditor.vue';

defineOptions({ layout: 'Admin' });

const api = useApi();
const limits = useResource('/media/limits');
const zone = ref(null);

// ── the list ─────────────────────────────────────────────────────────
const kind = ref('');
const search = ref('');
const mine = ref(false);
const items = ref([]);
const meta = ref({ page: 1, last_page: 1, total: 0 });
const loading = ref(false);
const failed = ref(null);

let seq = 0;
async function load(reset = true) {
    const mine = ++seq; // the latest search or filter wins, whichever answers first
    loading.value = true;
    failed.value = null;
    try {
        const res = await api.get('/media', {
            page: reset ? 1 : meta.value.page + 1,
            per_page: 48,
            kind: kind.value || undefined,
            search: search.value || undefined,
            mine: mine.value || undefined,
        });
        if (mine !== seq) return;
        // an upload since the first page shifts the pages by one: skip what is already shown
        const shown = new Set(items.value.map((m) => m.id));
        items.value = reset ? res.data : [...items.value, ...res.data.filter((m) => !shown.has(m.id))];
        meta.value = res.meta;
    } catch (e) {
        if (mine === seq) failed.value = e;
    } finally {
        if (mine === seq) loading.value = false;
    }
}
let timer;
watch(search, () => {
    clearTimeout(timer);
    timer = setTimeout(load, 250);
});
watch([kind, mine], () => load());
load();

const tabs = computed(() => [
    { id: '', label: __('All') },
    ...(limits.data.value?.kinds ?? ['image']).map((k) => ({
        id: k,
        label: { image: __('Images'), document: __('Documents'), audio: __('Audio'), video: __('Video'), archive: __('Archives') }[k],
    })),
]);

function uploaded(m) {
    if ((!kind.value || kind.value === m.kind) && !search.value) {
        items.value = [m, ...items.value];
        meta.value = { ...meta.value, total: meta.value.total + 1 };
    }
}

// ── the open file ────────────────────────────────────────────────────
const current = ref(null);
const form = reactive({ title: '', alt: '', caption: '', focus: [0.5, 0.5] });
const errors = ref({});
const saving = ref(false);
const mayLeave = async () =>
    !dirty.value ||
    (await confirm({
        title: __('Discard unsaved changes?'),
        message: __('Your changes to “{title}” are not saved.', { title: current.value.title }),
        confirmLabel: __('Discard'),
    }));
async function close() {
    if (await mayLeave()) current.value = null;
}
async function open(m) {
    if (!(await mayLeave())) return;
    try {
        current.value = (await api.get(`/media/${m.id}`)).data;
        fill();
    } catch (e) {
        notify.error(e);
    }
}
// /admin/media?file=12 opens that file (links from the admin finder)
const page = usePage();
onMounted(() => {
    const id = Number(new URLSearchParams(page.url.split('?')[1] ?? '').get('file'));
    if (Number.isInteger(id) && id > 0) open({ id });
});
function fill() {
    Object.assign(form, {
        title: current.value.title,
        alt: current.value.alt ?? '',
        caption: current.value.caption ?? '',
        focus: [...(current.value.focus ?? [0.5, 0.5])],
    });
    errors.value = {};
}
const focusMoved = computed(() => !!current.value?.focus && (form.focus[0] !== current.value.focus[0] || form.focus[1] !== current.value.focus[1]));
const dirty = computed(
    () =>
        !!current.value &&
        (form.title !== current.value.title || form.alt !== (current.value.alt ?? '') || form.caption !== (current.value.caption ?? '') || focusMoved.value),
);
async function save() {
    saving.value = true;
    errors.value = {};
    try {
        const body = { title: form.title, alt: form.alt || null, caption: form.caption || null };
        if (focusMoved.value) body.focus = form.focus;
        current.value = (await api.patch(`/media/${current.value.id}`, body)).data;
        items.value = items.value.map((m) => (m.id === current.value.id ? { ...m, ...current.value } : m));
        fill();
        notify.success(__('Saved.'));
    } catch (e) {
        if (e instanceof ApiError && e.status === 422) errors.value = e.fieldErrors();
        else notify.error(e);
    } finally {
        saving.value = false;
    }
}
async function remove() {
    const m = current.value;
    const used = m.usage?.length ?? 0;
    const ok = await confirm({
        title: __('Delete “{title}”?', { title: m.title }),
        message: used
            ? __('{n} entries use this file. They will show a missing file until you pick another one.', { n: used })
            : __('The file is removed from storage. This cannot be undone.'),
        confirmLabel: used ? __('Delete anyway') : __('Delete file'),
        danger: true,
    });
    if (!ok) return;
    try {
        await api.delete(`/media/${m.id}`, { query: { force: used > 0 } });
        items.value = items.value.filter((x) => x.id !== m.id);
        meta.value = { ...meta.value, total: Math.max(0, meta.value.total - 1) };
        current.value = null;
        notify.success(__('Deleted “{title}”.', { title: m.title }));
    } catch (e) {
        notify.error(e);
    }
}
async function copyUrl() {
    try {
        await navigator.clipboard.writeText(new URL(current.value.url, window.location.origin).href);
        notify.success(__('Address copied.'));
    } catch {
        notify.error(__('Could not copy. Select the address and copy it yourself.'));
    }
}
const STATUS = {
    draft: __('Draft'),
    pending: __('In review'),
    scheduled: __('Scheduled'),
    private: __('Private'),
    published: __('Published'),
    trash: __('In the trash'),
};
const isImage = computed(() => current.value?.kind === 'image');

// ── crop, turn and flip (ImageEditor) ────────────────────────────────
const editing = ref(false);
function edited(media) {
    current.value = media;
    items.value = items.value.map((m) => (m.id === media.id ? { ...m, ...media } : m));
    form.focus = [...(media.focus ?? [0.5, 0.5])]; // the server moves it with the picture; other fields keep what was typed
}

// ── focal point: cropped sizes keep it in view ───────────────────────
const canFocus = computed(() => isImage.value && current.value?.can.edit && !!current.value?.sizes);
const round = (n) => Math.round(Math.min(1, Math.max(0, n)) * 100) / 100;
function pickFocus(event) {
    if (!canFocus.value) return;
    const box = event.currentTarget.getBoundingClientRect();
    form.focus = [round((event.clientX - box.left) / box.width), round((event.clientY - box.top) / box.height)];
}
function nudgeFocus(event) {
    const step = event.shiftKey ? 0.1 : 0.02;
    const d = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] }[event.key];
    if (!d) return;
    event.preventDefault();
    form.focus = [round(form.focus[0] + d[0]), round(form.focus[1] + d[1])];
}
const focusLabel = computed(() =>
    __('Focal point: {x}% across, {y}% down. Arrow keys move it.', { x: Math.round(form.focus[0] * 100), y: Math.round(form.focus[1] * 100) }),
);
</script>

<template>
    <Head :title="__('Media')" />
    <KPageHeader :title="__('Media')" :description="__('Images and files for your pages and posts. Drop files anywhere on this page to upload them.')">
        <template #actions>
            <KButton v-if="limits.data.value?.can_upload" variant="primary" icon="upload" @click="zone?.choose()">{{ __('Upload') }}</KButton>
        </template>
    </KPageHeader>

    <KUploadZone v-if="limits.data.value?.can_upload" ref="zone" page class="k-media-upload" @uploaded="uploaded" />

    <div class="k-media-layout" :class="{ 'has-detail': current }">
        <KCard coord="A1" flush class="k-media-browser">
            <template #title
                >{{ __('Library') }} <span class="k-muted k-small k-num">{{ meta.total }}</span></template
            >
            <template #actions>
                <label class="k-choice k-media-mine"
                    ><input v-model="mine" type="checkbox" /> <span>{{ __('Only mine') }}</span></label
                >
                <label class="k-inline-field"
                    ><span>{{ __('Search') }}</span
                    ><input v-model="search" type="search" class="k-input k-media-search" :aria-label="__('Search the media library')"
                /></label>
            </template>
            <div class="k-media-browser__tabs"><KTabs v-model="kind" :tabs="tabs" :label="__('Kind of file')" /></div>
            <KAlert v-if="failed" tone="danger">{{ failed.message }}</KAlert>
            <KMediaGrid v-if="items.length" :items="items" :current="current?.id ?? null" :label="__('Media library')" @pick="open" />
            <KEmpty v-else-if="!loading && !failed" icon="images" :title="search || kind || mine ? __('Nothing matches') : __('No files yet')">
                <p>
                    {{
                        search || kind || mine
                            ? __('Try another search or kind.')
                            : __('Upload images and documents here, then use them in your pages and posts.')
                    }}
                </p>
            </KEmpty>
            <div v-if="meta.page < meta.last_page" class="k-media-more">
                <KButton :loading="loading" @click="load(false)">{{ __('Show more') }}</KButton>
            </div>
        </KCard>

        <KCard v-if="current" coord="B1" class="k-media-detail" :title="current.title">
            <template #actions>
                <KButton size="sm" variant="ghost" icon="x" :aria-label="__('Close details')" @click="close" />
            </template>
            <figure class="k-media-preview">
                <span v-if="isImage && current.url" class="k-media-focus" :class="{ 'is-editable': canFocus }" @click="pickFocus">
                    <KImage :media="current" size="medium" sizes="380px" :alt="current.alt || ''" />
                    <button
                        v-if="canFocus"
                        type="button"
                        class="k-media-focus__dot"
                        :style="{ left: `${form.focus[0] * 100}%`, top: `${form.focus[1] * 100}%` }"
                        :aria-label="focusLabel"
                        @click.stop
                        @keydown="nudgeFocus"
                    ></button>
                </span>
                <span v-else-if="current.sizes?.medium || current.sizes?.thumb" class="k-media-preview__page">
                    <KImage
                        :media="current"
                        :size="current.sizes.medium ? 'medium' : 'thumb'"
                        sizes="380px"
                        :alt="__('First page of {name}', { name: current.title })"
                    />
                </span>
                <span v-else class="k-media-preview__file"
                    ><KIcon name="file" /><span>{{ current.ext.toUpperCase() }}</span></span
                >
            </figure>
            <div v-if="current.can.edit_image || current.edit" class="k-media-edit">
                <KButton v-if="current.can.edit_image" size="sm" icon="crop" @click="editing = true">{{ __('Edit image') }}</KButton>
                <span v-if="current.edit" class="k-muted k-small">{{ __('Cropped, turned or flipped. The original is kept.') }}</span>
            </div>
            <ImageEditor v-if="current.can.edit_image" v-model:open="editing" :media="current" @saved="edited" />
            <p v-if="canFocus" class="k-muted k-small k-media-focus__help">{{ __('Click the image to choose what must stay in view when it is cropped.') }}</p>

            <dl class="k-media-facts">
                <div>
                    <dt>{{ __('File') }}</dt>
                    <dd class="k-mono-input">{{ current.filename }}</dd>
                </div>
                <div>
                    <dt>{{ __('Size') }}</dt>
                    <dd class="k-num">
                        {{ formatBytes(current.size) }}<template v-if="current.width"> · {{ current.width }} × {{ current.height }}</template>
                    </dd>
                </div>
                <div>
                    <dt>{{ __('Uploaded') }}</dt>
                    <dd>
                        {{ formatDateTime(current.created_at) }}<template v-if="current.uploader"> · {{ current.uploader.name }}</template>
                    </dd>
                </div>
                <div v-if="current.url">
                    <dt>{{ __('Address') }}</dt>
                    <dd class="k-media-url">
                        <a :href="current.url" target="_blank" rel="noopener" class="k-mono-input">{{ current.url }}</a>
                        <KButton size="sm" variant="ghost" icon="clipboard" :aria-label="__('Copy address')" @click="copyUrl" />
                    </dd>
                </div>
            </dl>

            <form id="k-media-form" class="k-form" @submit.prevent="save">
                <KField
                    v-if="isImage"
                    v-slot="{ id, describedBy }"
                    :label="__('Alt text')"
                    :help="__('Describe the image for people who can\'t see it. Leave empty only if it is purely decorative.')"
                    :error="errors.alt ?? ''"
                >
                    <textarea
                        :id="id"
                        v-model="form.alt"
                        class="k-input k-textarea"
                        rows="2"
                        maxlength="1000"
                        :disabled="!current.can.edit"
                        :aria-describedby="describedBy"
                    ></textarea>
                </KField>
                <KField v-slot="{ id }" :label="__('Title')" :error="errors.title ?? ''">
                    <KInput :id="id" v-model="form.title" maxlength="191" :disabled="!current.can.edit" />
                </KField>
                <KField v-slot="{ id }" :label="__('Caption')" :help="__('Themes may show it under the file.')" :error="errors.caption ?? ''">
                    <textarea :id="id" v-model="form.caption" class="k-input k-textarea" rows="2" maxlength="2000" :disabled="!current.can.edit"></textarea>
                </KField>
            </form>

            <section class="k-media-usage" :aria-label="__('Where it is used')">
                <h3 class="k-media-usage__title">{{ __('Used in') }}</h3>
                <ul v-if="current.usage?.length">
                    <li v-for="u in current.usage" :key="`${u.id}-${u.field}`">
                        <Link :href="`/admin/content/${u.type}/${u.id}`">{{ u.title || __('(untitled)') }}</Link>
                        <span class="k-muted k-small"> · {{ STATUS[u.status] ?? u.status }}</span>
                    </li>
                </ul>
                <p v-else class="k-muted k-small">{{ __('Not used in any entry yet.') }}</p>
            </section>

            <template v-if="current.can.edit" #footer>
                <div class="k-form__actions">
                    <KButton variant="ghost" icon="trash" class="k-media-delete" @click="remove">{{ __('Delete') }}</KButton>
                    <KButton v-if="dirty" variant="ghost" @click="fill">{{ __('Cancel') }}</KButton>
                    <KButton type="submit" form="k-media-form" variant="primary" :loading="saving" :disabled="!dirty">{{ __('Save') }}</KButton>
                </div>
            </template>
        </KCard>
    </div>
</template>
