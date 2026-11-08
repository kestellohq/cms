import { a as safeField, n as REVEAL, o as safeId, r as blockCommand, s as safeLink, t as FORMATS } from "./canvas-protocol-BnvfVFqm.mjs";
import { a as neighbours, o as safeFieldPath, r as itemOf, t as cellOf } from "./field-paths-X95yVgQw.mjs";
import { nextTick } from "vue";
// core/resources/js/app/canvas/drag.js
/**
* Dragging a block (from the toolbar, the layers or the library) over the page:
* the canvas says which blocks are under the pointer and where in each; the
* editor decides where the block may go, and the overlay shows it.
*/
var DEPTH = 24;
/** The blocks under a point, innermost first, with where the point is in each (0..1 across and down). */
function chainAt(el, x, y) {
	const chain = [];
	for (let at = el; at && chain.length < DEPTH; at = at.parentElement?.closest("[data-block-id]") ?? null) {
		const id = safeId(at.getAttribute("data-block-id"));
		const r = at.getBoundingClientRect();
		if (id && r.width && r.height) chain.push({
			id,
			xr: (x - r.left) / r.width,
			yr: (y - r.top) / r.height,
			w: r.width
		});
	}
	return chain;
}
/**
* @param {{ send: Function, overlay: ReturnType<typeof import('./overlay.js').createOverlay>, blockEl: Function }} ctx
*/
function createDrag({ send, overlay, blockEl }, doc = document) {
	const win = doc.defaultView;
	let active = false;
	let frame = 0;
	doc.addEventListener("dragover", (event) => {
		if (!active) return;
		event.preventDefault();
		const x = event.clientX;
		const y = event.clientY;
		const el = event.target instanceof win.Element ? event.target.closest("[data-block-id]") : null;
		win.cancelAnimationFrame(frame);
		frame = win.requestAnimationFrame(() => send({
			type: "kestello:dragover",
			chain: chainAt(el, x, y)
		}));
	});
	doc.addEventListener("drop", (event) => {
		if (!active) return;
		event.preventDefault();
		overlay.hideDrop();
		send({ type: "kestello:drop" });
	});
	doc.addEventListener("dragleave", (event) => {
		if (active && !event.relatedTarget) overlay.hideDrop();
	});
	return {
		setActive(on) {
			active = on === true;
			if (!active) overlay.hideDrop();
		},
		/** Show where the editor says the block would land. */
		mark(target) {
			if (!active || !target || typeof target !== "object") {
				overlay.hideDrop();
				return;
			}
			const block = target.id ? blockEl(safeId(target.id)) : null;
			const list = doc.querySelector("[data-block-id]")?.parentElement;
			const r = block?.getBoundingClientRect() ?? (target.end && list ? list.getBoundingClientRect() : null);
			overlay.showDrop(target, r);
		}
	};
}
// core/resources/js/app/canvas/editing.js
/**
* Typing straight into the page. A block component marks the element showing
* a field with data-k-field="<name>"; the editor says which blocks and fields
* may be typed into: plain (one line), multi (lines) or rich text. What is
* typed goes to the editor as the field's new value; the editor validates and
* saves it, and the server cleans rich text again before the site shows it.
*/
var MAX = 2e5;
/** A field's value as the editor stores it. <strike> (what browsers write for strikethrough) is saved as <s>. */
function valueOf(el, kind) {
	return (kind === "rich" ? el.innerHTML.replace(/<(\/?)strike\b[^>]*>/gi, "<$1s>") : el.innerText.replace(/\n+$/, "")).slice(0, MAX);
}
/** A selection as character offsets into an element's text, so it survives the element being drawn again. */
function offsetsOf(el, range) {
	const measure = (node, offset) => {
		const r = el.ownerDocument.createRange();
		r.selectNodeContents(el);
		r.setEnd(node, offset);
		return r.toString().length;
	};
	return {
		start: measure(range.startContainer, range.startOffset),
		end: measure(range.endContainer, range.endOffset)
	};
}
/** The range for character offsets into an element's text. */
function rangeAt(el, { start, end }) {
	const doc = el.ownerDocument;
	const walker = doc.createTreeWalker(el, 4);
	const range = doc.createRange();
	range.selectNodeContents(el);
	range.collapse(false);
	let seen = 0;
	let started = false;
	for (let node = walker.nextNode(); node; node = walker.nextNode()) {
		const len = node.textContent.length;
		if (!started && (start < seen + len || start === seen + len && start === end)) {
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
var pastedText = (text, kind) => kind === "plain" ? String(text).replace(/\s*\n\s*/g, " ") : String(text);
/**
* @param {{ send: Function, fieldEl: (id: string, field: string) => Element|null, rectOf: Function, onIdle: Function }} ctx
*/
function createEditing({ send, fieldEl, rectOf, onIdle }, doc = document) {
	const win = doc.defaultView;
	let editable = {};
	let editing = null;
	let left = null;
	const KEEP_MS = 6e4;
	const edited = /* @__PURE__ */ new Set();
	let seq = 0;
	let waiting = null;
	const report = () => {
		edited.add(editing.id);
		send({
			type: "kestello:edit",
			id: editing.id,
			field: editing.field,
			value: valueOf(editing.el, editing.kind),
			seq: ++seq
		});
	};
	function setEditable(map) {
		editable = map && typeof map === "object" ? map : {};
	}
	/** Mark the fields that may be typed into (blocks that load late are marked once they are in the page). */
	function mark() {
		for (const el of doc.querySelectorAll("[data-k-editable]")) if (el !== editing?.el) {
			el.removeAttribute("data-k-editable");
			el.removeAttribute("contenteditable");
		}
		for (const [id, fields] of Object.entries(editable)) {
			if (!safeId(id) || !fields || typeof fields !== "object") continue;
			for (const [field, kind] of Object.entries(fields)) {
				const el = safeFieldPath(field) ? fieldEl(id, field) : null;
				if (!el) continue;
				el.setAttribute("data-k-editable", ["rich", "multi"].includes(kind) ? kind : "plain");
				el.setAttribute("contenteditable", kind === "rich" ? "true" : "plaintext-only");
				el.setAttribute("spellcheck", "true");
			}
		}
	}
	/** Put the caret in a field; its block may still be loading (the first of its kind on the page), so wait for it a moment. */
	function focus(id, field, at = "end", tries = 40) {
		const el = id && field ? fieldEl(id, field) : null;
		if (!el) {
			if (id && field && tries > 0) win.requestAnimationFrame(() => focus(id, field, at, tries - 1));
			else waiting = null;
			return;
		}
		if (!el.hasAttribute("data-k-editable")) mark();
		el.focus({ preventScroll: true });
		el.scrollIntoView({ block: "nearest" });
		const range = doc.createRange();
		range.selectNodeContents(el);
		range.collapse(at === "start");
		const sel = win.getSelection();
		sel?.removeAllRanges();
		sel?.addRange(range);
		if (waiting?.keys && editing?.el === el) doc.execCommand("insertText", false, waiting.keys);
		waiting = null;
	}
	/** Is the caret on the first (up) or last (down) line of the field? Then the arrow leaves it. */
	function onEdgeLine(el, dir) {
		const sel = win.getSelection();
		if (!sel?.rangeCount || !sel.isCollapsed) return false;
		const caret = sel.getRangeAt(0).getClientRects()[0];
		if (!caret) return true;
		const box = el.getBoundingClientRect();
		return dir === "up" ? caret.top - box.top < caret.height * .75 : box.bottom - caret.bottom < caret.height * .75;
	}
	/** Cells in a table row, for Shift+Tab from a row's first cell. */
	const widthOf = (id, table, row) => {
		let n = 0;
		while (n < 64 && fieldEl(id, `${table}.rows.${row}.${n}`)) n++;
		return n;
	};
	/** Move the caret to the first of these fields the page has; false if none. */
	function moveTo(id, paths, at) {
		const target = paths.find((p) => fieldEl(id, p));
		if (!target) return false;
		focus(id, target, at);
		return true;
	}
	const isEmpty = (el) => (el.textContent ?? "").trim() === "" && !el.querySelector("img");
	const infoOf = (el) => {
		const block = el.closest("[data-block-id]");
		const id = safeId(block?.getAttribute("data-block-id"));
		const field = safeFieldPath(el.getAttribute("data-k-field"));
		return id && field && editable[id]?.[field] ? {
			el,
			id,
			field,
			kind: el.getAttribute("data-k-editable")
		} : null;
	};
	const unwrap = (node) => {
		const parent = node.parentNode;
		while (node.firstChild) parent.insertBefore(node.firstChild, node);
		parent.removeChild(node);
	};
	function toggleWrap(tag) {
		const sel = win.getSelection();
		if (!editing || !sel?.rangeCount) return;
		const existing = (sel.anchorNode instanceof win.Element ? sel.anchorNode : sel.anchorNode?.parentElement)?.closest(tag);
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
		for (const n of [...editing.el.querySelectorAll(tags.join(","))]) if (range.intersectsNode(n)) unwrap(n);
	}
	/** A format from the editor's toolbar, on the text being edited. */
	function format(command, value) {
		if (!(command in FORMATS)) return;
		const el = !editing && left && Date.now() - left.at < KEEP_MS ? fieldEl(left.id, left.field) : null;
		if (el) {
			el.focus({ preventScroll: true });
			if (left.offsets) {
				const sel = win.getSelection();
				sel?.removeAllRanges();
				sel?.addRange(rangeAt(el, left.offsets));
			}
		}
		if (editing?.kind !== "rich") return;
		editing.el.focus();
		const exec = FORMATS[command];
		if (exec === "createLink") {
			const url = safeLink(value);
			if (!url) return;
			doc.execCommand(exec, false, url);
		} else if (exec) doc.execCommand(exec);
		else toggleWrap(command);
		if (command === "clear") unwrapAll([
			"code",
			"mark",
			"a"
		]);
		report();
		reportFormats();
	}
	let lastFormats = "";
	function reportFormats() {
		if (editing?.kind !== "rich") return;
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
			bold: state("bold"),
			italic: state("italic"),
			strike: state("strikeThrough"),
			sub: state("subscript"),
			sup: state("superscript"),
			link: inside("a"),
			code: inside("code"),
			mark: inside("mark")
		};
		const key = JSON.stringify(formats);
		if (key !== lastFormats) {
			lastFormats = key;
			send({
				type: "kestello:formats",
				formats
			});
		}
	}
	doc.addEventListener("focusin", (event) => {
		const el = event.target instanceof win.Element ? event.target.closest("[data-k-editable]") : null;
		editing = el ? infoOf(el) : null;
		if (!editing) return;
		doc.execCommand?.("defaultParagraphSeparator", false, "p");
		if (editing.kind === "rich" && el.innerHTML.trim() === "") {
			el.innerHTML = "<p><br></p>";
			const range = doc.createRange();
			range.setStart(el.firstChild, 0);
			range.collapse(true);
			win.getSelection()?.removeAllRanges();
			win.getSelection()?.addRange(range);
		}
		lastFormats = "";
		send({
			type: "kestello:editing",
			id: editing.id,
			field: editing.field,
			kind: editing.kind
		});
	});
	doc.addEventListener("focusout", (event) => {
		if (!editing || event.target !== editing.el) return;
		const sel = win.getSelection();
		const range = sel?.rangeCount ? sel.getRangeAt(0) : null;
		left = {
			id: editing.id,
			field: editing.field,
			offsets: range && editing.el.contains(range.commonAncestorContainer) ? offsetsOf(editing.el, range) : null,
			at: Date.now()
		};
		editing = null;
		send({
			type: "kestello:editing",
			id: null
		});
		onIdle();
	});
	doc.addEventListener("input", (event) => {
		if (editing && editing.el.contains(event.target instanceof win.Node ? event.target : null)) report();
	});
	doc.addEventListener("keydown", (event) => {
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
	}, true);
	doc.addEventListener("keydown", (event) => {
		if (!editing) return;
		const { el, id, field, kind } = editing;
		const key = (name) => send({
			type: "kestello:key",
			id,
			field,
			key: name,
			rect: rectOf(el)
		});
		const startNext = (name) => {
			waiting = {
				keys: "",
				until: Date.now() + 2e3
			};
			el.blur();
			key(name);
		};
		const emptyLast = () => {
			if (kind !== "rich") return null;
			const node = win.getSelection()?.anchorNode;
			let line = node instanceof win.Element ? node : node?.parentElement;
			while (line && line.parentElement !== el) line = line.parentElement;
			return line && line === el.lastElementChild && line.textContent.trim() === "" && el.textContent.trim() !== "" ? line : null;
		};
		const dropLine = (line) => {
			line.remove();
			report();
		};
		const command = blockCommand(event);
		const cell = cellOf(field);
		const plainMods = !event.metaKey && !event.ctrlKey && !event.altKey;
		const go = (dir, at) => moveTo(id, neighbours(field, dir, (row) => widthOf(id, cell?.field, row)), at);
		let line;
		if ((itemOf(field) || cell) && plainMods && !event.shiftKey && (event.key === "ArrowUp" || event.key === "ArrowDown")) {
			const dir = event.key === "ArrowUp" ? "up" : "down";
			if (onEdgeLine(el, dir) && go(dir, dir === "up" ? "end" : "start")) event.preventDefault();
			return;
		}
		if (cell && plainMods && event.key === "Tab") {
			if (go(event.shiftKey ? "prev" : "next", "end")) event.preventDefault();
			return;
		}
		if (cell && plainMods && event.key === "Enter" && !event.shiftKey) {
			event.preventDefault();
			if (!go("down", "end")) startNext("enter");
			return;
		}
		if (event.key === "Enter" && !event.shiftKey && kind === "plain") {
			event.preventDefault();
			startNext("enter");
		} else if (event.key === "Enter" && !event.shiftKey && (line = emptyLast())) {
			event.preventDefault();
			dropLine(line);
			startNext("enter");
		} else if (event.key === "/" && (line = emptyLast())) {
			event.preventDefault();
			dropLine(line);
			key("slash-after");
		} else if (event.key === "Backspace" && isEmpty(el)) {
			event.preventDefault();
			key("remove");
		} else if (event.key === "/" && isEmpty(el) && kind === "rich") {
			event.preventDefault();
			key("slash");
		} else if (event.key === "Escape") {
			event.preventDefault();
			el.blur();
			key("escape");
		} else if (event.key.toLowerCase() === "k" && (event.metaKey || event.ctrlKey) && kind === "rich") {
			event.preventDefault();
			key("link");
		} else if (event.altKey && event.shiftKey && event.code === "KeyX" && kind === "rich") {
			event.preventDefault();
			toggleWrap("code");
			report();
		} else if (event.altKey && event.shiftKey && event.code === "KeyD" && kind === "rich") {
			event.preventDefault();
			doc.execCommand("strikeThrough");
			report();
		} else if (command) {
			event.preventDefault();
			key(command);
		}
	});
	let formatFrame = 0;
	doc.addEventListener("selectionchange", () => {
		win.cancelAnimationFrame(formatFrame);
		formatFrame = win.requestAnimationFrame(reportFormats);
	});
	doc.addEventListener("paste", (event) => {
		if (!editing) return;
		event.preventDefault();
		doc.execCommand("insertText", false, pastedText(event.clipboardData?.getData("text/plain") ?? "", editing.kind));
	});
	doc.addEventListener("drop", (event) => {
		if (editing) event.preventDefault();
	}, true);
	return {
		setEditable,
		mark,
		focus,
		format,
		current: () => editing,
		/** Does a tree include everything typed here? (one that doesn't would put older text back) */
		includesTyping: (data) => typeof data.seen !== "number" || data.seen >= seq,
		/** Blocks typed into since the last call (and forgets them). */
		takeEdited: () => {
			const out = new Set(edited);
			edited.clear();
			return out;
		},
		/** The editor answered a key that makes a new block without one (it didn't fit): stop holding letters. */
		expectingFocus: () => waiting !== null
	};
}
// core/resources/js/app/canvas/overlay.js
/**
* What the canvas draws over the page: outlines for the hovered and selected
* block, and the mark showing where a dragged block would land. Nothing here
* changes the page's own elements; outlines are one adopted stylesheet and the
* drop mark is a fixed element of its own.
*/
var ACCENT = "#2563eb";
function createOverlay(doc = document) {
	const sheet = "adoptedStyleSheets" in doc ? new CSSStyleSheet() : null;
	if (sheet) doc.adoptedStyleSheets = [...doc.adoptedStyleSheets, sheet];
	const BASE = [
		"[data-block-id] { cursor: default; }",
		"[data-k-editable], [data-k-title] { cursor: text; }",
		"[data-k-editable]:focus { outline: none; }",
		"[data-k-field]:empty::before { content: attr(data-placeholder); opacity: .45; pointer-events: none; }",
		"[data-k-editable=\"rich\"]:not(:focus):has(> p:only-child > br:only-child)::before { content: attr(data-placeholder); opacity: .45; pointer-events: none; position: absolute; }",
		"[data-k-editable=\"rich\"]:has(> p:only-child > br:only-child) { position: relative; }",
		"[data-k-field]:empty { min-height: 1.4em; min-width: 2ch; }"
	];
	/** Ids are checked by the caller (safeId), so they can go into a selector as they are. */
	function outline({ hover, selected, also = [] }) {
		if (!sheet) return;
		const rules = [];
		if (hover && hover !== selected) rules.push(`[data-block-id="${hover}"] { outline: 1px dashed ${ACCENT} !important; outline-offset: 2px; }`);
		if (selected) rules.push(`[data-block-id="${selected}"] { outline: 2px solid ${ACCENT} !important; outline-offset: 2px; }`);
		if (also.length) rules.push(`${also.map((id) => `[data-block-id="${id}"]`).join(", ")} { outline: 2px solid ${ACCENT} !important; outline-offset: 2px; }`);
		sheet.replaceSync([...rules, ...BASE].join("\n"));
	}
	let mark = null;
	const markEl = () => {
		if (!mark) {
			mark = doc.createElement("div");
			mark.setAttribute("aria-hidden", "true");
			mark.style.cssText = `position:fixed;z-index:2147483645;pointer-events:none;background:${ACCENT};border-radius:2px;transition:top 60ms,left 60ms,width 60ms,height 60ms;`;
			doc.body.appendChild(mark);
		}
		return mark;
	};
	/**
	* A line before, after, left or right of the block, or a box over it (inside).
	* @param {{ where?: string, end?: boolean }} where  the editor's drop target
	* @param {DOMRect|null} r  the block's box (or, for the end, the list's)
	*/
	function showDrop(where, r) {
		const el = markEl();
		if (!r) {
			el.hidden = true;
			return;
		}
		el.hidden = false;
		const box = (top, left, width, height, fill = true) => Object.assign(el.style, {
			top: `${top}px`,
			left: `${left}px`,
			width: `${width}px`,
			height: `${height}px`,
			background: fill ? ACCENT : "rgb(37 99 235 / .08)",
			outline: fill ? "none" : `2px solid ${ACCENT}`
		});
		const at = where.end ? "after" : where.where;
		if (at === "before") box(r.top - 4, r.left, r.width, 3);
		else if (at === "after") box(r.bottom + 1, r.left, r.width, 3);
		else if (at === "left") box(r.top, r.left - 4, 3, r.height);
		else if (at === "right") box(r.top, r.right + 1, 3, r.height);
		else box(r.top, r.left, r.width, r.height, false);
	}
	const hideDrop = () => {
		if (mark) mark.hidden = true;
	};
	outline({
		hover: null,
		selected: null
	});
	return {
		outline,
		showDrop,
		hideDrop
	};
}
// core/resources/js/app/canvas/resize.js
/**
* Resizing on the page. A block marks the element that can be resized with
* data-k-resize="<prop>" (a number field from 10 to 100: a percentage of the
* column). The selected block gets a handle at that element's outer bottom
* corner; dragging it sets --k-size (a percentage) and --k-size-f (a fraction)
* on the block while the pointer moves, which the block's CSS reads, and sends
* the new value to the editor when the pointer lets go.
*/
/** The percentage of the column a drag gives: the width it started at plus the distance moved. */
function resizedPercent({ startWidth, column, dx, factor }) {
	const w = startWidth + dx * factor;
	return Math.round(Math.min(100, Math.max(10, w / Math.max(1, column) * 100)));
}
/** How a block is laid out, for measuring: floated, centred, and from which side it grows. */
function layoutOf(className, float) {
	const floated = float === "left" || float === "right";
	return {
		floated,
		fromEnd: /\bis-(end|wrap-end)\b/.test(className),
		centred: !floated && /\bis-center\b/.test(className)
	};
}
/**
* @param {{ selected: () => string|null, blockEl: (id: string) => Element|null, send: Function, onMove: Function }} ctx
*/
function createResizer({ selected, blockEl, send, onMove }, doc = document) {
	const win = doc.defaultView;
	let handle = null;
	let active = null;
	const own = (block) => (el) => el.closest("[data-block-id]") === block;
	function target() {
		const id = selected();
		const block = id ? blockEl(id) : null;
		if (!block) return null;
		const el = block.hasAttribute("data-k-resize") ? block : [...block.querySelectorAll("[data-k-resize]")].find(own(block));
		const prop = el ? safeField(el.getAttribute("data-k-resize")) : null;
		return prop ? {
			id,
			block,
			el,
			prop
		} : null;
	}
	function handleEl() {
		if (handle) return handle;
		handle = doc.createElement("div");
		handle.setAttribute("aria-hidden", "true");
		handle.dataset.kHandle = "resize";
		handle.style.cssText = "position:fixed;z-index:2147483646;width:14px;height:14px;margin:-7px 0 0 -7px;border:2px solid #fff;border-radius:50%;background:#2563eb;box-shadow:0 0 0 1px #2563eb;cursor:ew-resize;touch-action:none;";
		handle.addEventListener("pointerdown", start);
		doc.body.appendChild(handle);
		return handle;
	}
	function placeAt(t) {
		const r = t.el.getBoundingClientRect();
		const h = handleEl();
		h.hidden = false;
		h.style.top = `${r.bottom}px`;
		h.style.left = `${layoutOf(t.block.className, "none").fromEnd ? r.left : r.right}px`;
	}
	/** Called whenever the selection or the layout may have changed. */
	function place() {
		if (active) return;
		const t = target();
		if (t) placeAt(t);
		else if (handle) handle.hidden = true;
	}
	/** The width a percentage is of: the text column, without the block's own padding (or a float's gutters). */
	function columnOf(block, style, layout) {
		if (layout.floated) {
			const gutter = parseFloat(style.float === "right" ? style.marginRight : style.marginLeft) || 0;
			return (block.parentElement?.clientWidth ?? 0) - gutter * 2;
		}
		return block.clientWidth - (parseFloat(style.paddingLeft) || 0) - (parseFloat(style.paddingRight) || 0);
	}
	function start(event) {
		const t = target();
		if (!t) return;
		event.preventDefault();
		event.stopPropagation();
		const style = win.getComputedStyle(t.block);
		const layout = layoutOf(t.block.className, style.float);
		active = {
			...t,
			x: event.clientX,
			startWidth: (layout.floated ? t.block : t.el).getBoundingClientRect().width,
			column: columnOf(t.block, style, layout) || 1,
			factor: (layout.centred ? 2 : 1) * (layout.fromEnd ? -1 : 1),
			value: null
		};
		handle.setPointerCapture?.(event.pointerId);
		handle.addEventListener("pointermove", move);
		handle.addEventListener("pointerup", end, { once: true });
		handle.addEventListener("pointercancel", end, { once: true });
	}
	function move(event) {
		if (!active) return;
		const value = resizedPercent({
			startWidth: active.startWidth,
			column: active.column,
			dx: event.clientX - active.x,
			factor: active.factor
		});
		active.value = value;
		active.block.style.setProperty("--k-size", `${value}%`);
		active.block.style.setProperty("--k-size-f", String(value / 100));
		placeAt(active);
		handle.title = `${value}%`;
		onMove(active.id, active.block);
	}
	function end() {
		handle.removeEventListener("pointermove", move);
		const done = active;
		active = null;
		if (done && done.value !== null) send({
			type: "kestello:resize",
			id: done.id,
			prop: done.prop,
			value: done.value
		});
		place();
	}
	return {
		place,
		busy: () => active !== null
	};
}
// core/resources/js/app/canvas/room.js
/**
* Is there free room just above and just below the selected block for the editor's
* toolbar, or would it cover something a visitor reads? The canvas answers this (it
* has the page); ui/builder/toolbar-spot.js then decides where the toolbar goes.
*
* Pure but for hitsAt(x, y): the elements under a point, topmost first
* (document.elementsFromPoint), so it is tested with plain objects.
*/
/** Elements that show something by themselves, even without text. */
var MEDIA = /* @__PURE__ */ new Set([
	"IMG",
	"PICTURE",
	"VIDEO",
	"SVG",
	"svg",
	"IFRAME",
	"CANVAS",
	"INPUT",
	"BUTTON",
	"SELECT",
	"TEXTAREA"
]);
/** Would covering this element hide content? Backgrounds of the block's own ancestors don't count. */
function isContent(el, block) {
	if (!el || el === block || el.contains?.(block)) return false;
	if (MEDIA.has(el.tagName)) return true;
	for (const n of el.childNodes ?? []) if (n.nodeType === 3 && n.textContent.trim() !== "") return true;
	return false;
}
/**
* How much content each strip of a band holds: the band is `height` px tall from `from`,
* cut into columns `step` px wide across the page; each column is 0 (free) to 1 (covered at
* every probe), and columns off screen or outside `within` count as covered.
* @param {number} from  the band's top, viewport px
* @param {(x:number, y:number) => Element[]} hitsAt
* @param {object} block
* @param {{height:number, step:number, viewport:{width:number,height:number}, within?:[number,number]|null}} o
* @returns {number[]}
*/
function bandColumns(from, hitsAt, block, { height, step, viewport, within = null }) {
	const ys = [
		from + 4,
		from + height / 2,
		from + height - 4
	];
	const offScreen = ys.some((y) => y < 0 || y > viewport.height);
	const cols = [];
	for (let x = step / 2; x < viewport.width; x += step) {
		if (offScreen || within && (x < within[0] || x > within[1])) {
			cols.push(1);
			continue;
		}
		let hit = 0;
		for (const y of ys) for (const el of hitsAt(x, y)) {
			if (el === block || el.contains?.(block)) break;
			if (isContent(el, block)) {
				hit++;
				break;
			}
		}
		cols.push(Math.round(hit / ys.length * 100) / 100);
	}
	return cols;
}
/**
* The bands the toolbar could take: just above the block, just below it, and inside its top
* edge (a section's empty padding; only for a block tall enough to hold it), as columns of
* how much content each strip holds (bandColumns). ui/builder/toolbar-spot.js picks from them.
* @param {{top:number,left:number,width:number,height:number}} box  the block, in viewport px
* @returns {{step:number, above:number[], below:number[], inside:number[]|null}}
*/
function freeRoom(box, hitsAt, block, { height = 44, step = 20, viewport = {
	width: 1280,
	height: 800
} } = {}) {
	const o = {
		height,
		step,
		viewport
	};
	return {
		step,
		above: bandColumns(box.top - height, hitsAt, block, o),
		below: bandColumns(box.top + box.height, hitsAt, block, o),
		inside: box.height >= height * 2 ? bandColumns(box.top, hitsAt, block, {
			...o,
			within: [box.left, box.left + box.width]
		}) : null
	};
}
// core/resources/js/app/canvas/tree.js
/**
* Where a tree from the editor goes in the page being shown: a template's own
* blocks, or, for an entry, its body field (inside the template's entry-content
* block, and in the entry's data). Pure: returns new page props.
*/
/** The template's blocks with the entry-content block for this field holding the given blocks. */
function withBody(nodes, field, blocks) {
	return nodes.map((n) => {
		if (n?.type === "core/entry-content" && (n.props?.field ?? "") === field) return {
			...n,
			children: blocks
		};
		return Array.isArray(n?.children) && n.children.length ? {
			...n,
			children: withBody(n.children, field, blocks)
		} : n;
	});
}
/** Does the template show this body field anywhere (its entry-content block)? */
function hasBody(nodes, field) {
	return (nodes ?? []).some((n) => n?.type === "core/entry-content" && (n.props?.field ?? "") === field || Array.isArray(n?.children) && hasBody(n.children, field));
}
/**
* A template without a slot for the body would hide everything being edited. The canvas
* then shows the body after the template, in a slot of its own, and says so to the editor.
*/
var FALLBACK_ID = "kbodyfallback";
/**
* Blocks typed into since the last tree get a new revision, so they render afresh
* (the browser has already changed their text while they were edited).
*/
function stamp(nodes, edited, rev) {
	if (!edited.size) return nodes;
	return nodes.map((n) => ({
		...n,
		...edited.has(n.id) ? { _rev: rev } : {},
		children: Array.isArray(n.children) ? stamp(n.children, edited, rev) : n.children
	}));
}
/**
* @param {object} props   the page's props now
* @param {{ kind: string, field?: string }} design  what the canvas shows (router.page.props.design)
* @param {{ blocks: object[], title?: string }} data  the message from the editor
*/
function placeTree(props, design, data) {
	const blocks = data.blocks;
	if (design.kind !== "entry") return {
		...props,
		template: {
			...props.template ?? {},
			blocks
		}
	};
	const field = design.field;
	const next = { ...props };
	if (props.template) {
		const own = (props.template.blocks ?? []).filter((n) => n?.id !== FALLBACK_ID);
		next.template = {
			...props.template,
			blocks: hasBody(own, field) ? withBody(own, field, blocks) : [...own, {
				type: "core/entry-content",
				id: FALLBACK_ID,
				props: { field },
				children: blocks,
				component: "@core/blocks/EntryContent"
			}]
		};
	}
	if (props.entry) {
		next.entry = {
			...props.entry,
			data: {
				...props.entry.data ?? {},
				[field]: blocks
			}
		};
		if (typeof data.title === "string") next.entry.title = data.title;
	}
	if (typeof data.title === "string") next.title = data.title;
	return next;
}
// core/resources/js/app/canvas/index.js
/**
* The editors' canvas (Appearance → Templates, and the entry editor): the site,
* in a frame of the admin. The admin sends the tree being edited, already
* prepared by the server, and which block is hovered or selected; this frame
* shows it, reports clicks, and lets text be typed straight into the blocks that
* allow it. Loaded only on a canvas page (app/index.js), and it only listens to
* the same-origin window around it. Links and forms do nothing here.
*
* The parts: editing.js (typing), resize.js (handles), drag.js (dropping blocks),
* overlay.js (outlines and marks), tree.js (where a tree goes in the page).
* What the messages carry: shared/canvas-protocol.js.
*/
function startCanvas(router, win = window) {
	const doc = win.document;
	const design = router.page.props.design;
	const send = (message) => win.parent.postMessage(message, win.location.origin);
	const marks = {
		hover: null,
		selected: null,
		also: []
	};
	let pending = null;
	let rev = 0;
	let bodyShown = null;
	const blockEl = (id) => id ? doc.querySelector(`[data-block-id="${id}"]`) : null;
	const fieldEl = (id, field) => {
		const block = blockEl(id);
		if (!block) return null;
		if (block.getAttribute("data-k-field") === field) return block;
		return [...block.querySelectorAll(`[data-k-field="${field}"]`)].find((el) => el.closest("[data-block-id]") === block) ?? null;
	};
	const rectOf = (el) => {
		const r = el.getBoundingClientRect();
		return {
			top: r.top,
			left: r.left,
			width: r.width,
			height: r.height
		};
	};
	const overlay = createOverlay(doc);
	const onIdle = () => {
		if (pending && editing.includesTyping(pending)) {
			const p = pending;
			pending = null;
			show(p);
		}
	};
	const editing = createEditing({
		send,
		fieldEl,
		rectOf,
		onIdle
	}, doc);
	const resizer = createResizer({
		selected: () => marks.selected,
		blockEl,
		send,
		onMove: (id, block) => send({
			type: "kestello:rect",
			id,
			rect: rectOf(block),
			room: room.id === id ? room.value : null
		})
	}, doc);
	const drag = createDrag({
		send,
		overlay,
		blockEl
	}, doc);
	let room = {
		id: null,
		value: null
	};
	function reportRect(probe = true) {
		const el = blockEl(marks.selected);
		const rect = el ? rectOf(el) : null;
		if (rect && (probe || room.id !== marks.selected)) room = {
			id: marks.selected,
			value: freeRoom(rect, (x, y) => doc.elementsFromPoint(x, y), el, { viewport: {
				width: win.innerWidth,
				height: win.innerHeight
			} })
		};
		send({
			type: "kestello:rect",
			id: marks.selected,
			rect,
			room: rect ? room.value : null
		});
		resizer.place();
	}
	let rectAsked = false;
	let probeNext = false;
	const reportSoon = (probe = true) => {
		probeNext ||= probe !== false;
		if (rectAsked) return;
		rectAsked = true;
		win.requestAnimationFrame(() => {
			rectAsked = false;
			const p = probeNext;
			probeNext = false;
			reportRect(p);
		});
	};
	let scrollStopped = 0;
	win.addEventListener("scroll", () => {
		reportSoon(false);
		win.clearTimeout(scrollStopped);
		scrollStopped = win.setTimeout(() => reportSoon(true), 140);
	}, { passive: true });
	win.addEventListener("resize", reportSoon);
	if ("ResizeObserver" in win) new win.ResizeObserver(reportSoon).observe(doc.body);
	if ("MutationObserver" in win) {
		let asked = false;
		new win.MutationObserver(() => {
			if (asked) return;
			asked = true;
			win.requestAnimationFrame(() => {
				asked = false;
				editing.mark();
				reportRect();
			});
		}).observe(doc.body, {
			childList: true,
			subtree: true
		});
	}
	function show(data) {
		rev++;
		const blocks = stamp(data.blocks, editing.takeEdited(), rev);
		const props = router.page.props;
		if (design.kind === "entry" && props.template) {
			const shown = hasBody((props.template.blocks ?? []).filter((n) => n?.id !== FALLBACK_ID), design.field);
			if (shown !== bodyShown) {
				bodyShown = shown;
				send({
					type: "kestello:body",
					shown
				});
			}
		}
		router.page.props = placeTree(props, design, {
			...data,
			blocks
		});
		editing.setEditable(data.editable);
		nextTick(() => {
			editing.mark();
			reportRect();
			if (data.focus) editing.focus(safeId(data.focus.id), safeFieldPath(data.focus.field), data.focus.at === "start" ? "start" : "end");
		});
	}
	win.addEventListener("message", (event) => {
		if (event.origin !== win.location.origin || event.source !== win.parent) return;
		const data = event.data ?? {};
		switch (data.type) {
			case "kestello:tree": {
				if (!Array.isArray(data.blocks)) return;
				const force = data.force || pending?.force;
				if (!editing.includesTyping(data)) {
					pending = {
						...data,
						force
					};
					return;
				}
				const now = editing.current();
				if (now && !data.focus && !force) {
					pending = data;
					return;
				}
				pending = null;
				show(now && !data.focus ? {
					...data,
					focus: {
						id: now.id,
						field: now.field,
						at: "end"
					}
				} : data);
				break;
			}
			case "kestello:mark": {
				marks.hover = safeId(data.hover);
				const selected = safeId(data.selected);
				const changed = selected !== marks.selected;
				marks.selected = selected;
				marks.also = Array.isArray(data.also) ? data.also.slice(0, 500).map(safeId).filter(Boolean) : [];
				overlay.outline(marks);
				if (changed && selected) blockEl(selected)?.dispatchEvent(new CustomEvent(REVEAL, { bubbles: true }));
				if (changed && selected && data.scroll) blockEl(selected)?.scrollIntoView({
					block: "nearest",
					behavior: "smooth"
				});
				reportRect();
				break;
			}
			case "kestello:format":
				editing.format(data.command, data.value);
				break;
			case "kestello:focus":
				editing.focus(safeId(data.id), safeFieldPath(data.field), data.at === "start" ? "start" : "end");
				break;
			case "kestello:drag":
				drag.setActive(data.active);
				break;
			case "kestello:drop-mark": drag.mark(data.mark);
		}
	});
	const blockAt = (target) => target instanceof win.Element ? target.closest("[data-block-id]") : null;
	const chain = (el) => {
		const ids = [];
		for (let at = el; at; at = at.parentElement?.closest("[data-block-id]") ?? null) ids.push(at.getAttribute("data-block-id"));
		return ids;
	};
	doc.addEventListener("click", (event) => {
		const typing = event.target instanceof win.Element && event.target.closest("[data-k-editable]");
		if (event.target instanceof win.Element && event.target.closest("a, button, input[type=submit], form")) event.preventDefault();
		if (event.target instanceof win.Element && event.target.closest("[data-k-title]")) {
			send({ type: "kestello:title" });
			return;
		}
		const el = blockAt(event.target);
		if (!el) return;
		if (!typing) event.preventDefault();
		const extend = typing ? null : event.shiftKey ? "range" : event.metaKey || event.ctrlKey ? "toggle" : null;
		send({
			type: "kestello:pick",
			ids: chain(el),
			extend
		});
	}, true);
	doc.addEventListener("submit", (event) => event.preventDefault(), true);
	let lastHover = null;
	doc.addEventListener("mouseover", (event) => {
		const el = blockAt(event.target);
		const id = el?.getAttribute("data-block-id") ?? null;
		if (id !== lastHover) {
			lastHover = id;
			send({
				type: "kestello:hover",
				ids: el ? chain(el) : []
			});
		}
	});
	doc.addEventListener("mouseleave", () => {
		lastHover = null;
		send({
			type: "kestello:hover",
			ids: []
		});
	});
	send({ type: "kestello:canvas-ready" });
}
export { startCanvas };
