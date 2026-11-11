<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { Head, KImage, Link, usePage } from '@kestello/app';
import { ApiError, confirm, notify, useApi, useLeaveGuard, __ } from '@kestello/sdk';
import { KAlert, KBadge, KButton, KField, KIcon, KInput, KMediaPicker, KSelect } from '@kestello/ui';
import { ADDABLE, fromEdit, newBlock, previewDocument, sameEdit, toEdit } from '../../../components/emails/edit.js';

defineOptions({ layout: 'Admin' });

/**
 * One email, edited block by block in one of the site's languages, with a
 * preview that follows every change (light or dark, wide or phone, HTML or
 * plain text). Saving keeps the edit for that language only.
 */
const api = useApi();
const page = usePage();

const BLOCK_TYPES = {
    heading: { label: __('Heading'), icon: 'text-h' },
    paragraph: { label: __('Text'), icon: 'paragraph' },
    button: { label: __('Button'), icon: 'cursor-click' },
    image: { label: __('Image'), icon: 'image' },
    divider: { label: __('Divider'), icon: 'minus' },
    note: { label: __('Small print'), icon: 'note' },
};
// the email's own blocks, by what they do
const KEPT = {
    link: __('The link'),
    safety: __('If it wasn’t them'),
    expiry: __('How long the link works'),
    answers: __('The answers'),
};
// a plugin's named block: what kind it is, as a whole sentence for translators
const blockLabel = (block, source) =>
    block.type === 'original'
        ? (KEPT[block.name] ?? __('{kind} from the email', { kind: BLOCK_TYPES[source?.type]?.label ?? block.name }))
        : BLOCK_TYPES[block.type].label;
const id = computed(() => String(page.props.params?.id ?? ''));

const email = ref(null);
const languages = ref([]);
const locale = ref(new URL(window.location.href).searchParams.get('locale') ?? '');
const loadError = ref(null);
const draft = ref(null); // { subject, blocks: [{ _key, type, ... }] }
const saved = ref(null); // the edit as saved (or the template), to tell what changed
const errors = ref({});
const busy = ref(''); // 'save' | 'reset' | 'test'

async function load() {
    loadError.value = null;
    try {
        take((await api.get(`/emails/${id.value}`, { locale: locale.value })).data);
    } catch (e) {
        loadError.value = e;
    }
}
function take(data) {
    email.value = data;
    languages.value = data.languages;
    locale.value = data.locale;
    const start = data.edit ?? data.template;
    draft.value = start ? fromEdit(start) : null;
    saved.value = start ? toEdit(draft.value) : null;
    errors.value = {};
}
load();

const dirty = computed(() => draft.value !== null && saved.value !== null && !sameEdit(toEdit(draft.value), saved.value));
useLeaveGuard(dirty);
const edited = computed(() => email.value?.edit != null);
// fieldErrors() gives each field its first message
const err = (key) => errors.value[key] ?? '';
const locked = (name) => email.value?.locked.includes(name) ?? false;
const originals = computed(() => new Map((email.value?.original.blocks ?? []).filter((b) => b.name).map((b) => [b.name, b])));
const original = (name) => originals.value.get(name) ?? null;

// named blocks the draft left out, which can be put back
const missing = computed(() => {
    const kept = new Set((draft.value?.blocks ?? []).filter((b) => b.type === 'original').map((b) => b.name));
    return [...originals.value.values()].filter((b) => !kept.has(b.name));
});

async function switchLanguage(code) {
    if (code === locale.value) return;
    if (
        dirty.value &&
        !(await confirm({
            title: __('Leave your changes?'),
            message: __('They are not saved. Each language keeps its own edit.'),
            confirmLabel: __('Switch language'),
        }))
    )
        return;
    locale.value = code;
    const url = new URL(window.location.href);
    url.searchParams.set('locale', code);
    window.history.replaceState(window.history.state, '', url);
    await load();
}

