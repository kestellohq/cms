/**
 * Typing straight into the page. A block component marks the element showing
 * a field with data-k-field="<name>"; the editor says which blocks and fields
 * may be typed into: plain (one line), multi (lines) or rich text. What is
 * typed goes to the editor as the field's new value; the editor validates and
 * saves it, and the server cleans rich text again before the site shows it.
 */
import { blockCommand, FORMATS, safeField, safeId, safeLink } from '../../shared/canvas-protocol.js';

const MAX = 200_000;

/** A field's value as the editor stores it. <strike> (what browsers write for strikethrough) is saved as <s>. */
export function valueOf(el, kind) {
    const value = kind === 'rich' ? el.innerHTML.replace(/<(\/?)strike\b[^>]*>/gi, '<$1s>') : el.innerText.replace(/\n+$/, '');
    return value.slice(0, MAX);
}

/** A selection as character offsets into an element's text, so it survives the element being drawn again. */
export function offsetsOf(el, range) {
    const measure = (node, offset) => {
        const r = el.ownerDocument.createRange();
        r.selectNodeContents(el);
        r.setEnd(node, offset);
        return r.toString().length;
    };
    return { start: measure(range.startContainer, range.startOffset), end: measure(range.endContainer, range.endOffset) };
}

/** The range for character offsets into an element's text. */
export function rangeAt(el, { start, end }) {
    const doc = el.ownerDocument;
    const walker = doc.createTreeWalker(el, 4 /* text */);
    const range = doc.createRange();
    range.selectNodeContents(el);
    range.collapse(false);
    let seen = 0;
    let started = false;
    for (let node = walker.nextNode(); node; node = walker.nextNode()) {
        const len = node.textContent.length;
        // a start at a node's end goes to the start of the next one, so it lands inside the next element (<b>…)
        if (!started && (start < seen + len || (start === seen + len && start === end))) {
            range.setStart(node, start - seen);
            started = true;
        }
        if (started && end <= seen + len) {
            range.setEnd(node, end - seen);
            return range;
        }
        seen += len;
    }
    return range;
}

/** Pasted text: formatting from elsewhere stays out; a one-line field gets one line. */
export const pastedText = (text, kind) => (kind === 'plain' ? String(text).replace(/\s*\n\s*/g, ' ') : String(text));

/**
 * @param {{ send: Function, fieldEl: (id: string, field: string) => Element|null, rectOf: Function, onIdle: Function }} ctx
 */
