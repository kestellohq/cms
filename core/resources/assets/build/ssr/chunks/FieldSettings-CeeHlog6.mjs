import { t as _plugin_vue_export_helper_default } from "./_plugin-vue_export-helper-DjkACiAr.mjs";
import { computed, createTextVNode, createVNode, mergeProps, ref, toDisplayString, unref, useSSRContext, watch, withCtx } from "vue";
import { ssrIncludeBooleanAttr, ssrInterpolate, ssrRenderAttr, ssrRenderAttrs, ssrRenderClass, ssrRenderComponent, ssrRenderList } from "vue/server-renderer";
import { __ } from "@kestello/sdk";
import { KButton, KField, KIcon, KInput, KSelect, KSwitch } from "@kestello/ui";
// core/resources/js/components/content-types/ConditionsEditor.vue
/**
* "Show this field only when…" for one field. Rules look at the fields
* above it ($earlier). Edits field.conditions in place; null means always shown.
*/
var _sfc_main$2 = {
	__name: "ConditionsEditor",
	__ssrInlineRender: true,
	props: {
		field: {
			type: Object,
			required: true
		},
		earlier: {
			type: Array,
			required: true
		},
		error: {
			type: String,
			default: ""
		}
	},
	setup(__props) {
		const props = __props;
		const OPS = [
			{
				value: "is",
				label: __("is")
			},
			{
				value: "is_not",
				label: __("is not")
			},
			{
				value: "contains",
				label: __("contains")
			},
			{
				value: "filled",
				label: __("has a value")
			},
			{
				value: "empty",
				label: __("is empty")
			}
		];
		const on = computed({
			get: () => !!props.field.conditions,
			set: (v) => {
				props.field.conditions = v ? {
					match: "all",
					rules: [blank()]
				} : null;
			}
		});
		const candidates = computed(() => props.earlier.filter((f) => f.name && ![
			"group",
			"repeater",
			"blocks",
			"file"
		].includes(f.type)));
		const blank = () => ({
			field: candidates.value.at(-1)?.name ?? "",
			op: "is",
			value: ""
		});
		const target = (rule) => props.earlier.find((f) => f.name === rule.field);
		const choicesOf = (f) => {
			const c = f?.options?.choices;
			if (!c) return null;
			return (Array.isArray(c) ? c : Object.entries(c).map(([value, label]) => ({
				value,
				label
			}))).filter((x) => x.value !== "");
		};
		const opsFor = (rule) => target(rule)?.type === "boolean" ? OPS.filter((o) => ["filled", "empty"].includes(o.value)).map((o) => ({
			...o,
			label: o.value === "filled" ? __("is ticked") : __("is not ticked")
		})) : OPS;
		function retarget(rule) {
			if (target(rule)?.type === "boolean" && !["filled", "empty"].includes(rule.op)) rule.op = "filled";
			rule.value = "";
		}
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<div${ssrRenderAttrs(mergeProps({ class: "k-cond" }, _attrs))} data-v-fa7ad026>`);
			_push(ssrRenderComponent(unref(KSwitch), {
				modelValue: on.value,
				"onUpdate:modelValue": ($event) => on.value = $event,
				disabled: !candidates.value.length && !on.value,
				label: unref(__)("Show only when…")
			}, null, _parent));
			if (!candidates.value.length && !on.value) _push(`<span class="k-muted k-small" data-v-fa7ad026>${ssrInterpolate(unref(__)("Possible for fields below another field."))}</span>`);
			else _push(`<!---->`);
			if (__props.field.conditions) {
				_push(`<div class="k-cond__body" data-v-fa7ad026>`);
				if (__props.field.conditions.rules.length > 1) {
					_push(`<div class="k-cond__match" data-v-fa7ad026>`);
					_push(ssrRenderComponent(unref(KSelect), {
						modelValue: __props.field.conditions.match,
						"onUpdate:modelValue": ($event) => __props.field.conditions.match = $event,
						"aria-label": unref(__)("Which rules must hold"),
						options: [{
							value: "all",
							label: unref(__)("all of these hold")
						}, {
							value: "any",
							label: unref(__)("any of these holds")
						}]
					}, null, _parent));
					_push(`</div>`);
				} else _push(`<!---->`);
				_push(`<!--[-->`);
				ssrRenderList(__props.field.conditions.rules, (rule, i) => {
					_push(`<div class="k-cond__rule" data-v-fa7ad026>`);
					_push(ssrRenderComponent(unref(KSelect), {
						modelValue: rule.field,
						"onUpdate:modelValue": [($event) => rule.field = $event, ($event) => retarget(rule)],
						"aria-label": unref(__)("Field"),
						options: candidates.value.map((f) => ({
							value: f.name,
							label: f.label || f.name
						}))
					}, null, _parent));
					_push(ssrRenderComponent(unref(KSelect), {
						modelValue: rule.op,
						"onUpdate:modelValue": ($event) => rule.op = $event,
						"aria-label": unref(__)("Comparison"),
						options: opsFor(rule)
					}, null, _parent));
					if (!["filled", "empty"].includes(rule.op)) {
						_push(`<!--[-->`);
						if (choicesOf(target(rule))) _push(ssrRenderComponent(unref(KSelect), {
							modelValue: rule.value,
							"onUpdate:modelValue": ($event) => rule.value = $event,
							"aria-label": unref(__)("Value"),
							options: [{
								value: "",
								label: unref(__)("Choose…")
							}, ...choicesOf(target(rule)).map((c) => ({
								value: c.value,
								label: c.label || c.value
							}))]
						}, null, _parent));
						else _push(ssrRenderComponent(unref(KInput), {
							modelValue: rule.value,
							"onUpdate:modelValue": ($event) => rule.value = $event,
							"aria-label": unref(__)("Value"),
							maxlength: "200"
						}, null, _parent));
						_push(`<!--]-->`);
					} else _push(`<span data-v-fa7ad026></span>`);
					_push(ssrRenderComponent(unref(KButton), {
						size: "sm",
						variant: "ghost",
						icon: "x",
						"aria-label": unref(__)("Remove rule {n}", { n: i + 1 }),
						onClick: ($event) => {
							__props.field.conditions.rules.splice(i, 1);
							if (!__props.field.conditions.rules.length) __props.field.conditions = null;
						}
					}, null, _parent));
					_push(`</div>`);
				});
				_push(`<!--]-->`);
				if (__props.field.conditions.rules.length < 10) _push(ssrRenderComponent(unref(KButton), {
					size: "sm",
					icon: "plus",
					onClick: ($event) => __props.field.conditions.rules.push(blank())
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`${ssrInterpolate(unref(__)("Add a rule"))}`);
						else return [createTextVNode(toDisplayString(unref(__)("Add a rule")), 1)];
					}),
					_: 1
				}, _parent));
				else _push(`<!---->`);
				_push(`</div>`);
			} else _push(`<!---->`);
			if (__props.error) _push(`<p class="k-field__error" role="alert" data-v-fa7ad026>${ssrInterpolate(__props.error)}</p>`);
			else _push(`<!---->`);
			_push(`</div>`);
		};
	}
};
var _sfc_setup$2 = _sfc_main$2.setup;
_sfc_main$2.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/components/content-types/ConditionsEditor.vue");
	return _sfc_setup$2 ? _sfc_setup$2(props, ctx) : void 0;
};
var ConditionsEditor_default = /*#__PURE__*/ _plugin_vue_export_helper_default(_sfc_main$2, [["__scopeId", "data-v-fa7ad026"]]);
// core/resources/js/components/content-types/fields.js
/**
* Field definitions as the content type and form editors change them (FieldList,
* FieldSettings, the form builder). Pure: no translations, no Vue; labels are in
* the components.
*/
/** Types that hold other fields (or blocks): not offered deeper than the third level. */
var NESTING = [
	"group",
	"repeater",
	"blocks"
];
/** Types a value can be sorted and filtered by (top-level fields only). */
var INDEXABLE = [
	"text",
	"email",
	"url",
	"color",
	"number",
	"boolean",
	"date",
	"datetime",
	"select",
	"entry",
	"term",
	"user"
];
/** Types without a typed default value. */
var NO_DEFAULT = [
	"group",
	"repeater",
	"blocks",
	"media",
	"entry",
	"term",
	"user",
	"multi_select",
	"rich_text",
	"file"
];
var newId = () => Array.from(crypto.getRandomValues(/* @__PURE__ */ new Uint8Array(4)), (b) => b.toString(16).padStart(2, "0")).join("");
/** A label as a field name: "Your e-mail" → "your_e_mail"; starts with a letter, at most 64 characters. */
var machine = (label) => String(label ?? "").toLowerCase().normalize("NFKD").replace(/[̀-ͯ]/g, "").replace(/[^a-z0-9]+/g, "_").replace(/^_+|_+$/g, "").replace(/^(\d)/, "f_$1").slice(0, 64);
/** A fresh field of a type, with no name yet (the label names it). */
function blankField(type = "text") {
	return {
		id: newId(),
		name: "",
		label: "",
		type,
		help: "",
		required: false,
		indexed: false,
		internal: false,
		searchable: null,
		width: "full",
		default: null,
		options: {},
		children: [],
		conditions: null,
		_new: true
	};
}
/**
* A field after its type changed: only the options the new type understands are kept,
* and what no longer makes sense is reset. Changes the field in place.
* @param {object} allowed  the new type's options (meta.options[type])
*/
function adjustForType(f, allowed, { taxonomies = [], depth = 1 } = {}) {
	f.options = Object.fromEntries(Object.entries(f.options ?? {}).filter(([k]) => k in allowed));
	if (allowed.choices && !Array.isArray(f.options.choices)) f.options.choices = [{
		value: "",
		label: ""
	}];
	if (f.type === "term" && !f.options.taxonomy) f.options.taxonomy = taxonomies[0]?.name ?? "";
	if (!["group", "repeater"].includes(f.type)) f.children = [];
	if (!INDEXABLE.includes(f.type) || depth > 1) f.indexed = false;
	if (NO_DEFAULT.includes(f.type)) f.default = null;
	return f;
}
/** A new field's name follows its label until someone types a name of their own. */
function followLabel(f) {
	if (f._new && !f._namedByHand) f.name = machine(f.label);
	return f;
}
/** A choice's value follows its label while it was empty or still the label's. */
function setChoiceLabel(c, label) {
	if (c.value === "" || c.value === machine(c.label)) c.value = machine(label);
	c.label = label;
	return c;
}
/**
* Switch "several" on or off. A field holding several values cannot be indexed,
* and a file field that held one (the server keeps max: 1) forgets that limit,
* so the default number of files applies instead of still one.
*/
function setMultiple(f, on) {
	f.options.multiple = on;
	f.indexed = false;
	if (on && f.type === "file" && Number(f.options.max) <= 1) f.options.max = null;
	return f;
}
// core/resources/js/components/content-types/field-labels.js
/** What each field type is called in the editors. */
function fieldTypeLabel(type) {
	return {
		text: __("Text"),
		textarea: __("Long text"),
		rich_text: __("Formatted text"),
		number: __("Number"),
		boolean: __("Yes or no"),
		date: __("Date"),
		datetime: __("Date and time"),
		email: __("Email address"),
		url: __("Web address"),
		link: __("Link"),
		color: __("Color"),
		select: __("Choice"),
		multi_select: __("Several choices"),
		media: __("Media"),
		entry: __("Linked entry"),
		term: __("Term"),
		user: __("Person"),
		blocks: __("Blocks"),
		group: __("Group"),
		repeater: __("Repeating group"),
		file: __("File upload")
	}[type] ?? type;
}
// core/resources/js/components/content-types/FieldList.vue
/**
* The fields of a content type (or of a group or repeating group inside
* it): a list of rows, each opening into its settings (FieldSettings). Edits
* the array in place. Field ids stay with a field through renames, so stored
* values follow it.
*/
var _sfc_main$1 = {
	__name: "FieldList",
	__ssrInlineRender: true,
	props: {
		fields: {
			type: Array,
			required: true
		},
		meta: {
			type: Object,
			required: true
		},
		errors: {
			type: Object,
			default: () => ({})
		},
		path: {
			type: String,
			default: "fields"
		},
		depth: {
			type: Number,
			default: 1
		},
		mode: {
			type: String,
			default: "content"
		}
	},
	setup(__props) {
		const props = __props;
		const isForm = computed(() => props.mode === "form");
		const open = ref(/* @__PURE__ */ new Set());
		const hasErrors = (i) => Object.keys(props.errors).some((k) => k === `${props.path}.${i}` || k.startsWith(`${props.path}.${i}.`));
		watch(() => props.errors, () => {
			const s = new Set(open.value);
			props.fields.forEach((f, i) => {
				if (hasErrors(i)) s.add(f.id);
			});
			open.value = s;
		});
		function add() {
			const f = blankField();
			props.fields.push(f);
			open.value = /* @__PURE__ */ new Set([...open.value, f.id]);
		}
		function remove(i) {
			props.fields.splice(i, 1);
		}
		function move(i, by) {
			const j = i + by;
			if (j < 0 || j >= props.fields.length) return;
			const [f] = props.fields.splice(i, 1);
			props.fields.splice(j, 0, f);
		}
		const summary = (f) => [
			f.required && __("Required"),
			f.indexed && __("Sortable"),
			f.options?.display === "radios" && __("Radio buttons"),
			f.internal && __("Editors only"),
			f.width === "sidebar" && __("Sidebar"),
			f.conditions && __("Conditional"),
			f.options?.multiple && __("Several"),
			["group", "repeater"].includes(f.type) && (f.children.length === 1 ? __("1 field") : __("{n} fields", { n: f.children.length }))
		].filter(Boolean).join(" · ");
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<div${ssrRenderAttrs(mergeProps({ class: "k-fb" }, _attrs))} data-v-6202cd0b>`);
			if (!__props.fields.length) _push(`<p class="k-fb__empty" data-v-6202cd0b>${ssrInterpolate(isForm.value ? unref(__)("No fields yet. Add what you want people to fill in.") : __props.depth === 1 ? unref(__)("No fields yet. Every entry already has a title, an address and a publishing date; add what else it needs.") : unref(__)("Add the fields this group holds."))}</p>`);
			else {
				_push(`<ol class="k-fb__list" data-v-6202cd0b><!--[-->`);
				ssrRenderList(__props.fields, (f, i) => {
					_push(`<li class="${ssrRenderClass([{
						"is-open": open.value.has(f.id),
						"has-error": hasErrors(i)
					}, "k-fb__item"])}" data-v-6202cd0b><div class="k-fb__row" data-v-6202cd0b><button type="button" class="k-fb__summary"${ssrRenderAttr("aria-expanded", open.value.has(f.id) ? "true" : "false")} data-v-6202cd0b>`);
					_push(ssrRenderComponent(unref(KIcon), {
						name: open.value.has(f.id) ? "caret-down" : "caret-right",
						class: "k-fb__chev"
					}, null, _parent));
					_push(`<span class="k-fb__label" data-v-6202cd0b>${ssrInterpolate(f.label || f.name || unref(__)("New field"))}</span>`);
					if (f.name) _push(`<code class="k-fb__name" data-v-6202cd0b>${ssrInterpolate(f.name)}</code>`);
					else _push(`<!---->`);
					_push(`<span class="k-fb__type" data-v-6202cd0b>${ssrInterpolate(unref(fieldTypeLabel)(f.type))}</span>`);
					if (summary(f)) _push(`<span class="k-fb__flags" data-v-6202cd0b>${ssrInterpolate(summary(f))}</span>`);
					else _push(`<!---->`);
					if (hasErrors(i)) {
						_push(`<span class="k-fb__problem" data-v-6202cd0b>`);
						_push(ssrRenderComponent(unref(KIcon), { name: "warning-circle" }, null, _parent));
						_push(` ${ssrInterpolate(unref(__)("Needs attention"))}</span>`);
					} else _push(`<!---->`);
					_push(`</button><div class="k-fb__tools" data-v-6202cd0b>`);
					_push(ssrRenderComponent(unref(KButton), {
						size: "sm",
						variant: "ghost",
						icon: "arrow-up",
						disabled: i === 0,
						"aria-label": unref(__)("Move {field} up", { field: f.label || f.name }),
						onClick: ($event) => move(i, -1)
					}, null, _parent));
					_push(ssrRenderComponent(unref(KButton), {
						size: "sm",
						variant: "ghost",
						icon: "arrow-down",
						disabled: i === __props.fields.length - 1,
						"aria-label": unref(__)("Move {field} down", { field: f.label || f.name }),
						onClick: ($event) => move(i, 1)
					}, null, _parent));
					_push(ssrRenderComponent(unref(KButton), {
						size: "sm",
						variant: "ghost",
						icon: "trash",
						"aria-label": unref(__)("Remove {field}", { field: f.label || f.name }),
						onClick: ($event) => remove(i)
					}, null, _parent));
					_push(`</div></div>`);
					if (open.value.has(f.id)) {
						_push(`<div class="k-fb__panel" data-v-6202cd0b>`);
						_push(ssrRenderComponent(_sfc_main, {
							field: f,
							index: i,
							siblings: __props.fields,
							meta: __props.meta,
							errors: __props.errors,
							path: __props.path,
							depth: __props.depth,
							mode: __props.mode
						}, null, _parent));
						_push(`</div>`);
					} else _push(`<!---->`);
					_push(`</li>`);
				});
				_push(`<!--]--></ol>`);
			}
			_push(ssrRenderComponent(unref(KButton), {
				class: "k-fb__add",
				icon: "plus",
				onClick: add
			}, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(`${ssrInterpolate(__props.depth === 1 ? unref(__)("Add a field") : unref(__)("Add a field inside"))}`);
					else return [createTextVNode(toDisplayString(__props.depth === 1 ? unref(__)("Add a field") : unref(__)("Add a field inside")), 1)];
				}),
				_: 1
			}, _parent));
			_push(`</div>`);
		};
	}
};
var _sfc_setup$1 = _sfc_main$1.setup;
_sfc_main$1.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/components/content-types/FieldList.vue");
	return _sfc_setup$1 ? _sfc_setup$1(props, ctx) : void 0;
};
var FieldList_default = /*#__PURE__*/ _plugin_vue_export_helper_default(_sfc_main$1, [["__scopeId", "data-v-6202cd0b"]]);
// core/resources/js/components/content-types/FieldSettings.vue
/**
* One field's settings: label, name, type, width, help, the type's own options, whether
* it is required, and when it shows. Used inside FieldList's rows and beside the form
* builder's canvas. Edits the field in place.
*/
var _sfc_main = {
	__name: "FieldSettings",
	__ssrInlineRender: true,
	props: {
		field: {
			type: Object,
			required: true
		},
		index: {
			type: Number,
			required: true
		},
		siblings: {
			type: Array,
			required: true
		},
		meta: {
			type: Object,
			required: true
		},
		errors: {
			type: Object,
			default: () => ({})
		},
		path: {
			type: String,
			default: "fields"
		},
		depth: {
			type: Number,
			default: 1
		},
		mode: {
			type: String,
			default: "content"
		}
	},
	setup(__props) {
		const props = __props;
		const f = computed(() => props.field);
		const isForm = computed(() => props.mode === "form");
		const KINDS = [
			["image", __("Images")],
			["document", __("Documents")],
			["audio", __("Audio")],
			["video", __("Video")],
			["archive", __("Archives")]
		];
		const typeOptions = computed(() => Object.keys(props.meta.options).filter((t) => props.depth < 3 || !NESTING.includes(t)).map((t) => ({
			value: t,
			label: fieldTypeLabel(t)
		})));
		const widthOptions = computed(() => [
			{
				value: "full",
				label: __("Full width")
			},
			{
				value: "half",
				label: __("Half width")
			},
			...props.depth === 1 && !isForm.value ? [{
				value: "sidebar",
				label: __("In the sidebar")
			}] : []
		]);
		const err = (key = "") => props.errors[`${props.path}.${props.index}${key ? "." + key : ""}`] ?? "";
		const has = (key) => key in (props.meta.options[f.value.type] ?? {});
		const optionList = (key) => Array.isArray(f.value.options[key]) ? f.value.options[key] : f.value.options[key] = [];
		const typeChanged = () => adjustForType(f.value, props.meta.options[f.value.type] ?? {}, {
			taxonomies: props.meta.taxonomies ?? [],
			depth: props.depth
		});
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<div${ssrRenderAttrs(mergeProps({ class: "k-fs" }, _attrs))}>`);
			if (err()) _push(`<p class="k-field__error" role="alert">${ssrInterpolate(err())}</p>`);
			else _push(`<!---->`);
			_push(`<div class="k-fs__grid">`);
			_push(ssrRenderComponent(unref(KField), {
				label: unref(__)("Label"),
				error: err("label")
			}, {
				default: withCtx(({ id, describedBy, invalid }, _push, _parent, _scopeId) => {
					if (_push) _push(ssrRenderComponent(unref(KInput), {
						id,
						modelValue: f.value.label,
						"onUpdate:modelValue": [($event) => f.value.label = $event, ($event) => unref(followLabel)(f.value)],
						maxlength: "100",
						invalid,
						"aria-describedby": describedBy
					}, null, _parent, _scopeId));
					else return [createVNode(unref(KInput), {
						id,
						modelValue: f.value.label,
						"onUpdate:modelValue": [($event) => f.value.label = $event, ($event) => unref(followLabel)(f.value)],
						maxlength: "100",
						invalid,
						"aria-describedby": describedBy
					}, null, 8, [
						"id",
						"modelValue",
						"onUpdate:modelValue",
						"invalid",
						"aria-describedby"
					])];
				}),
				_: 1
			}, _parent));
			_push(ssrRenderComponent(unref(KField), {
				label: unref(__)("Name"),
				help: isForm.value ? unref(__)("The key each answer is stored under. Starts with a letter; lowercase letters, digits and underscores.") : f.value._new ? unref(__)("Used in templates and the API. Lowercase letters, digits and underscores.") : unref(__)("Renaming keeps the stored values."),
				error: err("name"),
				required: ""
			}, {
				default: withCtx(({ id, describedBy, invalid }, _push, _parent, _scopeId) => {
					if (_push) _push(ssrRenderComponent(unref(KInput), {
						id,
						modelValue: f.value.name,
						"onUpdate:modelValue": [($event) => f.value.name = $event, ($event) => f.value._namedByHand = true],
						class: "k-mono-input",
						maxlength: "64",
						spellcheck: "false",
						autocomplete: "off",
						invalid,
						"aria-describedby": describedBy
					}, null, _parent, _scopeId));
					else return [createVNode(unref(KInput), {
						id,
						modelValue: f.value.name,
						"onUpdate:modelValue": [($event) => f.value.name = $event, ($event) => f.value._namedByHand = true],
						class: "k-mono-input",
						maxlength: "64",
						spellcheck: "false",
						autocomplete: "off",
						invalid,
						"aria-describedby": describedBy
					}, null, 8, [
						"id",
						"modelValue",
						"onUpdate:modelValue",
						"invalid",
						"aria-describedby"
					])];
				}),
				_: 1
			}, _parent));
			_push(ssrRenderComponent(unref(KField), {
				label: unref(__)("Type"),
				error: err("type")
			}, {
				default: withCtx(({ id, describedBy, invalid }, _push, _parent, _scopeId) => {
					if (_push) _push(ssrRenderComponent(unref(KSelect), {
						id,
						modelValue: f.value.type,
						"onUpdate:modelValue": [($event) => f.value.type = $event, typeChanged],
						options: typeOptions.value,
						invalid,
						"aria-describedby": describedBy
					}, null, _parent, _scopeId));
					else return [createVNode(unref(KSelect), {
						id,
						modelValue: f.value.type,
						"onUpdate:modelValue": [($event) => f.value.type = $event, typeChanged],
						options: typeOptions.value,
						invalid,
						"aria-describedby": describedBy
					}, null, 8, [
						"id",
						"modelValue",
						"onUpdate:modelValue",
						"options",
						"invalid",
						"aria-describedby"
					])];
				}),
				_: 1
			}, _parent));
			if (f.value.type !== "group" && f.value.type !== "repeater") _push(ssrRenderComponent(unref(KField), { label: isForm.value ? unref(__)("Width") : unref(__)("Width in the editor") }, {
				default: withCtx(({ id }, _push, _parent, _scopeId) => {
					if (_push) _push(ssrRenderComponent(unref(KSelect), {
						id,
						modelValue: f.value.width,
						"onUpdate:modelValue": ($event) => f.value.width = $event,
						options: widthOptions.value
					}, null, _parent, _scopeId));
					else return [createVNode(unref(KSelect), {
						id,
						modelValue: f.value.width,
						"onUpdate:modelValue": ($event) => f.value.width = $event,
						options: widthOptions.value
					}, null, 8, [
						"id",
						"modelValue",
						"onUpdate:modelValue",
						"options"
					])];
				}),
				_: 1
			}, _parent));
			else _push(`<!---->`);
			_push(ssrRenderComponent(unref(KField), {
				class: "k-fs__wide",
				label: unref(__)("Help text"),
				help: isForm.value ? unref(__)("Shown under the field.") : unref(__)("Shown under the field in the editor.")
			}, {
				default: withCtx(({ id }, _push, _parent, _scopeId) => {
					if (_push) _push(ssrRenderComponent(unref(KInput), {
						id,
						modelValue: f.value.help,
						"onUpdate:modelValue": ($event) => f.value.help = $event,
						maxlength: "300"
					}, null, _parent, _scopeId));
					else return [createVNode(unref(KInput), {
						id,
						modelValue: f.value.help,
						"onUpdate:modelValue": ($event) => f.value.help = $event,
						maxlength: "300"
					}, null, 8, [
						"id",
						"modelValue",
						"onUpdate:modelValue"
					])];
				}),
				_: 1
			}, _parent));
			_push(`</div>`);
			if (has("choices")) {
				_push(`<div class="k-fs__choices"><div class="k-fs__subhead"><h4>${ssrInterpolate(unref(__)("Choices"))}</h4><span class="k-muted k-small">${ssrInterpolate(isForm.value ? unref(__)("The value is stored; the label is what visitors see.") : unref(__)("The value is stored; the label is what editors see."))}</span></div>`);
				if (err("options.choices")) _push(`<p class="k-field__error" role="alert">${ssrInterpolate(err("options.choices"))}</p>`);
				else _push(`<!---->`);
				_push(`<!--[-->`);
				ssrRenderList(optionList("choices"), (c, ci) => {
					_push(`<div class="k-fs__choice">`);
					_push(ssrRenderComponent(unref(KInput), {
						modelValue: c.value,
						"onUpdate:modelValue": ($event) => c.value = $event,
						class: "k-mono-input",
						"aria-label": unref(__)("Value of choice {n}", { n: ci + 1 }),
						maxlength: "100",
						spellcheck: "false"
					}, null, _parent));
					_push(ssrRenderComponent(unref(KInput), {
						"model-value": c.label,
						"aria-label": unref(__)("Label of choice {n}", { n: ci + 1 }),
						maxlength: "200",
						"onUpdate:modelValue": (v) => unref(setChoiceLabel)(c, v)
					}, null, _parent));
					_push(ssrRenderComponent(unref(KButton), {
						size: "sm",
						variant: "ghost",
						icon: "x",
						"aria-label": unref(__)("Remove choice {n}", { n: ci + 1 }),
						onClick: ($event) => f.value.options.choices.splice(ci, 1)
					}, null, _parent));
					_push(`</div>`);
				});
				_push(`<!--]-->`);
				_push(ssrRenderComponent(unref(KButton), {
					size: "sm",
					icon: "plus",
					onClick: ($event) => optionList("choices").push({
						value: "",
						label: ""
					})
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`${ssrInterpolate(unref(__)("Add a choice"))}`);
						else return [createTextVNode(toDisplayString(unref(__)("Add a choice")), 1)];
					}),
					_: 1
				}, _parent));
				_push(`</div>`);
			} else _push(`<!---->`);
			if (has("kinds")) {
				_push(`<div class="k-fs__set"><span class="k-fs__set-label">${ssrInterpolate(unref(__)("Accepts"))}</span><!--[-->`);
				ssrRenderList(KINDS, ([k, l]) => {
					_push(`<label class="k-fs__check"><input type="checkbox"${ssrIncludeBooleanAttr(optionList("kinds").includes(k)) ? " checked" : ""}> ${ssrInterpolate(l)}</label>`);
				});
				_push(`<!--]--><span class="k-muted k-small">${ssrInterpolate(unref(__)("Nothing ticked: any file."))}</span></div>`);
			} else _push(`<!---->`);
			if (has("types")) {
				_push(`<div class="k-fs__set"><span class="k-fs__set-label">${ssrInterpolate(unref(__)("Links to"))}</span><!--[-->`);
				ssrRenderList(__props.meta.types, (t) => {
					_push(`<label class="k-fs__check"><input type="checkbox"${ssrIncludeBooleanAttr(optionList("types").includes(t.name)) ? " checked" : ""}> ${ssrInterpolate(t.label)}</label>`);
				});
				_push(`<!--]--><span class="k-muted k-small">${ssrInterpolate(unref(__)("Nothing ticked: any type."))}</span></div>`);
			} else _push(`<!---->`);
			_push(`<div class="k-fs__grid">`);
			if (has("taxonomy")) _push(ssrRenderComponent(unref(KField), {
				label: unref(__)("Taxonomy"),
				error: err("options.taxonomy"),
				required: ""
			}, {
				default: withCtx(({ id, invalid }, _push, _parent, _scopeId) => {
					if (_push) _push(ssrRenderComponent(unref(KSelect), {
						id,
						modelValue: f.value.options.taxonomy,
						"onUpdate:modelValue": ($event) => f.value.options.taxonomy = $event,
						options: __props.meta.taxonomies.map((t) => ({
							value: t.name,
							label: t.label
						})),
						invalid
					}, null, _parent, _scopeId));
					else return [createVNode(unref(KSelect), {
						id,
						modelValue: f.value.options.taxonomy,
						"onUpdate:modelValue": ($event) => f.value.options.taxonomy = $event,
						options: __props.meta.taxonomies.map((t) => ({
							value: t.name,
							label: t.label
						})),
						invalid
					}, null, 8, [
						"id",
						"modelValue",
						"onUpdate:modelValue",
						"options",
						"invalid"
					])];
				}),
				_: 1
			}, _parent));
			else _push(`<!---->`);
			if (has("display")) _push(ssrRenderComponent(unref(KField), { label: unref(__)("Shown as") }, {
				default: withCtx(({ id }, _push, _parent, _scopeId) => {
					if (_push) _push(ssrRenderComponent(unref(KSelect), {
						id,
						modelValue: f.value.options.display,
						"onUpdate:modelValue": ($event) => f.value.options.display = $event,
						options: [{
							value: "dropdown",
							label: unref(__)("A dropdown")
						}, {
							value: "radios",
							label: unref(__)("Radio buttons")
						}]
					}, null, _parent, _scopeId));
					else return [createVNode(unref(KSelect), {
						id,
						modelValue: f.value.options.display,
						"onUpdate:modelValue": ($event) => f.value.options.display = $event,
						options: [{
							value: "dropdown",
							label: unref(__)("A dropdown")
						}, {
							value: "radios",
							label: unref(__)("Radio buttons")
						}]
					}, null, 8, [
						"id",
						"modelValue",
						"onUpdate:modelValue",
						"options"
					])];
				}),
				_: 1
			}, _parent));
			else _push(`<!---->`);
			if (has("max_size")) _push(ssrRenderComponent(unref(KField), {
				label: unref(__)("Largest file (MB)"),
				help: unref(__)("Empty: {size} MB. At most {max} MB.", {
					size: unref(5),
					max: __props.meta.max_upload_mb ?? 20
				}),
				error: err("options.max_size")
			}, {
				default: withCtx(({ id, invalid }, _push, _parent, _scopeId) => {
					if (_push) _push(ssrRenderComponent(unref(KInput), {
						id,
						modelValue: f.value.options.max_size,
						"onUpdate:modelValue": ($event) => f.value.options.max_size = $event,
						type: "number",
						min: "1",
						max: __props.meta.max_upload_mb ?? 20,
						invalid
					}, null, _parent, _scopeId));
					else return [createVNode(unref(KInput), {
						id,
						modelValue: f.value.options.max_size,
						"onUpdate:modelValue": ($event) => f.value.options.max_size = $event,
						type: "number",
						min: "1",
						max: __props.meta.max_upload_mb ?? 20,
						invalid
					}, null, 8, [
						"id",
						"modelValue",
						"onUpdate:modelValue",
						"max",
						"invalid"
					])];
				}),
				_: 1
			}, _parent));
			else _push(`<!---->`);
			if (has("placeholder")) _push(ssrRenderComponent(unref(KField), {
				label: unref(__)("Example shown inside the empty field"),
				help: unref(__)("Optional. Help text under the field is easier to read.")
			}, {
				default: withCtx(({ id }, _push, _parent, _scopeId) => {
					if (_push) _push(ssrRenderComponent(unref(KInput), {
						id,
						modelValue: f.value.options.placeholder,
						"onUpdate:modelValue": ($event) => f.value.options.placeholder = $event,
						maxlength: "200"
					}, null, _parent, _scopeId));
					else return [createVNode(unref(KInput), {
						id,
						modelValue: f.value.options.placeholder,
						"onUpdate:modelValue": ($event) => f.value.options.placeholder = $event,
						maxlength: "200"
					}, null, 8, [
						"id",
						"modelValue",
						"onUpdate:modelValue"
					])];
				}),
				_: 1
			}, _parent));
			else _push(`<!---->`);
			if (has("min")) _push(ssrRenderComponent(unref(KField), {
				label: f.value.type === "number" ? unref(__)("Smallest") : f.value.type === "repeater" ? unref(__)("Fewest items") : unref(__)("Fewest characters"),
				error: err("options.min")
			}, {
				default: withCtx(({ id, invalid }, _push, _parent, _scopeId) => {
					if (_push) _push(ssrRenderComponent(unref(KInput), {
						id,
						modelValue: f.value.options.min,
						"onUpdate:modelValue": ($event) => f.value.options.min = $event,
						type: "number",
						invalid
					}, null, _parent, _scopeId));
					else return [createVNode(unref(KInput), {
						id,
						modelValue: f.value.options.min,
						"onUpdate:modelValue": ($event) => f.value.options.min = $event,
						type: "number",
						invalid
					}, null, 8, [
						"id",
						"modelValue",
						"onUpdate:modelValue",
						"invalid"
					])];
				}),
				_: 1
			}, _parent));
			else _push(`<!---->`);
			if (has("max") && (f.value.type !== "file" || f.value.options.multiple)) _push(ssrRenderComponent(unref(KField), {
				help: f.value.type === "file" ? unref(__)("Empty: {count}. At most {max}.", {
					count: unref(5),
					max: unref(10)
				}) : "",
				label: f.value.type === "number" ? unref(__)("Largest") : f.value.type === "file" ? unref(__)("Most files") : [
					"repeater",
					"media",
					"entry",
					"term",
					"user"
				].includes(f.value.type) ? unref(__)("Most items") : unref(__)("Most characters"),
				error: err("options.max")
			}, {
				default: withCtx(({ id, invalid }, _push, _parent, _scopeId) => {
					if (_push) _push(ssrRenderComponent(unref(KInput), {
						id,
						modelValue: f.value.options.max,
						"onUpdate:modelValue": ($event) => f.value.options.max = $event,
						type: "number",
						invalid
					}, null, _parent, _scopeId));
					else return [createVNode(unref(KInput), {
						id,
						modelValue: f.value.options.max,
						"onUpdate:modelValue": ($event) => f.value.options.max = $event,
						type: "number",
						invalid
					}, null, 8, [
						"id",
						"modelValue",
						"onUpdate:modelValue",
						"invalid"
					])];
				}),
				_: 1
			}, _parent));
			else _push(`<!---->`);
			if (has("step")) _push(ssrRenderComponent(unref(KField), { label: unref(__)("Step") }, {
				default: withCtx(({ id }, _push, _parent, _scopeId) => {
					if (_push) _push(ssrRenderComponent(unref(KInput), {
						id,
						modelValue: f.value.options.step,
						"onUpdate:modelValue": ($event) => f.value.options.step = $event,
						type: "number",
						step: "any"
					}, null, _parent, _scopeId));
					else return [createVNode(unref(KInput), {
						id,
						modelValue: f.value.options.step,
						"onUpdate:modelValue": ($event) => f.value.options.step = $event,
						type: "number",
						step: "any"
					}, null, 8, [
						"id",
						"modelValue",
						"onUpdate:modelValue"
					])];
				}),
				_: 1
			}, _parent));
			else _push(`<!---->`);
			if (has("rows")) _push(ssrRenderComponent(unref(KField), { label: unref(__)("Rows") }, {
				default: withCtx(({ id }, _push, _parent, _scopeId) => {
					if (_push) _push(ssrRenderComponent(unref(KInput), {
						id,
						modelValue: f.value.options.rows,
						"onUpdate:modelValue": ($event) => f.value.options.rows = $event,
						type: "number",
						min: "2",
						max: "40"
					}, null, _parent, _scopeId));
					else return [createVNode(unref(KInput), {
						id,
						modelValue: f.value.options.rows,
						"onUpdate:modelValue": ($event) => f.value.options.rows = $event,
						type: "number",
						min: "2",
						max: "40"
					}, null, 8, [
						"id",
						"modelValue",
						"onUpdate:modelValue"
					])];
				}),
				_: 1
			}, _parent));
			else _push(`<!---->`);
			if (!unref(NO_DEFAULT).includes(f.value.type) && f.value.type !== "boolean") _push(ssrRenderComponent(unref(KField), {
				label: unref(__)("Default value"),
				help: has("choices") ? unref(__)("One of the values above.") : ""
			}, {
				default: withCtx(({ id }, _push, _parent, _scopeId) => {
					if (_push) _push(ssrRenderComponent(unref(KInput), {
						id,
						modelValue: f.value.default,
						"onUpdate:modelValue": ($event) => f.value.default = $event,
						maxlength: "1000"
					}, null, _parent, _scopeId));
					else return [createVNode(unref(KInput), {
						id,
						modelValue: f.value.default,
						"onUpdate:modelValue": ($event) => f.value.default = $event,
						maxlength: "1000"
					}, null, 8, [
						"id",
						"modelValue",
						"onUpdate:modelValue"
					])];
				}),
				_: 1
			}, _parent));
			else _push(`<!---->`);
			_push(`</div><div class="k-fs__switches">`);
			_push(ssrRenderComponent(unref(KSwitch), {
				modelValue: f.value.required,
				"onUpdate:modelValue": ($event) => f.value.required = $event,
				label: isForm.value ? unref(__)("Required") : unref(__)("Required to publish")
			}, null, _parent));
			if (has("multiple")) _push(ssrRenderComponent(unref(KSwitch), {
				"model-value": !!f.value.options.multiple,
				label: f.value.type === "file" ? unref(__)("Allow several files") : unref(__)("Allow several"),
				"onUpdate:modelValue": (on) => unref(setMultiple)(f.value, on)
			}, null, _parent));
			else _push(`<!---->`);
			if (has("integer")) _push(ssrRenderComponent(unref(KSwitch), {
				modelValue: f.value.options.integer,
				"onUpdate:modelValue": ($event) => f.value.options.integer = $event,
				label: unref(__)("Whole numbers only")
			}, null, _parent));
			else _push(`<!---->`);
			if (f.value.type === "boolean") _push(ssrRenderComponent(unref(KSwitch), {
				"model-value": f.value.default === true,
				label: unref(__)("On by default"),
				"onUpdate:modelValue": (v) => f.value.default = v ? true : null
			}, null, _parent));
			else _push(`<!---->`);
			if (!isForm.value && __props.depth === 1 && unref(INDEXABLE).includes(f.value.type) && !f.value.options?.multiple) _push(ssrRenderComponent(unref(KSwitch), {
				modelValue: f.value.indexed,
				"onUpdate:modelValue": ($event) => f.value.indexed = $event,
				label: unref(__)("Use for sorting and filtering")
			}, null, _parent));
			else _push(`<!---->`);
			if (!isForm.value) _push(ssrRenderComponent(unref(KSwitch), {
				modelValue: f.value.internal,
				"onUpdate:modelValue": ($event) => f.value.internal = $event,
				label: unref(__)("Editors only (never shown publicly)")
			}, null, _parent));
			else _push(`<!---->`);
			_push(`</div>`);
			if (__props.depth === 1) _push(ssrRenderComponent(ConditionsEditor_default, {
				field: f.value,
				earlier: __props.siblings.slice(0, __props.index),
				error: err("conditions")
			}, null, _parent));
			else _push(`<!---->`);
			if (f.value.type === "group" || f.value.type === "repeater") {
				_push(`<div class="k-fs__children"><div class="k-fs__subhead"><h4>${ssrInterpolate(f.value.type === "repeater" ? unref(__)("Fields in each item") : unref(__)("Fields in the group"))}</h4></div>`);
				if (err("children")) _push(`<p class="k-field__error" role="alert">${ssrInterpolate(err("children"))}</p>`);
				else _push(`<!---->`);
				_push(ssrRenderComponent(FieldList_default, {
					fields: f.value.children,
					meta: __props.meta,
					errors: __props.errors,
					path: `${__props.path}.${__props.index}.children`,
					depth: __props.depth + 1,
					mode: __props.mode
				}, null, _parent));
				_push(`</div>`);
			} else _push(`<!---->`);
			_push(`</div>`);
		};
	}
};
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/components/content-types/FieldSettings.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export { machine as a, blankField as i, FieldList_default as n, newId as o, fieldTypeLabel as r, _sfc_main as t };