// ── blocks ───────────────────────────────────────────────────────────
function add(type) {
    draft.value.blocks.push(newBlock(type));
}
function keep(name) {
    draft.value.blocks.push(newBlock('original', { name }));
}
function move(i, by) {
    const list = draft.value.blocks;
    const [block] = list.splice(i, 1);
    list.splice(i + by, 0, block);
}
function remove(i) {
    const [block] = draft.value.blocks.splice(i, 1);
    if (lastField?.target === block) lastField = null;
}

// "{site}" (written here: its closing braces would end a {{ }} in the template)
const token = (name) => `{${name}}`;
// the field last typed in, so a value chip goes where the cursor is
let lastField = null;
function track(event, target, key) {
    lastField = { el: event.target, target, key };
}
async function insert(name) {
    const text = token(name);
    if (!lastField?.el.isConnected) {
        notify.info(__('Click in a text field first, then pick the value to put there.'));
        return;
    }
    const { el, target, key } = lastField;
    const value = String(target[key] ?? '');
    const at = el.selectionStart ?? value.length;
    const end = el.selectionEnd ?? at;
    target[key] = value.slice(0, at) + text + value.slice(end);
    await nextTick();
    el.focus();
    el.setSelectionRange(at + text.length, at + text.length);
}

// images: chosen in the media library, shown by their thumbnail
const picking = ref(null); // the image block being chosen for
const thumbs = ref({}); // media id → the media item, or { missing: true }
function pick(block) {
    picking.value = block;
}
function picked(items) {
    const file = items[0];
    if (file && picking.value) {
        picking.value.media = file.id;
        if (!picking.value.alt) picking.value.alt = file.alt ?? '';
        thumbs.value[file.id] = file;
    }
    picking.value = null;
}
watch(
    () => (draft.value?.blocks ?? []).filter((b) => b.type === 'image' && b.media && !thumbs.value[b.media]).map((b) => b.media),
    async (ids) => {
        for (const mid of ids) {
            try {
                thumbs.value[mid] = (await api.get(`/media/${mid}`)).data;
            } catch {
                thumbs.value[mid] = { missing: true }; // gone: saving says so on the block
            }
        }
    },
    { immediate: true },
);

// ── preview ──────────────────────────────────────────────────────────
const preview = ref(null);
const previewProblem = ref('');
const scheme = ref('light');
const width = ref('wide');
const view = ref('html');
const frameHeight = ref(480);
let timer = null;
let asked = 0;

async function refresh() {
    if (!email.value) return;
    const ask = ++asked;
    const body = { locale: locale.value, ...(draft.value ? toEdit(draft.value) : {}) };
    try {
        const { data } = await api.post(`/emails/${id.value}/preview`, body);
        if (ask !== asked) return; // a newer change asked again
        preview.value = data;
        previewProblem.value = '';
    } catch (e) {
        if (ask !== asked) return;
        previewProblem.value = e instanceof ApiError && e.status === 422 ? (Object.values(e.fieldErrors())[0] ?? e.message) : e.message;
    }
}
watch(
    () => [email.value, draft.value && JSON.stringify(toEdit(draft.value))],
    () => {
        clearTimeout(timer);
        timer = setTimeout(refresh, preview.value ? 400 : 0);
    },
);
onBeforeUnmount(() => clearTimeout(timer));

const doc = computed(() => (preview.value ? previewDocument(preview.value.html, scheme.value) : ''));
// as tall as the email, measured again when the width or colours change (the iframe isn't reloaded for the width)
const frame = ref(null);
function fit() {
    const d = frame.value?.contentDocument;
    if (!d?.documentElement) return;
    frameHeight.value = 0; // let it shrink before measuring
    requestAnimationFrame(() => {
        frameHeight.value = Math.max(320, d.documentElement.scrollHeight);
    });
}
watch(width, () => nextTick(fit));