export function createEditing({ send, fieldEl, rectOf, onIdle }, doc = document) {
    const win = doc.defaultView;
    let editable = {}; // id → { field: 'plain' | 'multi' | 'rich' }
    let editing = null; // { el, id, field, kind }
    // The editor's link field takes the focus out of the page; the text and selection it applies to are kept
    let left = null; // { id, field, offsets, at }
    const KEEP_MS = 60_000;
    const edited = new Set(); // blocks typed into since the last tree: drawn afresh
    let seq = 0; // each change typed here is numbered; a tree says up to which one it includes
    // After Enter makes a new block, the caret has nowhere to go until the editor sends it;
    // letters typed meanwhile are kept and typed into the new block once it is there.
    let waiting = null; // { keys: string, until: number }

    const report = () => {
        edited.add(editing.id);
        send({ type: 'kestello:edit', id: editing.id, field: editing.field, value: valueOf(editing.el, editing.kind), seq: ++seq });
    };

    function setEditable(map) {
        editable = map && typeof map === 'object' ? map : {};
    }

    /** Mark the fields that may be typed into (blocks that load late are marked once they are in the page). */
    function mark() {
        for (const el of doc.querySelectorAll('[data-k-editable]')) {
            if (el !== editing?.el) {
                el.removeAttribute('data-k-editable');
                el.removeAttribute('contenteditable');
            }
        }
        for (const [id, fields] of Object.entries(editable)) {
            if (!safeId(id) || !fields || typeof fields !== 'object') continue;
            for (const [field, kind] of Object.entries(fields)) {
                const el = safeField(field) ? fieldEl(id, field) : null;
                if (!el) continue;
                el.setAttribute('data-k-editable', ['rich', 'multi'].includes(kind) ? kind : 'plain');
                el.setAttribute('contenteditable', kind === 'rich' ? 'true' : 'plaintext-only');
                el.setAttribute('spellcheck', 'true');
            }
        }
    }

    /** Put the caret in a field; its block may still be loading (the first of its kind on the page), so wait for it a moment. */
    function focus(id, field, at = 'end', tries = 40) {
        const el = id && field ? fieldEl(id, field) : null;
        if (!el) {
            if (id && field && tries > 0) win.requestAnimationFrame(() => focus(id, field, at, tries - 1));
            else waiting = null;
            return;
        }
        if (!el.hasAttribute('data-k-editable')) mark();
        el.focus({ preventScroll: true });
        el.scrollIntoView({ block: 'nearest' });
        const range = doc.createRange();
        range.selectNodeContents(el);
        range.collapse(at === 'start');
        const sel = win.getSelection();
        sel?.removeAllRanges();
        sel?.addRange(range);
        if (waiting?.keys && editing?.el === el) doc.execCommand('insertText', false, waiting.keys);
        waiting = null;
    }

    const isEmpty = (el) => (el.textContent ?? '').trim() === '' && !el.querySelector('img');
    const infoOf = (el) => {
        const block = el.closest('[data-block-id]');
        const id = safeId(block?.getAttribute('data-block-id'));
        const field = safeField(el.getAttribute('data-k-field'));
        return id && field && editable[id]?.[field] ? { el, id, field, kind: el.getAttribute('data-k-editable') } : null;
    };

    // ── inline code and highlight have no editing command: wrap the selection, or unwrap the one the caret is in
    const unwrap = (node) => {
        const parent = node.parentNode;
        while (node.firstChild) parent.insertBefore(node.firstChild, node);
        parent.removeChild(node);
    };
    function toggleWrap(tag) {
        const sel = win.getSelection();
        if (!editing || !sel?.rangeCount) return;
        const at = sel.anchorNode instanceof win.Element ? sel.anchorNode : sel.anchorNode?.parentElement;
        const existing = at?.closest(tag);
        if (existing && editing.el.contains(existing)) {
            unwrap(existing);
            return;
        }
        const range = sel.getRangeAt(0);
        if (range.collapsed || !editing.el.contains(range.commonAncestorContainer)) return;
        const wrap = doc.createElement(tag);
        wrap.appendChild(range.extractContents());
        range.insertNode(wrap);
        sel.removeAllRanges();
        const r = doc.createRange();
        r.selectNodeContents(wrap);
        sel.addRange(r);
    }
    function unwrapAll(tags) {
        const sel = win.getSelection();
        if (!editing || !sel?.rangeCount) return;
        const range = sel.getRangeAt(0);
        for (const n of [...editing.el.querySelectorAll(tags.join(','))]) if (range.intersectsNode(n)) unwrap(n);
    }

    /** A format from the editor's toolbar, on the text being edited. */
    function format(command, value) {
        if (!(command in FORMATS)) return;
        // back to the text the format is for (it may have been drawn again meanwhile)
        const el = !editing && left && Date.now() - left.at < KEEP_MS ? fieldEl(left.id, left.field) : null;
        if (el) {
            el.focus({ preventScroll: true });
            if (left.offsets) {
                const sel = win.getSelection();
                sel?.removeAllRanges();
                sel?.addRange(rangeAt(el, left.offsets));
            }
        }
        if (editing?.kind !== 'rich') return;
        editing.el.focus();
        const exec = FORMATS[command];
        if (exec === 'createLink') {
            const url = safeLink(value);
            if (!url) return;
            doc.execCommand(exec, false, url);
        } else if (exec) doc.execCommand(exec);
        else toggleWrap(command);
        if (command === 'clear') unwrapAll(['code', 'mark', 'a']);
        report();
        reportFormats();
    }

    // ── which formats the caret sits in, so the toolbar can show them as on
    let lastFormats = '';
    function reportFormats() {
        if (editing?.kind !== 'rich') return;
        const at = win.getSelection()?.anchorNode;
        const el = at instanceof win.Element ? at : at?.parentElement;
        if (!el || !editing.el.contains(el)) return;
        const inside = (sel) => {
            const x = el.closest(sel);
            return !!x && editing.el.contains(x);
        };
        const state = (c) => {
            try {
                return doc.queryCommandState(c);
            } catch {
                return false;
            }
        };
        const formats = {
            bold: state('bold'),
            italic: state('italic'),
            strike: state('strikeThrough'),
            sub: state('subscript'),
            sup: state('superscript'),
            link: inside('a'),
            code: inside('code'),
            mark: inside('mark'),
        };
        const key = JSON.stringify(formats);
        if (key !== lastFormats) {
            lastFormats = key;
            send({ type: 'kestello:formats', formats });
        }
    }

    // ── events
    doc.addEventListener('focusin', (event) => {
        const el = event.target instanceof win.Element ? event.target.closest('[data-k-editable]') : null;
        editing = el ? infoOf(el) : null;
        if (!editing) return;
        doc.execCommand?.('defaultParagraphSeparator', false, 'p');
        // rich text is made of paragraphs from the first letter, so lines can be told apart
        if (editing.kind === 'rich' && el.innerHTML.trim() === '') {
            el.innerHTML = '<p><br></p>';
            const range = doc.createRange();
            range.setStart(el.firstChild, 0);
            range.collapse(true);
            win.getSelection()?.removeAllRanges();
            win.getSelection()?.addRange(range);
        }
        lastFormats = '';
        send({ type: 'kestello:editing', id: editing.id, field: editing.field, kind: editing.kind });
    });
    doc.addEventListener('focusout', (event) => {
        if (!editing || event.target !== editing.el) return;
        const sel = win.getSelection();
        const range = sel?.rangeCount ? sel.getRangeAt(0) : null;
        left = {
            id: editing.id,
            field: editing.field,
            offsets: range && editing.el.contains(range.commonAncestorContainer) ? offsetsOf(editing.el, range) : null,
            at: Date.now(),
        };
        editing = null;
        send({ type: 'kestello:editing', id: null });
        onIdle();
    });
    doc.addEventListener('input', (event) => {
        if (editing && editing.el.contains(event.target instanceof win.Node ? event.target : null)) report();
    });

    // letters typed while waiting for the new block's caret
    doc.addEventListener(
        'keydown',
        (event) => {
            if (!waiting) return;
            if (Date.now() > waiting.until) {
                waiting = null;
                return;
            }
            if (event.key.length === 1 && !event.metaKey && !event.ctrlKey && !event.altKey) {
                event.preventDefault();
                event.stopImmediatePropagation();
                waiting.keys += event.key;
            }
        },
        true,
    );

    doc.addEventListener('keydown', (event) => {
        if (!editing) return;
        const { el, id, field, kind } = editing;
        const key = (name) => send({ type: 'kestello:key', id, field, key: name, rect: rectOf(el) });
        const startNext = (name) => {
            waiting = { keys: '', until: Date.now() + 2000 };
            el.blur();
            key(name);
        };
        // in rich text an empty last line ends the block: Enter starts the next one, / picks what it is
        const emptyLast = () => {
            if (kind !== 'rich') return null;
            const node = win.getSelection()?.anchorNode;
            let line = node instanceof win.Element ? node : node?.parentElement;
            while (line && line.parentElement !== el) line = line.parentElement;
            return line && line === el.lastElementChild && line.textContent.trim() === '' && el.textContent.trim() !== '' ? line : null;
        };
        const dropLine = (line) => {
            line.remove();
            report();
        };
        const command = blockCommand(event);
        let line;
        if (event.key === 'Enter' && !event.shiftKey && kind === 'plain') {
            event.preventDefault();
            startNext('enter');
        } else if (event.key === 'Enter' && !event.shiftKey && (line = emptyLast())) {
            event.preventDefault();
            dropLine(line);
            startNext('enter');
        } else if (event.key === '/' && (line = emptyLast())) {
            event.preventDefault();
            dropLine(line);
            key('slash-after');
        } else if (event.key === 'Backspace' && isEmpty(el)) {
            event.preventDefault();
            key('remove');
        } else if (event.key === '/' && isEmpty(el) && kind === 'rich') {
            event.preventDefault();
            key('slash');
        } else if (event.key === 'Escape') {
            event.preventDefault();
            el.blur();
            key('escape');
        } else if (event.key.toLowerCase() === 'k' && (event.metaKey || event.ctrlKey) && kind === 'rich') {
            event.preventDefault();
            key('link');
        } else if (event.altKey && event.shiftKey && event.code === 'KeyX' && kind === 'rich') {
            event.preventDefault();
            toggleWrap('code');
            report();
        } else if (event.altKey && event.shiftKey && event.code === 'KeyD' && kind === 'rich') {
            event.preventDefault();
            doc.execCommand('strikeThrough');
            report();
        } else if (command) {
            event.preventDefault();
            key(command);
        }
    });

    let formatFrame = 0;
    doc.addEventListener('selectionchange', () => {
        win.cancelAnimationFrame(formatFrame);
        formatFrame = win.requestAnimationFrame(reportFormats);
    });
    doc.addEventListener('paste', (event) => {
        if (!editing) return;
        event.preventDefault();
        doc.execCommand('insertText', false, pastedText(event.clipboardData?.getData('text/plain') ?? '', editing.kind));
    });
    // nothing dropped into the text being typed (files, other pages' HTML)
    doc.addEventListener(
        'drop',
        (event) => {
            if (editing) event.preventDefault();
        },
        true,
    );

    return {
        setEditable,
        mark,
        focus,
        format,
        current: () => editing,
        /** Does a tree include everything typed here? (one that doesn't would put older text back) */
        includesTyping: (data) => typeof data.seen !== 'number' || data.seen >= seq,
        /** Blocks typed into since the last call (and forgets them). */
        takeEdited: () => {
            const out = new Set(edited);
            edited.clear();
            return out;
        },
        /** The editor answered a key that makes a new block without one (it didn't fit): stop holding letters. */
        expectingFocus: () => waiting !== null,
    };
}
