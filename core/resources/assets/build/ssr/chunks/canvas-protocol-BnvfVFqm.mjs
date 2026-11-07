// core/resources/js/shared/canvas-protocol.js
/**
* What the visual editor (admin, KBuilder) and its canvas (the site in a frame,
* app/canvas/) say to each other. Both sides import this file, so the rules for
* what a message may carry live in one place.
*
* Messages only travel between a page and a same-origin frame, and each side
* checks the origin and the window they came from before reading them.
*
* Editor → canvas
*   kestello:tree       { blocks, editable, focus?, force?, seen, title? }  show this prepared tree;
*                       seen: the last typed change (edit seq) it holds, so the canvas never draws older text
*   kestello:mark       { hover, selected, scroll }                   outline these blocks
*   kestello:format     { command, value? }                           format the selected text
*   kestello:focus      { id, field, at }                             put the caret in a field
*   kestello:drag       { active }                                    a block is being dragged
*   kestello:drop-mark  { mark }                                      where it would land
*
* Canvas → editor
*   kestello:canvas-ready                                             send the tree
*   kestello:pick       { ids }          a click: the block and those around it, innermost first
*   kestello:hover      { ids }
*   kestello:rect       { id, rect, room }  where the selected block is, and whether the toolbar
*                                        would cover content above, below or inside it (app/canvas/room.js)
*   kestello:edit       { id, field, value, seq }                     text typed into a block (seq counts up)
*   kestello:editing    { id, field?, kind? }                         the caret entered or left
*   kestello:formats    { formats }      which formats the caret sits in
*   kestello:key        { id, field, key, rect }                      a command typed on the page
*   kestello:dragover   { chain: [{ id, xr, yr, w }] }                the blocks under the pointer
*   kestello:drop
*   kestello:resize     { id, prop, value }                           a handle was dragged
*   kestello:body       { shown }        an entry: does its template show the body (false: drawn after it)
*   kestello:title                       the entry's title was clicked (it is typed in the editor's bar)
*/
/** Block ids are short lowercase hex/alphanumerics (builder/tree.js newId). */
/**
* A DOM event the canvas sends to a block when it is selected. It bubbles, so a block that
* hides some of its content (tabs) can open the part holding it:
*   el.addEventListener(REVEAL, () => { open.value = true; })
*/
var REVEAL = "kestello:reveal";
var safeId = (id) => typeof id === "string" && /^[a-z0-9]{1,16}$/.test(id) ? id : null;
/** Field names as blocks declare them. */
var safeField = (name) => typeof name === "string" && /^[a-z][a-z0-9_]{0,63}$/.test(name) ? name : null;
/** Links typed into text: web, mail and phone addresses, paths on this site and anchors. */
var LINK = /^(https?:\/\/|mailto:|tel:|\/(?!\/)|#)/i;
var safeLink = (url) => typeof url === "string" && LINK.test(url.trim()) ? url.trim() : null;
/** What someone typed as a link address: "example.com" means https://example.com. */
function linkFromInput(text) {
	const url = String(text ?? "").trim();
	if (!url) return null;
	if (LINK.test(url)) return url;
	if (/^[a-z][a-z0-9+.-]*:/i.test(url) && !/^[^:/]+:\d/.test(url)) return null;
	return `https://${url.replace(/^\/+/, "")}`;
}
/**
* Block commands that work the same on the page and in the editor around it.
* Takes a keyboard event (or anything with its key fields); returns the command or null.
*/
function blockCommand(event) {
	const mod = event.metaKey || event.ctrlKey;
	const key = String(event.key ?? "").toLowerCase();
	if (mod && !event.altKey && key === "z") return event.shiftKey ? "redo" : "undo";
	if (mod && !event.altKey && !event.shiftKey && key === "y") return "redo";
	if (mod && !event.altKey && !event.shiftKey && key === "s") return "save";
	if (mod && event.shiftKey && !event.altKey && key === "d") return "duplicate";
	if (mod && event.shiftKey && !event.altKey && event.code === "KeyF") return "find";
	if (event.altKey && event.shiftKey && !mod && event.code === "KeyZ") return "remove-block";
	if (mod && event.altKey && event.code === "KeyT") return "insert-before";
	if (mod && event.altKey && event.code === "KeyY") return "insert-after";
	if (mod && event.altKey && !event.shiftKey && event.code === "KeyC") return "copy-styles";
	if (mod && event.altKey && !event.shiftKey && event.code === "KeyV") return "paste-styles";
	return null;
}
/** Text formats the canvas can apply, and the editing command behind each (null: done by hand). */
var FORMATS = {
	bold: "bold",
	italic: "italic",
	link: "createLink",
	unlink: "unlink",
	strike: "strikeThrough",
	sub: "subscript",
	sup: "superscript",
	clear: "removeFormat",
	code: null,
	mark: null
};
export { safeField as a, linkFromInput as i, REVEAL as n, safeId as o, blockCommand as r, safeLink as s, FORMATS as t };