// ── save, undo, test ─────────────────────────────────────────────────
async function save() {
    busy.value = 'save';
    errors.value = {};
    try {
        const { data } = await api.put(`/emails/${id.value}`, { locale: locale.value, ...toEdit(draft.value) });
        take(data);
        notify.success(
            languages.value.length > 1
                ? __('Saved. Emails in {language} use it from now on.', { language: languageName.value })
                : __('Saved. The site uses it from now on.'),
        );
    } catch (e) {
        if (e instanceof ApiError && e.status === 422) {
            errors.value = e.fieldErrors();
            notify.error(__('Some blocks need attention.'));
        } else {
            notify.error(e);
        }
    } finally {
        busy.value = '';
    }
}

async function reset() {
    if (
        !(await confirm({
            title: __('Use the original email?'),
            message:
                languages.value.length > 1
                    ? __('Your edit in {language} is removed, and the email goes out as the site writes it. Other languages keep theirs.', {
                          language: languageName.value,
                      })
                    : __('Your edit is removed, and the email goes out as the site writes it.'),
            confirmLabel: __('Use the original'),
            danger: true,
        }))
    )
        return;
    busy.value = 'reset';
    try {
        const { data } = await api.delete(`/emails/${id.value}`, { query: { locale: locale.value } });
        take(data);
        notify.success(__('The original email is back.'));
    } catch (e) {
        notify.error(e);
    } finally {
        busy.value = '';
    }
}

async function test() {
    busy.value = 'test';
    try {
        const { data } = await api.post(`/emails/${id.value}/test`, { locale: locale.value, ...(draft.value ? toEdit(draft.value) : {}) });
        notify.success(__('Sent to {to}, with sample values. It isn’t saved.', { to: data.to }));
    } catch (e) {
        // a draft that can't be built marks its fields; a failed send says why ("to" has no field here)
        const fields = e instanceof ApiError && e.status === 422 ? e.fieldErrors() : {};
        if (fields.to) {
            notify.error(fields.to);
        } else {
            errors.value = fields;
            notify.error(e);
        }
    } finally {
        busy.value = '';
    }
}

const languageName = computed(() => languages.value.find((l) => l.code === locale.value)?.name ?? locale.value);
const languageOptions = computed(() => languages.value.map((l) => ({ value: l.code, label: l.name })));
const SCHEMES = [
    { value: 'light', label: __('Light'), icon: 'sun' },
    { value: 'dark', label: __('Dark'), icon: 'moon' },
];
const WIDTHS = [
    { value: 'wide', label: __('Wide'), icon: 'desktop' },
    { value: 'phone', label: __('Phone'), icon: 'device-mobile' },
];
const VIEWS = [
    { value: 'html', label: __('Email') },
    { value: 'text', label: __('Plain text') },
];
</script>

