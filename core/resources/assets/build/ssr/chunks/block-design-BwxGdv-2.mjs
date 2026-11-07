// core/resources/js/shared/block-design.js
/**
* Block design: the styles an editor gives one block (colour, spacing, type,
* border), per screen size. Stored on the node next to its props:
*
*   design: {
*     base:   { background: 'cream', 'padding-top': 'lg', radius: '12px' },
*     tablet: { 'padding-top': 'md' },          // tablet and phone
*     phone:  { 'font-size': 'sm' },            // phone only
*   }
*
* A value is either a preset of the theme (a lowercase name: 'lg', 'cream')
* or a custom value ('#1e3a8a', '24px', '1.5rem'). The server checks every
* value (BlockDesign.php, which mirrors PROPS below); this file turns them
* into what the page draws, the same way on the server (SSR), on the site and
* on the editor's canvas.
*
* Nothing becomes CSS text here. A block gets CSS custom properties and a
* list of what is set:
*
*   style="--kd-padding-top: var(--k-space-lg); --kd-padding-top-tablet: var(--k-space-md)"
*   data-k-d="padding-top padding-top@tablet"
*
* and shared/block-layout.css applies each one only when it is set, phone
* over tablet over the base. Inspecting a block in the browser shows exactly
* what it was given.
*/
/** Screen sizes a value can be given for, widest first. 'base' applies everywhere. */
var SCREENS = [
	"base",
	"tablet",
	"phone"
];
/**
* Every style a block can be given: its kind (what values it takes) and the
* group a block opts into (BlockDefinition::supports). Keep in step with
* BlockDesign::PROPS (a PHP test compares the two).
*/
var PROPS = {
	text: {
		kind: "color",
		group: "color"
	},
	background: {
		kind: "color",
		group: "color"
	},
	"padding-top": {
		kind: "space",
		group: "spacing"
	},
	"padding-right": {
		kind: "space",
		group: "spacing"
	},
	"padding-bottom": {
		kind: "space",
		group: "spacing"
	},
	"padding-left": {
		kind: "space",
		group: "spacing"
	},
	"margin-top": {
		kind: "space",
		group: "spacing"
	},
	"margin-bottom": {
		kind: "space",
		group: "spacing"
	},
	"font-size": {
		kind: "font",
		group: "type"
	},
	"border-width": {
		kind: "width",
		group: "border"
	},
	"border-style": {
		kind: "line",
		group: "border"
	},
	"border-color": {
		kind: "color",
		group: "border"
	},
	radius: {
		kind: "radius",
		group: "border"
	}
};
var LINES = [
	"solid",
	"dashed",
	"dotted",
	"double",
	"none"
];
/** The custom property each preset kind reads (ThemeStyles writes them; block-layout.css has defaults). */
var PRESET_VAR = {
	color: "k-color",
	space: "k-space",
	font: "k-font",
	radius: "k-radius"
};
var PRESET = /^[0-9]?[a-z][a-z0-9-]{0,30}$/;
var HEX = /^#(?:[0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i;
var LENGTH = /^(?:0|\d{1,4}(?:\.\d{1,3})?(?:px|rem|em|%|vw))$/;
var PX = /^(?:0|\d{1,2}(?:\.\d{1,2})?px)$/;
/**
* The CSS for one value, or null when it isn't one this kind takes.
* (The server refuses such values; null here keeps a stray one off the page.)
*/
function cssValue(kind, value) {
	if (typeof value !== "string" || value === "") return null;
	if (kind === "line") return LINES.includes(value) ? value : null;
	if (kind === "width") return PX.test(value) ? value : null;
	if (kind !== "color" && LENGTH.test(value)) return value;
	if (PRESET_VAR[kind] && PRESET.test(value)) return `var(--${PRESET_VAR[kind]}-${value})`;
	return kind === "color" && HEX.test(value) ? value : null;
}
/** The custom property for a style on a screen size: --kd-padding-top, --kd-padding-top-tablet. */
var varName = (prop, screen = "base") => `--kd-${prop}${screen === "base" ? "" : `-${screen}`}`;
/** The token in data-k-d for a style on a screen size: padding-top, padding-top@tablet. */
var token = (prop, screen = "base") => screen === "base" ? prop : `${prop}@${screen}`;
/**
* What a block's root element gets for its design: { style, 'data-k-d' }, or
* null when it has none. Values a block may not take are left out.
*/
function designAttrs(design) {
	if (!design || typeof design !== "object") return null;
	const style = {};
	const set = [];
	for (const screen of SCREENS) {
		const values = design[screen];
		if (!values || typeof values !== "object") continue;
		for (const [prop, value] of Object.entries(values)) {
			const spec = PROPS[prop];
			const css = spec ? cssValue(spec.kind, value) : null;
			if (css === null) continue;
			style[varName(prop, screen)] = css;
			set.push(token(prop, screen));
		}
	}
	return set.length ? {
		style,
		"data-k-d": set.join(" ")
	} : null;
}
/** The value a block shows on a screen size: its own, else the wider sizes' (phone → tablet → base). */
function effective(design, prop, screen = "base") {
	for (let i = SCREENS.indexOf(screen); i >= 0; i--) {
		const v = design?.[SCREENS[i]]?.[prop];
		if (v !== void 0 && v !== null && v !== "") return {
			value: v,
			from: SCREENS[i]
		};
	}
	return {
		value: null,
		from: null
	};
}
/**
* The design with one value set (or removed, for null or ''), on one screen
* size. Empty screen sizes and an empty design are dropped, so a block whose
* styles are all cleared stores nothing. Returns a new object.
*/
function withValue(design, screen, prop, value) {
	const next = { ...design ?? {} };
	const values = { ...next[screen] ?? {} };
	if (value === null || value === void 0 || value === "") delete values[prop];
	else values[prop] = value;
	if (Object.keys(values).length) next[screen] = values;
	else delete next[screen];
	return Object.keys(next).length ? next : null;
}
export { withValue as a, effective as i, SCREENS as n, designAttrs as r, PROPS as t };
