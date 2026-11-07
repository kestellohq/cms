<script setup>
import { onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { __ } from '@kestello/sdk';
import KIcon from './KIcon.vue';

/**
 * The core rich-text editor: paragraphs, two heading levels, bold, italic,
 * links, lists and quotes. It emits HTML; the server sanitizes it again
 * with the rich-text profile, so this editor is a convenience, not a
 * security boundary. Sites swap it with $cms->admin->replace('editor.richtext', …).
 */
const model = defineModel({ type: String, default: '' });
const props = defineProps({ id: { type: String, default: '' }, disabled: Boolean, invalid: Boolean, label: { type: String, default: '' } });

const area = ref(null);
const state = reactive({ block: 'p', bold: false, italic: false, ul: false, ol: false, link: false });
const linking = ref(false);
const href = ref('');
const linkInput = ref(null);
let last = null;
let saved = null;

function render(html) {
    if (area.value && html !== last) {
        area.value.innerHTML = html || '';
        last = html;
    }
}
watch(
    () => model.value,
    (v) => render(v ?? ''),
);
onMounted(() => {
    render(model.value ?? '');
    document.addEventListener('selectionchange', sync);
});
onBeforeUnmount(() => document.removeEventListener('selectionchange', sync));

function emit() {
    let html = area.value.innerHTML;
    if (/^(<p>(<br>)?<\/p>|<br>)?$/.test(html.trim())) html = '';
    last = html;
    model.value = html;
}

function inside() {
    const sel = document.getSelection();
    return sel && sel.rangeCount && area.value?.contains(sel.anchorNode);
}
function sync() {
    if (!inside()) return;
    state.bold = document.queryCommandState('bold');
    state.italic = document.queryCommandState('italic');
    state.ul = document.queryCommandState('insertUnorderedList');
    state.ol = document.queryCommandState('insertOrderedList');
    state.block = String(document.queryCommandValue('formatBlock') || 'p')
        .toLowerCase()
        .replace(/[<>]/g, '');
    let node = document.getSelection().anchorNode;
    state.link = false;
    while (node && node !== area.value) {
        if (node.nodeName === 'A') {
            state.link = true;
            break;
        }
        node = node.parentNode;
    }
}

function run(command, value = null) {
    area.value.focus();
    document.execCommand('styleWithCSS', false, false);
    document.execCommand(command, false, value);
    emit();
    sync();
}
const block = (tag) => run('formatBlock', state.block === tag && tag !== 'p' ? '<p>' : `<${tag}>`);

function openLink() {
    const sel = document.getSelection();
    if (!inside()) area.value.focus();
    saved = sel.rangeCount ? sel.getRangeAt(0).cloneRange() : null;
    let node = sel.anchorNode;
    while (node && node !== area.value && node.nodeName !== 'A') node = node.parentNode;
    href.value = node?.nodeName === 'A' ? node.getAttribute('href') : '';
    linking.value = true;
    setTimeout(() => linkInput.value?.focus());
}
function restore() {
    area.value.focus();
    if (saved) {
        const sel = document.getSelection();
        sel.removeAllRanges();
        sel.addRange(saved);
    }
}
const safe = (url) => /^(https?:\/\/|mailto:|tel:|\/|#)/i.test(url);
function applyLink() {
    const url = href.value.trim();
    restore();
    if (url === '') run('unlink');
    else if (safe(url)) run('createLink', url);
    else return;
    linking.value = false;
}
function cancelLink() {
    linking.value = false;
    restore();
}

function paste(e) {
    // Plain text only: pasted formatting from word processors is noise.
    e.preventDefault();
    const text = e.clipboardData.getData('text/plain');
    const esc = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    const parts = text.split(/\r?\n\s*\r?\n/).map((p) => esc(p).replace(/\r?\n/g, '<br>'));
    document.execCommand('insertHTML', false, parts.length > 1 ? parts.map((p) => `<p>${p}</p>`).join('') : parts[0]);
    emit();
}
// New lines become <p>, and typing into an empty editor starts a paragraph (not a bare text node).
function focus() {
    document.execCommand('defaultParagraphSeparator', false, 'p');
    if (!props.disabled && area.value.innerHTML.trim() === '') document.execCommand('formatBlock', false, '<p>');
}
function keydown(e) {
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        openLink();
    }
}
const TOOLS = [
    { id: 'p', icon: 'paragraph', label: __('Paragraph'), on: () => state.block === 'p' || state.block === 'div', act: () => block('p') },
    { id: 'h2', icon: 'text-h-two', label: __('Heading'), on: () => state.block === 'h2', act: () => block('h2') },
    { id: 'h3', icon: 'text-h-three', label: __('Subheading'), on: () => state.block === 'h3', act: () => block('h3') },
    'sep',
    { id: 'b', icon: 'text-b', label: __('Bold'), on: () => state.bold, act: () => run('bold') },
    { id: 'i', icon: 'text-italic', label: __('Italic'), on: () => state.italic, act: () => run('italic') },
    { id: 'a', icon: 'link', label: __('Link'), on: () => state.link, act: openLink },
    'sep',
    { id: 'ul', icon: 'list-bullets', label: __('Bulleted list'), on: () => state.ul, act: () => run('insertUnorderedList') },
    { id: 'ol', icon: 'list-numbers', label: __('Numbered list'), on: () => state.ol, act: () => run('insertOrderedList') },
    { id: 'q', icon: 'quotes', label: __('Quote'), on: () => state.block === 'blockquote', act: () => block('blockquote') },
    'sep',
    {
        id: 'x',
        icon: 'eraser',
        label: __('Clear formatting'),
        on: () => false,
        act: () => {
            run('removeFormat');
            run('formatBlock', '<p>');
        },
    },
];
</script>

<template>
    <div class="k-richtext" :class="{ 'is-disabled': disabled, 'is-invalid': invalid }">
        <div v-if="!disabled" class="k-richtext__bar" role="toolbar" :aria-label="__('Formatting')" :aria-controls="id">
            <template v-for="(tool, i) in TOOLS" :key="tool.id ?? `s${i}`">
                <span v-if="tool === 'sep'" class="k-richtext__sep" aria-hidden="true"></span>
                <button
                    v-else
                    type="button"
                    class="k-richtext__tool"
                    :aria-pressed="tool.on() ? 'true' : 'false'"
                    :title="tool.label"
                    :aria-label="tool.label"
                    @mousedown.prevent
                    @click="tool.act()"
                >
                    <KIcon :name="tool.icon" />
                </button>
            </template>
        </div>
        <form v-if="linking" class="k-richtext__link" @submit.prevent="applyLink" @keydown.esc.prevent="cancelLink">
            <input
                ref="linkInput"
                v-model="href"
                class="k-input"
                type="text"
                inputmode="url"
                spellcheck="false"
                :aria-label="__('Link address')"
                placeholder="https://"
            />
            <button type="submit" class="k-btn k-btn--primary k-btn--sm">{{ href.trim() === '' ? __('Remove link') : __('Apply') }}</button>
            <button type="button" class="k-btn k-btn--ghost k-btn--sm" @click="cancelLink">{{ __('Cancel') }}</button>
            <span v-if="href.trim() && !safe(href.trim())" class="k-field__error">{{
                __('Use a web address (https://…), mailto:, or a path starting with /.')
            }}</span>
        </form>
        <div
            :id="id"
            ref="area"
            class="k-richtext__area k-prose"
            role="textbox"
            aria-multiline="true"
            :aria-label="label || undefined"
            :aria-invalid="invalid ? 'true' : undefined"
            :contenteditable="disabled ? 'false' : 'true'"
            @input="emit"
            @paste="paste"
            @keydown="keydown"
            @focus="focus"
        ></div>
    </div>
</template>