<template>
    <Head :title="email ? email.label : __('Email')" />
    <KAlert v-if="loadError" tone="danger">{{ loadError.message }}</KAlert>

    <form v-if="email" novalidate @submit.prevent="save">
        <div class="k-editor-head">
            <Link href="/admin/emails" class="k-link k-editor-head__back"><KIcon name="arrow-left" /> {{ __('Emails') }}</Link>
            <span v-if="languages.length > 1" class="k-me__lang">
                <label class="k-sr-only" for="k-me-lang">{{ __('Language') }}</label>
                <KSelect id="k-me-lang" :model-value="locale" :options="languageOptions" @update:model-value="switchLanguage" />
            </span>
            <span v-if="dirty" class="k-muted k-small">{{ __('Unsaved changes') }}</span>
            <KButton v-if="edited" variant="ghost" icon="arrow-counter-clockwise" :loading="busy === 'reset'" @click="reset">{{
                __('Use the original')
            }}</KButton>
            <KButton icon="paper-plane-tilt" :loading="busy === 'test'" @click="test">{{ __('Send me a test') }}</KButton>
            <KButton v-if="draft" variant="primary" type="submit" icon="check" :loading="busy === 'save'" :disabled="!dirty">{{ __('Save') }}</KButton>
        </div>

        <header class="k-me__head">
            <h1 class="k-me__title">{{ email.label }}</h1>
            <p class="k-muted">{{ email.description }}</p>
        </header>

        <div class="k-me">
            <!-- the email, block by block -->
            <section class="k-me__edit" :aria-label="__('The email')">
                <KAlert v-if="!draft" tone="info">{{
                    __('This email is written in HTML by its code, so it can be previewed and tested but not edited here.')
                }}</KAlert>

                <template v-else>
                    <KField
                        v-slot="{ id: fid, invalid }"
                        :label="__('Subject')"
                        :help="__('Empty keeps the original: {subject}', { subject: email.original.subject })"
                        :error="err('subject')"
                    >
                        <KInput :id="fid" v-model="draft.subject" maxlength="200" :invalid="invalid" @focus="(e) => track(e, draft, 'subject')" />
                    </KField>

                    <div v-if="email.placeholders.length" class="k-me__values">
                        <span class="k-small k-muted">{{ __('Values you can use:') }}</span>
                        <button
                            v-for="p in email.placeholders"
                            :key="p.name"
                            type="button"
                            class="k-me__value"
                            :title="__('Such as {sample}', { sample: p.sample })"
                            :aria-label="__('Insert {value}', { value: token(p.name) })"
                            @mousedown.prevent
                            @click="insert(p.name)"
                        >
                            {{ token(p.name) }}
                        </button>
                    </div>

                    <p v-if="err('blocks')" class="k-field__error" role="alert">{{ err('blocks') }}</p>

                    <ol class="k-me__blocks">
                        <li
                            v-for="(block, i) in draft.blocks"
                            :key="block._key"
                            class="k-me__block"
                            :class="{ 'is-locked': block.type === 'original' && locked(block.name) }"
                        >
                            <div class="k-me__bar">
                                <span class="k-me__kind"
                                    ><KIcon :name="block.type === 'original' ? 'push-pin' : BLOCK_TYPES[block.type].icon" />
                                    {{ blockLabel(block, original(block.name)) }}</span
                                >
                                <KBadge
                                    v-if="block.type === 'original' && locked(block.name)"
                                    :title="__('The email doesn’t work without it. Its words can change.')"
                                    ><KIcon name="lock-simple" /> {{ __('Needed') }}</KBadge
                                >
                                <span class="k-me__moves">
                                    <KButton size="sm" variant="ghost" icon="arrow-up" :disabled="i === 0" :aria-label="__('Move up')" @click="move(i, -1)" />
                                    <KButton
                                        size="sm"
                                        variant="ghost"
                                        icon="arrow-down"
                                        :disabled="i === draft.blocks.length - 1"
                                        :aria-label="__('Move down')"
                                        @click="move(i, 1)"
                                    />
                                    <KButton
                                        v-if="!(block.type === 'original' && locked(block.name))"
                                        size="sm"
                                        variant="ghost"
                                        icon="trash"
                                        :aria-label="__('Remove')"
                                        @click="remove(i)"
                                    />
                                </span>
                            </div>
                            <p v-if="err(`blocks.${i}.type`) || err(`blocks.${i}.name`)" class="k-field__error" role="alert">
                                {{ err(`blocks.${i}.type`) || err(`blocks.${i}.name`) }}
                            </p>

                            <KField
                                class="k-me__plain"
                                v-if="block.type === 'heading'"
                                v-slot="{ id: fid, invalid }"
                                :label="__('Heading')"
                                :error="err(`blocks.${i}.text`)"
                            >
                                <KInput :id="fid" v-model="block.text" maxlength="5000" :invalid="invalid" @focus="(e) => track(e, block, 'text')" />
                            </KField>
                            <KField
                                class="k-me__plain"
                                v-else-if="block.type === 'paragraph' || block.type === 'note'"
                                v-slot="{ id: fid, invalid }"
                                :label="block.type === 'note' ? __('Small print') : __('Text')"
                                :error="err(`blocks.${i}.text`)"
                            >
                                <textarea
                                    :id="fid"
                                    v-model="block.text"
                                    class="k-input k-me__area"
                                    rows="3"
                                    maxlength="5000"
                                    :aria-invalid="invalid ? 'true' : undefined"
                                    @focus="(e) => track(e, block, 'text')"
                                ></textarea>
                            </KField>
                            <div v-else-if="block.type === 'button'" class="k-me__pair">
                                <KField v-slot="{ id: fid, invalid }" :label="__('Button text')" :error="err(`blocks.${i}.label`)">
                                    <KInput :id="fid" v-model="block.label" maxlength="80" :invalid="invalid" @focus="(e) => track(e, block, 'label')" />
                                </KField>
                                <KField
                                    v-slot="{ id: fid, invalid }"
                                    :label="__('Goes to')"
                                    :help="__('https://… or a link value')"
                                    :error="err(`blocks.${i}.url`)"
                                >
                                    <KInput
                                        :id="fid"
                                        v-model="block.url"
                                        class="k-mono-input"
                                        spellcheck="false"
                                        :invalid="invalid"
                                        @focus="(e) => track(e, block, 'url')"
                                    />
                                </KField>
                            </div>
                            <div v-else-if="block.type === 'image'" class="k-me__image">
                                <div class="k-me__thumb">
                                    <KImage v-if="thumbs[block.media]?.url" :media="thumbs[block.media]" size="thumb" sizes="96px" />
                                    <KIcon v-else name="image" />
                                </div>
                                <div class="k-me__image-fields">
                                    <KButton size="sm" icon="image" @click="pick(block)">{{
                                        block.media ? __('Choose another image') : __('Choose an image')
                                    }}</KButton>
                                    <p v-if="thumbs[block.media]?.filename" class="k-small k-muted">{{ thumbs[block.media].filename }}</p>
                                    <p v-else-if="thumbs[block.media]?.missing" class="k-small k-muted">
                                        {{ __('This image is no longer in the media library.') }}
                                    </p>
                                    <p v-if="err(`blocks.${i}.media`)" class="k-field__error" role="alert">{{ err(`blocks.${i}.media`) }}</p>
                                    <KField
                                        v-slot="{ id: fid, invalid }"
                                        :label="__('Describe it')"
                                        :help="__('Read aloud, and shown when images are off. Empty for a decorative image.')"
                                        :error="err(`blocks.${i}.alt`)"
                                    >
                                        <KInput :id="fid" v-model="block.alt" maxlength="200" :invalid="invalid" @focus="(e) => track(e, block, 'alt')" />
                                    </KField>
                                </div>
                            </div>
                            <template v-else-if="block.type === 'original'">
                                <div class="k-me__original">
                                    <template v-if="original(block.name)?.type === 'button'">
                                        <span class="k-me__fake-button">{{ original(block.name).label }}</span>
                                        <span class="k-small k-muted">{{ __('Goes to the link the site makes for each email.') }}</span>
                                    </template>
                                    <template v-else-if="original(block.name)?.type === 'rows'">
                                        <span class="k-small k-muted">{{ __('The answers, as a list of labels and values.') }}</span>
                                    </template>
                                    <span v-else-if="original(block.name)">{{ original(block.name).text }}</span>
                                    <span v-else class="k-small k-muted">{{ __('The site adds this when it has one.') }}</span>
                                </div>
                                <KField
                                    v-if="['heading', 'paragraph', 'note'].includes(original(block.name)?.type)"
                                    v-slot="{ id: fid, invalid }"
                                    :label="__('Your words')"
                                    :help="__('Empty keeps the words above.')"
                                    :error="err(`blocks.${i}.text`)"
                                >
                                    <textarea
                                        :id="fid"
                                        v-model="block.text"
                                        class="k-input k-me__area"
                                        rows="2"
                                        maxlength="5000"
                                        :aria-invalid="invalid ? 'true' : undefined"
                                        @focus="(e) => track(e, block, 'text')"
                                    ></textarea>
                                </KField>
                                <KField
                                    v-else-if="original(block.name)?.type === 'button'"
                                    v-slot="{ id: fid, invalid }"
                                    :label="__('Your button text')"
                                    :help="__('Empty keeps the text above.')"
                                    :error="err(`blocks.${i}.label`)"
                                >
                                    <KInput :id="fid" v-model="block.label" maxlength="80" :invalid="invalid" @focus="(e) => track(e, block, 'label')" />
                                </KField>
                            </template>
                        </li>
                    </ol>

                    <div class="k-me__add">
                        <span class="k-small k-muted">{{ __('Add') }}</span>
                        <KButton v-for="type in ADDABLE" :key="type" size="sm" :icon="BLOCK_TYPES[type].icon" @click="add(type)">{{
                            BLOCK_TYPES[type].label
                        }}</KButton>
                    </div>
                    <div v-if="missing.length" class="k-me__add">
                        <span class="k-small k-muted">{{ __('Put back') }}</span>
                        <KButton v-for="b in missing" :key="b.name" size="sm" icon="arrow-u-down-left" @click="keep(b.name)">{{
                            blockLabel({ type: 'original', name: b.name }, b)
                        }}</KButton>
                    </div>
                </template>
            </section>

            <!-- the email as it arrives, with sample values -->
            <section class="k-me__preview" :aria-label="__('Preview')">
                <div class="k-me__switches">
                    <div class="k-me__seg" role="group" :aria-label="__('Show')">
                        <button v-for="v in VIEWS" :key="v.value" type="button" :aria-pressed="view === v.value" @click="view = v.value">{{ v.label }}</button>
                    </div>
                    <div v-if="view === 'html'" class="k-me__seg" role="group" :aria-label="__('Mail app')">
                        <button v-for="s in SCHEMES" :key="s.value" type="button" :aria-pressed="scheme === s.value" @click="scheme = s.value">
                            <KIcon :name="s.icon" /> {{ s.label }}
                        </button>
                    </div>
                    <div v-if="view === 'html'" class="k-me__seg" role="group" :aria-label="__('Screen')">
                        <button v-for="w in WIDTHS" :key="w.value" type="button" :aria-pressed="width === w.value" @click="width = w.value">
                            <KIcon :name="w.icon" /> {{ w.label }}
                        </button>
                    </div>
                </div>
                <p v-if="preview" class="k-me__subject">
                    <span class="k-small k-muted">{{ __('Subject') }}</span> <strong>{{ preview.subject }}</strong>
                </p>
                <p v-if="previewProblem" class="k-me__stale k-small" role="status">
                    <KIcon name="warning" /> {{ __('Not updated: {problem}', { problem: previewProblem }) }}
                </p>
                <div class="k-me__frame" :class="`is-${width}`">
                    <iframe
                        v-if="preview && view === 'html'"
                        ref="frame"
                        :srcdoc="doc"
                        :title="__('Preview of {email}', { email: email.label })"
                        :style="{ height: `${frameHeight}px` }"
                        sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"
                        @load="fit"
                    ></iframe>
                    <pre v-else-if="preview" class="k-me__text">{{ preview.text }}</pre>
                </div>
                <p class="k-small k-muted">{{ __('With sample values. Real emails use each reader’s own.') }}</p>
            </section>
        </div>
    </form>

    <KMediaPicker :open="picking !== null" :kinds="['image']" :title="__('Choose an image')" @update:open="(v) => !v && (picking = null)" @select="picked" />
