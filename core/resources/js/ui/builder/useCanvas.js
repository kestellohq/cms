/**
 * The canvas: the real site in a frame, showing the tree as it is edited. This
 * side prepares the tree (the server checks and fills in blocks), sends it, and
 * answers what the canvas reports: clicks, typing, keys, drops and handles.
 * What the messages carry: shared/canvas-protocol.js.
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref, toRaw, watch } from 'vue';
import { __ } from '@kestello/sdk';
import * as T from './tree.js';
import { editableFields, firstInline } from './blocks.js';
import { checkPage } from './checks.js';
import { prepareTree } from './prepare.js';
import { linkFromInput } from '../../shared/canvas-protocol.js';
import { toolbarSpot } from './toolbar-spot.js';

// typed text is already on the page, so the server can wait for a pause; anything else shows at once
const TYPED_MS = 600;
const CHANGED_MS = 60;

export function useCanvas(b, { api, props, emit, root }) {
    const frame = ref(null);
    const canvasEl = ref(null);
    const ready = ref(false);
    const updating = ref(false);

    // ── what is shown: the device width, and for templates the entry they are previewed with
    const device = ref('desktop');
    const DEVICES = [
        ['phone', 'device-mobile', __('Phone'), 390],
        ['tablet', 'device-tablet', __('Tablet'), 820],
        ['desktop', 'monitor', __('Desktop'), null],
    ];
    const width = computed(() => DEVICES.find((d) => d[0] === device.value)?.[3]);
    const entries = ref([]);
    const entry = ref('');
    async function loadEntries() {
        if (props.kind !== 'template') return;
        try {
            entries.value = (await api.get(`/design/templates/${props.kind}/${props.docKey}/preview-entries`)).data.entries;
            if (!entry.value && entries.value.length) entry.value = String(entries.value[0].id);
        } catch {
            entries.value = [];
        }
    }
    loadEntries();
    const entryOptions = computed(() =>
        entries.value.map((e) => ({ value: String(e.id), label: e.status === 'published' ? e.title : `${e.title} (${e.status})` })),
    );
    const canvasUrl = computed(() => {
        if (props.canvasSrc) return props.canvasSrc;
        const q = new URLSearchParams({ kind: props.kind, key: props.docKey });
        if (props.kind === 'template' && entry.value) q.set('entry', entry.value);
        return `/system/design/canvas?${q}`;
    });
    watch(canvasUrl, () => {
        ready.value = false;
    });

    const post = (message) => {
        if (ready.value) frame.value?.contentWindow?.postMessage(message, window.location.origin);
    };
    function sendMarks(scroll = false) {
        const also = b.multi?.value ? b.picked.value.filter((id) => id !== b.selected.value) : [];
        post({ type: 'kestello:mark', hover: b.hover.value, selected: b.selected.value, also, scroll });
    }
    watch(b.hover, () => sendMarks());
    watch(b.picked, () => sendMarks());

    /** What may be typed straight into the page (text fields that hold text, not data). */
    const editable = computed(() => editableFields(b.list.value, b.defOf, { disabled: props.disabled, locks: props.kind === 'entry' }));
    const plainData = () => JSON.parse(JSON.stringify(props.canvasData ?? {}));
    const plainEditable = () => JSON.parse(JSON.stringify(editable.value));

    // ── sending the tree
    let seq = 0;
    let timer = null;
    let shown = null; // the last prepared tree the canvas got
    let focusNext = null; // { id, field, at }: typing continues there once the canvas has it
    let typedTree = null; // the tree as it was after the last letter typed on the page
    let redraw = false; // something other than typing changed: draw even while the caret is in a field
    let typedSeq = 0; // the last change typed on the page that the tree holds (canvas-protocol: seq / seen)
    let shownSeen = 0;

    const scope = () => `${props.kind}:${props.docKey}:${props.resetKey}:${props.kind === 'template' ? entry.value : ''}`;
    const fetchPrepared = async (blocks) =>
        props.previewer
            ? props.previewer(blocks)
            : (await api.post('/design/preview', { blocks, entry: props.kind === 'template' && entry.value ? Number(entry.value) : null })).data.blocks;

    async function sendTree() {
        if (!ready.value) return;
        const mine = ++seq;
        const seen = typedSeq;
        updating.value = true;
        try {
            const blocks = await prepareTree(b.list.value, scope(), fetchPrepared);
            if (mine !== seq) return; // a newer change is on its way
            shown = blocks;
            shownSeen = seen;
            const focus = focusNext;
            const force = redraw;
            focusNext = null;
            redraw = false;
            post({ type: 'kestello:tree', blocks, editable: plainEditable(), focus, force, seen, ...plainData() });
            sendMarks();
            setTimeout(runChecks, 400);
        } catch {
            /* the canvas keeps the last good version */
        } finally {
            if (mine === seq) updating.value = false;
        }
    }
    watch(b.list, (now) => {
        // the page's v-model may hand the same tree back wrapped in a reactive proxy: compare what is underneath
        const typed = !!typedTree && toRaw(now) === toRaw(typedTree);
        if (!typed) redraw = true;
        clearTimeout(timer);
        timer = setTimeout(sendTree, typed && !redraw ? TYPED_MS : CHANGED_MS);
    });
    // the title and such change without the blocks: shown without asking the server
    watch(
        () => JSON.stringify(props.canvasData ?? {}),
        () => {
            if (shown) post({ type: 'kestello:tree', blocks: shown, editable: plainEditable(), seen: shownSeen, ...plainData() });
        },
    );

    /** After this change, the caret goes into the block's first text field (a new block, a block turned into another). */
    function continueTyping(node, at = 'end') {
        const field = firstInline(b.defOf(node));
        focusNext = field ? { id: node.id, field, at } : null;
    }
    const firstEditable = (id) => Object.keys(editable.value[id] ?? {})[0] ?? null;
    function focusOn(id, at = 'end') {
        const field = firstEditable(id);
        b.select(id);
        if (field) post({ type: 'kestello:focus', id, field, at });
    }

    // ── typing on the page
    const typing = ref(null); // { id, field, kind } while the caret is in a block
    const formats = ref({}); // the formats the caret sits in
    function onEdit({ id, field, value, seq: n }) {
        if (Number.isInteger(n) && n > typedSeq) typedSeq = n;
        if (typeof id !== 'string' || typeof value !== 'string' || !editable.value[id]?.[field]) return;
        if (b.selected.value !== id) b.quietSelect(id);
        if (b.setTyped(id, field, value)) typedTree = b.list.value;
        // nothing changed, yet the canvas waits for a tree that holds this change: send one anyway
        else {
            clearTimeout(timer);
            timer = setTimeout(sendTree, TYPED_MS);
        }
    }
    function onEditing(data) {
        const kind = typeof data.id === 'string' ? editable.value[data.id]?.[data.field] : null;
        typing.value = kind ? { id: data.id, field: data.field, kind } : null;
        if (!typing.value) formats.value = {};
    }
    /** A new text block after this one, the caret in it (Enter at the end of a block). */
    function newTextAfter(id, { first = false } = {}) {
        const def = b.catalog.value.get('core/text');
        const at = first ? null : T.locate(b.list.value, id);
        const parent = at?.parent ?? null;
        if (!def || !b.fitsIn(def, parent) || (!first && !at)) return false;
        const node = b.create(def);
        node.props = { ...node.props, text: '' };
        focusNext = { id: node.id, field: 'text', at: 'start' };
        b.insertAt(node, parent?.id ?? null, first ? 0 : at.index + 1, { message: !first });
        b.quietSelect(node.id);
        return true;
    }
    /** An empty entry: start with a paragraph, the caret in it. */
    function startWriting() {
        if (!newTextAfter(null, { first: true })) b.openQuick({ mode: 'after', id: null });
    }
    function onCanvasKey({ id, key, rect }) {
        if (props.disabled || typeof id !== 'string' || !T.locate(b.list.value, id)) return;
        if (key === 'enter') newTextAfter(id);
        else if (key === 'remove') {
            // Backspace in an empty block: it goes, and typing carries on at the end of the one before
            const at = b.removeNode(id, { focusRow: false });
            if (!at) return;
            const prev = at.list[at.index - 1] ?? at.parent;
            if (!prev) {
                b.select(null);
                return;
            }
            focusNext = firstEditable(prev.id) ? { id: prev.id, field: firstEditable(prev.id), at: 'end' } : null;
            b.quietSelect(prev.id);
        } else if (key === 'slash') b.openQuick({ mode: 'replace', id, rect });
        else if (key === 'slash-after') b.openQuick({ mode: 'after', id, rect });
        else if (key === 'escape') b.select(id);
        else if (key === 'link') linkOpen.value = true;
        else b.runCommand(key, id, rect);
    }

    // ── the toolbar over the selected block
    const rect = ref(null); // the selected block, in the canvas section's coordinates
    /** A box from the frame's coordinates into the canvas section's (which scrolls). */
    function toCanvas(r) {
        const f = frame.value.getBoundingClientRect();
        const c = canvasEl.value.getBoundingClientRect();
        const top = f.top - c.top + canvasEl.value.scrollTop;
        const frameLeft = f.left - c.left + canvasEl.value.scrollLeft;
        return { top: top + r.top, left: frameLeft + r.left, frameTop: top, frameHeight: f.height, frameLeft, frameWidth: f.width };
    }
    const toolbarSize = ref({ width: 360, height: 38 }); // measured by BuilderToolbar once drawn
    function onRect({ id, rect: r, room = null }) {
        if (!r || id !== b.selected.value || !frame.value || !canvasEl.value) {
            rect.value = null;
            return;
        }
        const at = toCanvas(r);
        const visible = r.top + r.height > 0 && r.top < at.frameHeight;
        rect.value = visible
            ? {
                  top: at.top,
                  left: at.left,
                  width: r.width,
                  height: r.height,
                  frame: { top: at.frameTop, left: at.frameLeft, width: at.frameWidth, height: at.frameHeight },
                  room,
              }
            : null;
    }
    // in the bar when asked (View menu), or while it is wider than the page (a phone preview),
    // where floating it could only cover the page
    const toolbarDocked = computed(() => b.docked.value || (!!rect.value && toolbarSize.value.width > rect.value.frame.width - 8));
    // where the toolbar covers the least (toolbar-spot.js), inside the page
    const toolbarStyle = computed(() => {
        const r = rect.value;
        if (!r) return null;
        const at = toolbarSpot({ block: r, frame: r.frame, size: toolbarSize.value, room: r.room });
        return { top: `${at.top}px`, left: `${at.left}px` };
    });
    /** Where a popup goes under a box the canvas reported (the quick inserter under the block typed in). */
    function under(r) {
        if (!r || !frame.value || !canvasEl.value) return null;
        const at = toCanvas(r);
        return { top: `${at.top + r.height + 6}px`, left: `${Math.max(8, at.left)}px` };
    }

    // ── formatting
    const linkOpen = ref(false);
    const linkUrl = ref('');
    watch(linkOpen, (open) => {
        if (open) {
            linkUrl.value = '';
            nextTick(() => root.value?.querySelector('.k-builder__link input')?.focus());
        }
    });
    const format = (command, value) => post({ type: 'kestello:format', command, value });
    function applyLink() {
        const url = linkFromInput(linkUrl.value);
        linkOpen.value = false;
        if (url) format('link', url);
        else if (linkUrl.value.trim()) b.announce(__("That isn't an address a link can go to."));
    }
    const richSelected = computed(() => !!b.selectedAt.value && Object.values(editable.value[b.selected.value] ?? {}).includes('rich'));
    const typingRich = computed(() => typing.value?.kind === 'rich' && typing.value.id === b.selected.value);
    watch(b.selected, () => {
        linkOpen.value = false;
    });

    // ── accessibility checks, on what the canvas actually shows
    const issues = ref([]);
    const bodyHidden = ref(false); // the template has no place for these blocks: visitors don't see them
    function runChecks() {
        issues.value = checkPage(frame.value?.contentDocument, { kind: props.kind, owns: b.owns, t: __ });
    }

    // ── messages from the canvas
    /** The first of the ids (innermost first) that is a block of this tree. */
    const own = (ids) => (Array.isArray(ids) ? ids.find((id) => b.owns(id)) : null) ?? null;
    const handlers = {
        'kestello:canvas-ready': () => {
            ready.value = true;
            sendTree();
        },
        'kestello:pick': (d) => {
            const id = own(d.ids);
            if (!id) return;
            if (d.extend === 'range' || d.extend === 'toggle') b.selectMore(id, d.extend);
            else if (id !== b.selected.value || b.multi.value) b.select(id, { scroll: true });
        },
        'kestello:hover': (d) => {
            b.hover.value = own(d.ids);
        },
        'kestello:rect': onRect,
        'kestello:edit': onEdit,
        'kestello:editing': onEditing,
        'kestello:formats': (d) => {
            if (d.formats && typeof d.formats === 'object') formats.value = { ...d.formats };
        },
        'kestello:key': onCanvasKey,
        'kestello:dragover': (d) => b.onCanvasDrag(d),
        'kestello:drop': () => b.dropHere(),
        'kestello:resize': (d) => b.setNumber(d.id, d.prop, d.value),
        'kestello:body': (d) => {
            bodyHidden.value = d.shown === false;
        },
        'kestello:title': () => emit('title'),
    };
    function onMessage(event) {
        if (event.origin !== window.location.origin || event.source !== frame.value?.contentWindow) return;
        const data = event.data;
        if (data && typeof data === 'object' && Object.hasOwn(handlers, data.type)) handlers[data.type](data);
    }
    onMounted(() => window.addEventListener('message', onMessage));
    onBeforeUnmount(() => {
        window.removeEventListener('message', onMessage);
        clearTimeout(timer);
    });

    return {
        frame,
        canvasEl,
        ready,
        updating,
        device,
        DEVICES,
        width,
        entry,
        entryOptions,
        canvasUrl,
        post,
        sendMarks,
        editable,
        continueTyping,
        focusOn,
        startWriting,
        typing,
        formats,
        rect,
        toolbarStyle,
        toolbarSize,
        toolbarDocked,
        under,
        linkOpen,
        linkUrl,
        format,
        applyLink,
        richSelected,
        typingRich,
        issues,
        runChecks,
        bodyHidden,
    };
}