</template>

<style scoped>
.k-me__head {
    max-width: 72ch;
    margin-bottom: 16px;
}
.k-me__title {
    font-size: 22px;
    margin: 0 0 4px;
}
.k-me__head p {
    margin: 0;
}
.k-me__lang {
    min-width: 160px;
}
.k-me {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    gap: 24px;
    align-items: start;
}
@media (max-width: 1100px) {
    .k-me {
        grid-template-columns: minmax(0, 1fr);
    }
}
.k-me__values {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px;
    margin: 4px 0 16px;
}
.k-me__value {
    font: 12px/1 var(--k-font-mono, ui-monospace, monospace);
    padding: 5px 7px;
    border: 1px solid var(--k-rule);
    border-radius: var(--k-radius);
    background: var(--k-sheet-2);
    color: var(--k-ink);
    cursor: pointer;
}
.k-me__value:hover,
.k-me__value:focus-visible {
    border-color: var(--k-ink);
}
.k-me__blocks {
    list-style: none;
    margin: 0;
    padding: 0;
    display: grid;
    gap: 10px;
}
.k-me__block {
    border: 1px solid var(--k-rule);
    border-radius: var(--k-radius);
    padding: 10px 12px 12px;
    background: var(--k-sheet);
}
.k-me__block.is-locked {
    border-style: dashed;
}
.k-me__bar {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 6px;
}
.k-me__kind {
    font-size: 13px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.k-me__moves {
    margin-left: auto;
    display: inline-flex;
    gap: 2px;
}
/* the block's bar already names it; the label stays for screen readers */
.k-me__plain :deep(.k-field__label) {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip-path: inset(50%);
    white-space: nowrap;
}
.k-me__area {
    width: 100%;
    resize: vertical;
    padding: 7px 10px;
    font: inherit;
}
.k-me__pair {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0 12px;
    align-items: start;
}
.k-me__pair > .k-field + .k-field {
    margin-top: 0;
}
.k-me__image {
    display: grid;
    grid-template-columns: 96px minmax(0, 1fr);
    gap: 12px;
    align-items: start;
}
.k-me__thumb {
    width: 96px;
    height: 72px;
    border-radius: var(--k-radius);
    background: var(--k-sheet-2);
    display: grid;
    place-items: center;
    overflow: hidden;
    color: var(--k-ink-2);
}
.k-me__thumb :deep(img) {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.k-me__image-fields p {
    margin: 4px 0 0;
}
.k-me__original {
    display: grid;
    gap: 4px;
    padding: 8px 10px;
    border-radius: var(--k-radius);
    background: var(--k-sheet-2);
    margin-bottom: 8px;
    white-space: pre-line;
}
.k-me__fake-button {
    justify-self: start;
    padding: 6px 12px;
    border-radius: var(--k-radius);
    background: var(--k-ink);
    color: var(--k-sheet);
    font-weight: 600;
    font-size: 13px;
}
.k-me__add {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px;
    margin-top: 12px;
}
.k-me__preview {
    position: sticky;
    top: 16px;
}
.k-me__switches {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 10px;
}
.k-me__seg {
    display: inline-flex;
    border: 1px solid var(--k-rule);
    border-radius: var(--k-radius);
    overflow: hidden;
}
.k-me__seg button {
    font: inherit;
    font-size: 13px;
    padding: 5px 10px;
    border: 0;
    background: transparent;
    color: var(--k-ink-2);
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}
.k-me__seg button + button {
    border-left: 1px solid var(--k-rule);
}
.k-me__seg button[aria-pressed='true'] {
    background: var(--k-sheet-2);
    color: var(--k-ink);
    font-weight: 600;
}
.k-me__subject {
    margin: 0 0 8px;
}
.k-me__stale {
    margin: 0 0 8px;
    color: var(--k-warn, var(--k-ink-2));
}
.k-me__frame {
    border: 1px solid var(--k-rule);
    border-radius: var(--k-radius);
    overflow: hidden;
    background: var(--k-sheet-2);
    margin: 0 auto 8px;
}
.k-me__frame.is-phone {
    max-width: 375px;
}
.k-me__frame iframe {
    display: block;
    width: 100%;
    border: 0;
}
.k-me__text {
    margin: 0;
    padding: 16px;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    font-size: 13px;
    background: var(--k-sheet);
}
</style>
