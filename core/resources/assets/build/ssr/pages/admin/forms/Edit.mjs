import { t as _sfc_main$2 } from "../../../chunks/FormField-1Z6RCO7g.mjs";
import { a as machine, i as blankField, o as newId, r as fieldTypeLabel, t as _sfc_main$3 } from "../../../chunks/FieldSettings-CeeHlog6.mjs";
import { t as _plugin_vue_export_helper_default } from "../../../chunks/_plugin-vue_export-helper-DjkACiAr.mjs";
import { Fragment, computed, createBlock, createCommentVNode, createTextVNode, createVNode, mergeModels, mergeProps, nextTick, openBlock, ref, renderList, toDisplayString, unref, useModel, useSSRContext, vModelText, watch, withCtx, withDirectives } from "vue";
import { ssrIncludeBooleanAttr, ssrInterpolate, ssrRenderAttr, ssrRenderAttrs, ssrRenderClass, ssrRenderComponent, ssrRenderList, ssrRenderSlot, ssrRenderStyle } from "vue/server-renderer";
import { Head, Link, usePage, useRouter } from "@kestello/app";
import { __, clone, confirm, notify, useApi, useLeaveGuard } from "@kestello/sdk";
import { KAlert, KButton, KCard, KField, KIcon, KInput, KSelect, KSwitch, KTabs } from "@kestello/ui";
// core/resources/js/components/forms/form-builder.js
/**
* The form builder's changes to a form's fields (FormBuilder.vue): adding a field of a
* type or a ready-made one, moving, duplicating and removing. Pure: arrays in, arrays
* out, names kept unique (answers are stored under them). Labels come from the caller.
*/
/** The palette: each type a form takes, with its icon, in the order people reach for them. */
var TYPE_ICONS = {
	text: "text-t",
	textarea: "text-align-left",
	email: "envelope-simple",
	url: "link-simple",
	number: "hash",
	select: "caret-circle-down",
	multi_select: "check-square",
	boolean: "toggle-left",
	date: "calendar-blank",
	file: "paperclip"
};
/** Ready-made fields most forms need. label: the caller's (translated) label. */
var PRESETS = [
	{
		key: "name",
		type: "text",
		name: "name",
		width: "half",
		required: true,
		icon: "user"
	},
	{
		key: "email",
		type: "email",
		name: "email",
		width: "half",
		required: true,
		icon: "envelope-simple"
	},
	{
		key: "phone",
		type: "text",
		name: "phone",
		width: "half",
		icon: "phone"
	},
	{
		key: "message",
		type: "textarea",
		name: "message",
		required: true,
		icon: "chat-text",
		options: { rows: 5 }
	},
	{
		key: "consent",
		type: "boolean",
		name: "consent",
		required: true,
		icon: "check-circle"
	}
];
/** A name not yet taken: email, email_2, email_3… */
function uniqueName(base, fields, except = null) {
	const taken = new Set(fields.filter((f) => f !== except).map((f) => f.name));
	const root = machine(base) || "field";
	if (!taken.has(root)) return root;
	for (let n = 2;; n++) {
		const name = `${root.slice(0, 60)}_${n}`;
		if (!taken.has(name)) return name;
	}
}
/**
* A new field: of a type ({ type }) or ready-made ({ preset }), labelled, named uniquely.
* @param {{ type?: string, preset?: object, label: string, allowed?: object }} what  allowed: the type's options (meta.options[type])
*/
function newFormField(fields, { type, preset, label, allowed = {} }) {
	const t = preset?.type ?? type;
	const f = blankField(t);
	f.label = label;
	f.name = uniqueName(preset?.name ?? label, fields);
	f._namedByHand = !!preset;
	if (preset) Object.assign(f, {
		width: preset.width ?? "full",
		required: !!preset.required,
		options: { ...preset.options ?? {} }
	});
	if (allowed.choices) f.options.choices = [{
		value: "first",
		label: ""
	}, {
		value: "second",
		label: ""
	}];
	if (allowed.display) f.options.display = "radios";
	return f;
}
/** The fields with one inserted at a place (the end when out of range). */
function insertField(fields, field, index = fields.length) {
	const at = Math.max(0, Math.min(index, fields.length));
	return [
		...fields.slice(0, at),
		field,
		...fields.slice(at)
	];
}
/** Fields whose conditions point at a field that isn't above them (a condition reads answers already given). */
function misplacedConditions(fields) {
	const seen = /* @__PURE__ */ new Set();
	const out = /* @__PURE__ */ new Set();
	for (const f of fields) {
		if ((f.conditions?.rules ?? []).some((r) => !seen.has(r.field))) out.add(f.id);
		seen.add(f.name);
	}
	return out;
}
// core/resources/js/components/forms/FormBuilder.vue
var _sfc_main$1 = {
	__name: "FormBuilder",
	__ssrInlineRender: true,
	props: /*@__PURE__*/ mergeModels({
		meta: {
			type: Object,
			required: true
		},
		errors: {
			type: Object,
			default: () => ({})
		},
		submitLabel: {
			type: String,
			default: ""
		}
	}, {
		"fields": {
			type: Array,
			required: true
		},
		"fieldsModifiers": {}
	}),
	emits: ["update:fields"],
	setup(__props) {
		const fields = useModel(__props, "fields");
		const props = __props;
		const selectedId = ref(null);
		const tab = ref("form");
		const live = ref("");
		const canvas = ref(null);
		const selectedIndex = computed(() => fields.value.findIndex((f) => f.id === selectedId.value));
		const selectedField = computed(() => fields.value[selectedIndex.value] ?? null);
		const misplaced = computed(() => misplacedConditions(fields.value));
		const tabs = computed(() => [{
			id: "field",
			label: __("Field")
		}, {
			id: "form",
			label: __("Form")
		}]);
		const PRESET_LABELS = {
			name: __("Name"),
			email: __("Email"),
			phone: __("Phone"),
			message: __("Message"),
			consent: __("I agree to be contacted about my message")
		};
		const types = computed(() => Object.keys(props.meta.options).map((t) => ({
			type: t,
			label: fieldTypeLabel(t),
			icon: TYPE_ICONS[t] ?? "square"
		})));
		const errorsOf = (i) => Object.keys(props.errors).some((k) => k === `fields.${i}` || k.startsWith(`fields.${i}.`));
		watch(() => props.errors, (e) => {
			const i = fields.value.findIndex((_, j) => errorsOf(j));
			if (i !== -1 && e) select(fields.value[i].id);
		});
		const history = ref([]);
		function change(next, message) {
			history.value = [...history.value.slice(-49), fields.value];
			fields.value = next;
			live.value = message;
		}
		function undo() {
			const prev = history.value.at(-1);
			if (!prev) return;
			history.value = history.value.slice(0, -1);
			fields.value = prev;
			if (!prev.some((f) => f.id === selectedId.value)) selectedId.value = null;
			live.value = __("Undone.");
		}
		function select(id, { focus = false } = {}) {
			selectedId.value = id;
			tab.value = id ? "field" : "form";
			if (focus) nextTick(() => canvas.value?.querySelector(`[data-field="${id}"]`)?.focus());
		}
		const nameOf = (f) => f.label || f.name || __("New field");
		/** The usual start, in one step: name and email side by side, then the message. */
		function addStarter() {
			let next = fields.value;
			for (const key of [
				"name",
				"email",
				"message"
			]) {
				const preset = PRESETS.find((p) => p.key === key);
				next = insertField(next, newFormField(next, {
					preset,
					label: PRESET_LABELS[key],
					allowed: props.meta.options[preset.type] ?? {}
				}));
			}
			change(next, __("Name, email and message added."));
			select(next[0].id);
		}
		const drop = ref(null);
		const fileWords = {
			one: (p) => __("Up to {size} MB.", p),
			many: (p) => __("Up to {count} files, {size} MB each.", p)
		};
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<div${ssrRenderAttrs(mergeProps({ class: "k-fbld" }, _attrs))}><aside class="k-fbld__palette"${ssrRenderAttr("aria-label", unref(__)("Add a field"))}><div class="k-fbld__palette-head"><h2>${ssrInterpolate(unref(__)("Add a field"))}</h2>`);
			_push(ssrRenderComponent(unref(KButton), {
				size: "sm",
				variant: "ghost",
				icon: "arrow-counter-clockwise",
				disabled: !history.value.length,
				title: unref(__)("Undo"),
				"aria-label": unref(__)("Undo"),
				onClick: undo
			}, null, _parent));
			_push(`</div><h3>${ssrInterpolate(unref(__)("Ready-made"))}</h3><div class="k-fbld__tiles"><!--[-->`);
			ssrRenderList(unref(PRESETS), (p) => {
				_push(`<button type="button" class="k-fbld__tile" draggable="true">`);
				_push(ssrRenderComponent(unref(KIcon), { name: p.icon }, null, _parent));
				_push(`<span>${ssrInterpolate(p.key === "consent" ? unref(__)("Consent") : PRESET_LABELS[p.key])}</span></button>`);
			});
			_push(`<!--]--></div><h3>${ssrInterpolate(unref(__)("Field types"))}</h3><div class="k-fbld__tiles"><!--[-->`);
			ssrRenderList(types.value, (t) => {
				_push(`<button type="button" class="k-fbld__tile" draggable="true">`);
				_push(ssrRenderComponent(unref(KIcon), { name: t.icon }, null, _parent));
				_push(`<span>${ssrInterpolate(t.label)}</span></button>`);
			});
			_push(`<!--]--></div><p class="k-fbld__hint">${ssrInterpolate(unref(__)("Click to add after the selected field, or drag into place."))}</p></aside><section class="k-fbld__canvas"${ssrRenderAttr("aria-label", unref(__)("The form as visitors see it"))}><div class="k-fbld__sheet">`);
			if (fields.value.length) {
				_push(`<ul class="k-b-form__grid k-fbld__grid" role="listbox"${ssrRenderAttr("aria-label", unref(__)("Fields"))}><!--[-->`);
				ssrRenderList(fields.value, (f, i) => {
					_push(`<li${ssrRenderAttr("data-field", f.id)} role="option"${ssrRenderAttr("aria-selected", String(selectedId.value === f.id))} tabindex="0" class="${ssrRenderClass([[`k-b-form__field--${f.width}`, {
						"is-selected": selectedId.value === f.id,
						"has-error": errorsOf(i),
						[`drop-${drop.value?.side}`]: drop.value?.index === i
					}], "k-b-form__field k-fbld__item"])}"${ssrRenderAttr("aria-label", `${nameOf(f)}, ${unref(fieldTypeLabel)(f.type)}${f.required ? `, ${unref(__)("required")}` : ""}`)} draggable="true"><div class="k-fbld__field" inert>`);
					_push(ssrRenderComponent(_sfc_main$2, {
						"model-value": f.type === "multi_select" ? [] : f.type === "boolean" ? f.default === true : f.default ?? "",
						field: f,
						"input-id": `k-fbld-${f.id}`,
						"choose-text": unref(__)("Choose…"),
						"file-words": fileWords
					}, null, _parent));
					_push(`</div>`);
					if (f.conditions || errorsOf(i) || misplaced.value.has(f.id)) {
						_push(`<div class="k-fbld__flags">`);
						if (errorsOf(i)) {
							_push(`<span class="k-fbld__flag is-danger">`);
							_push(ssrRenderComponent(unref(KIcon), { name: "warning-circle" }, null, _parent));
							_push(`${ssrInterpolate(unref(__)("Needs attention"))}</span>`);
						} else _push(`<!---->`);
						if (misplaced.value.has(f.id)) {
							_push(`<span class="k-fbld__flag is-danger">`);
							_push(ssrRenderComponent(unref(KIcon), { name: "arrow-up" }, null, _parent));
							_push(`${ssrInterpolate(unref(__)("Its condition reads a field below it; move it lower"))}</span>`);
						} else if (f.conditions) {
							_push(`<span class="k-fbld__flag">`);
							_push(ssrRenderComponent(unref(KIcon), { name: "eye" }, null, _parent));
							_push(`${ssrInterpolate(unref(__)("Shown only when its condition holds"))}</span>`);
						} else _push(`<!---->`);
						_push(`</div>`);
					} else _push(`<!---->`);
					if (selectedId.value === f.id) {
						_push(`<div class="k-fbld__tools" role="toolbar"${ssrRenderAttr("aria-label", unref(__)("{field} field", { field: nameOf(f) }))}><button type="button" class="k-fbld__tool"${ssrIncludeBooleanAttr(i === 0) ? " disabled" : ""}${ssrRenderAttr("title", unref(__)("Move up (Alt+Up)"))}${ssrRenderAttr("aria-label", unref(__)("Move up"))}>`);
						_push(ssrRenderComponent(unref(KIcon), { name: "arrow-up" }, null, _parent));
						_push(`</button><button type="button" class="k-fbld__tool"${ssrIncludeBooleanAttr(i === fields.value.length - 1) ? " disabled" : ""}${ssrRenderAttr("title", unref(__)("Move down (Alt+Down)"))}${ssrRenderAttr("aria-label", unref(__)("Move down"))}>`);
						_push(ssrRenderComponent(unref(KIcon), { name: "arrow-down" }, null, _parent));
						_push(`</button><button type="button" class="k-fbld__tool"${ssrRenderAttr("title", f.width === "half" ? unref(__)("Full width") : unref(__)("Half width"))}${ssrRenderAttr("aria-label", f.width === "half" ? unref(__)("Full width") : unref(__)("Half width"))}>`);
						_push(ssrRenderComponent(unref(KIcon), { name: f.width === "half" ? "arrows-out-line-horizontal" : "arrows-in-line-horizontal" }, null, _parent));
						_push(`</button><button type="button" class="k-fbld__tool"${ssrRenderAttr("title", unref(__)("Duplicate"))}${ssrRenderAttr("aria-label", unref(__)("Duplicate"))}>`);
						_push(ssrRenderComponent(unref(KIcon), { name: "copy" }, null, _parent));
						_push(`</button><button type="button" class="k-fbld__tool"${ssrRenderAttr("title", unref(__)("Remove (Delete)"))}${ssrRenderAttr("aria-label", unref(__)("Remove"))}>`);
						_push(ssrRenderComponent(unref(KIcon), { name: "trash" }, null, _parent));
						_push(`</button></div>`);
					} else _push(`<!---->`);
					_push(`</li>`);
				});
				_push(`<!--]--><li class="${ssrRenderClass([{ "is-over": drop.value?.end }, "k-fbld__end"])}" aria-hidden="true"></li></ul>`);
			} else {
				_push(`<div class="${ssrRenderClass([{ "is-over": drop.value?.end }, "k-fbld__empty"])}"><p>${ssrInterpolate(unref(__)("No fields yet. Add one from the left, or drag it here."))}</p>`);
				_push(ssrRenderComponent(unref(KButton), {
					size: "sm",
					icon: "lightning",
					onClick: addStarter
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`${ssrInterpolate(unref(__)("Start with name, email and message"))}`);
						else return [createTextVNode(toDisplayString(unref(__)("Start with name, email and message")), 1)];
					}),
					_: 1
				}, _parent));
				_push(`</div>`);
			}
			_push(`<span class="k-fbld__submit" aria-hidden="true">${ssrInterpolate(__props.submitLabel || unref(__)("Send"))}</span></div></section><aside class="k-fbld__panel"${ssrRenderAttr("aria-label", unref(__)("Settings"))}>`);
			_push(ssrRenderComponent(unref(KTabs), {
				modelValue: tab.value,
				"onUpdate:modelValue": ($event) => tab.value = $event,
				tabs: tabs.value,
				label: unref(__)("Settings")
			}, null, _parent));
			_push(`<div class="k-fbld__panel-body">`);
			if (tab.value === "field") {
				_push(`<!--[-->`);
				if (selectedField.value) {
					_push(`<!--[--><div class="k-fbld__panel-head">`);
					_push(ssrRenderComponent(unref(KIcon), { name: unref(TYPE_ICONS)[selectedField.value.type] ?? "square" }, null, _parent));
					_push(`<span>${ssrInterpolate(nameOf(selectedField.value))}</span></div>`);
					_push(ssrRenderComponent(_sfc_main$3, {
						key: selectedField.value.id,
						field: selectedField.value,
						index: selectedIndex.value,
						siblings: fields.value,
						meta: __props.meta,
						errors: __props.errors,
						mode: "form"
					}, null, _parent));
					_push(`<!--]-->`);
				} else _push(`<p class="k-fbld__none">${ssrInterpolate(unref(__)("Select a field in the form to change it."))}</p>`);
				_push(`<!--]-->`);
			} else _push(`<!---->`);
			_push(`<div style="${ssrRenderStyle(tab.value === "form" ? null : { display: "none" })}">`);
			ssrRenderSlot(_ctx.$slots, "form", {}, null, _push, _parent);
			_push(`</div></div></aside><div class="k-sr-only" aria-live="polite" role="status">${ssrInterpolate(live.value)}</div></div>`);
		};
	}
};
var _sfc_setup$1 = _sfc_main$1.setup;
_sfc_main$1.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/components/forms/FormBuilder.vue");
	return _sfc_setup$1 ? _sfc_setup$1(props, ctx) : void 0;
};
// core/resources/js/pages/admin/forms/Edit.vue
var _sfc_main = /*@__PURE__*/ Object.assign({ layout: "Admin" }, {
	__name: "Edit",
	__ssrInlineRender: true,
	setup(__props) {
		/** One form, built visually (FormBuilder): its fields (with conditions), and in the Form tab its messages, notifications, storage and spam settings. */
		const api = useApi();
		const page = usePage();
		const router = useRouter();
		const id = computed(() => String(page.props.params?.id ?? ""));
		const form = ref(null);
		const meta = ref(null);
		const loadError = ref(null);
		const errors = ref({});
		const saving = ref(false);
		const toRows = (fields) => fields.map((f) => ({
			...f,
			id: f.id || newId(),
			options: {
				...f.options,
				...f.options?.choices && !Array.isArray(f.options.choices) ? { choices: Object.entries(f.options.choices).map(([value, label]) => ({
					value,
					label
				})) } : {}
			},
			children: []
		}));
		async function load() {
			try {
				const body = await api.get(`/forms/${id.value}`);
				const d = clone(body.data.definition);
				d.fields = toRows(d.fields);
				d.notifications = d.notifications.map((n) => ({
					...n,
					to: n.to.join(", "),
					reply_to: n.reply_to ?? ""
				}));
				form.value = {
					...body.data,
					definition: d
				};
				saved.value = snapshot();
				meta.value = body.meta;
			} catch (e) {
				loadError.value = e;
			}
		}
		load();
		const def = computed(() => form.value?.definition);
		const snapshot = () => form.value ? JSON.stringify([
			form.value.title,
			form.value.status,
			form.value.definition
		]) : "";
		const saved = ref("");
		const dirty = computed(() => form.value !== null && snapshot() !== saved.value);
		useLeaveGuard(dirty);
		const emailFields = computed(() => (def.value?.fields ?? []).filter((f) => f.type === "email" && f.name));
		const err = (key) => errors.value[key] ?? "";
		async function remove() {
			if (!await confirm({
				title: __("Delete {name}?", { name: form.value.title }),
				message: __("Every submission and uploaded file goes with it, and pages showing it show nothing. This can't be undone."),
				confirmLabel: __("Delete the form"),
				danger: true
			})) return;
			try {
				await api.delete(`/forms/${id.value}`);
				notify.success(__("Form deleted."));
				router.visit("/admin/forms", { discardUnsaved: true });
			} catch (e) {
				notify.error(e);
			}
		}
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<!--[-->`);
			_push(ssrRenderComponent(unref(Head), { title: form.value ? form.value.title : unref(__)("Form") }, null, _parent));
			if (loadError.value) _push(ssrRenderComponent(unref(KAlert), { tone: "danger" }, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(`${ssrInterpolate(loadError.value.message)}`);
					else return [createTextVNode(toDisplayString(loadError.value.message), 1)];
				}),
				_: 1
			}, _parent));
			else _push(`<!---->`);
			_push(`<!-- novalidate: the builder draws the form's real (required) fields; the server checks what is saved -->`);
			if (form.value) {
				_push(`<form novalidate data-v-978749f7><div class="k-editor-head" data-v-978749f7>`);
				_push(ssrRenderComponent(unref(Link), {
					href: "/admin/forms",
					class: "k-link k-editor-head__back"
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) {
							_push(ssrRenderComponent(unref(KIcon), { name: "arrow-left" }, null, _parent, _scopeId));
							_push(` ${ssrInterpolate(unref(__)("Forms"))}`);
						} else return [createVNode(unref(KIcon), { name: "arrow-left" }), createTextVNode(" " + toDisplayString(unref(__)("Forms")), 1)];
					}),
					_: 1
				}, _parent));
				_push(`<span class="k-editor-head__state" data-v-978749f7>`);
				_push(ssrRenderComponent(unref(KSwitch), {
					"model-value": form.value.status === "open",
					label: form.value.status === "open" ? unref(__)("Open: taking submissions") : unref(__)("Closed"),
					"onUpdate:modelValue": (v) => form.value.status = v ? "open" : "closed"
				}, null, _parent));
				_push(`</span>`);
				_push(ssrRenderComponent(unref(KButton), {
					icon: "tray",
					href: `/admin/forms/${form.value.id}/submissions`
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`${ssrInterpolate(unref(__)("Submissions"))}`);
						else return [createTextVNode(toDisplayString(unref(__)("Submissions")), 1)];
					}),
					_: 1
				}, _parent));
				if (dirty.value) _push(`<span class="k-muted k-small" data-v-978749f7>${ssrInterpolate(unref(__)("Unsaved changes"))}</span>`);
				else _push(`<!---->`);
				_push(ssrRenderComponent(unref(KButton), {
					variant: "primary",
					type: "submit",
					icon: "check",
					loading: saving.value,
					disabled: !dirty.value
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`${ssrInterpolate(unref(__)("Save"))}`);
						else return [createTextVNode(toDisplayString(unref(__)("Save")), 1)];
					}),
					_: 1
				}, _parent));
				_push(`</div><div class="${ssrRenderClass([{ "has-error": err("title") }, "k-form-title"])}" data-v-978749f7><label class="k-sr-only" for="k-form-title" data-v-978749f7>${ssrInterpolate(unref(__)("Name of the form"))}</label><input id="k-form-title"${ssrRenderAttr("value", form.value.title)} class="k-editor__title-input" maxlength="200" autocomplete="off" data-lpignore="true" data-1p-ignore data-v-978749f7>`);
				if (err("title")) _push(`<p class="k-field__error" role="alert" data-v-978749f7>${ssrInterpolate(err("title"))}</p>`);
				else _push(`<!---->`);
				_push(`<p class="k-muted k-ff-lead" data-v-978749f7>${ssrInterpolate(unref(__)("Put the form on a page with the Form block."))}</p></div>`);
				if (err("fields")) _push(`<p class="k-field__error" role="alert" data-v-978749f7>${ssrInterpolate(err("fields"))}</p>`);
				else _push(`<!---->`);
				_push(ssrRenderComponent(_sfc_main$1, {
					fields: def.value.fields,
					"onUpdate:fields": ($event) => def.value.fields = $event,
					meta: meta.value,
					errors: errors.value,
					"submit-label": def.value.submit_label
				}, {
					form: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) {
							_push(ssrRenderComponent(unref(KCard), { title: unref(__)("After sending") }, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) {
										_push(ssrRenderComponent(unref(KField), {
											label: unref(__)("Button text"),
											error: err("submit_label")
										}, {
											default: withCtx(({ id: fid, invalid }, _push, _parent, _scopeId) => {
												if (_push) _push(ssrRenderComponent(unref(KInput), {
													id: fid,
													modelValue: def.value.submit_label,
													"onUpdate:modelValue": ($event) => def.value.submit_label = $event,
													maxlength: "60",
													invalid
												}, null, _parent, _scopeId));
												else return [createVNode(unref(KInput), {
													id: fid,
													modelValue: def.value.submit_label,
													"onUpdate:modelValue": ($event) => def.value.submit_label = $event,
													maxlength: "60",
													invalid
												}, null, 8, [
													"id",
													"modelValue",
													"onUpdate:modelValue",
													"invalid"
												])];
											}),
											_: 1
										}, _parent, _scopeId));
										_push(ssrRenderComponent(unref(KField), {
											label: unref(__)("Thank-you message"),
											error: err("success_message")
										}, {
											default: withCtx(({ id: fid, invalid }, _push, _parent, _scopeId) => {
												if (_push) _push(`<textarea${ssrRenderAttr("id", fid)} class="k-input k-ff-textarea" rows="3" maxlength="1000"${ssrRenderAttr("aria-invalid", invalid ? "true" : void 0)} data-v-978749f7${_scopeId}>${ssrInterpolate(def.value.success_message)}</textarea>`);
												else return [withDirectives(createVNode("textarea", {
													id: fid,
													"onUpdate:modelValue": ($event) => def.value.success_message = $event,
													class: "k-input k-ff-textarea",
													rows: "3",
													maxlength: "1000",
													"aria-invalid": invalid ? "true" : void 0
												}, null, 8, [
													"id",
													"onUpdate:modelValue",
													"aria-invalid"
												]), [[vModelText, def.value.success_message]])];
											}),
											_: 1
										}, _parent, _scopeId));
										_push(ssrRenderComponent(unref(KField), {
											label: unref(__)("Or go to a page"),
											help: unref(__)("Such as /thanks. Replaces the message."),
											error: err("redirect")
										}, {
											default: withCtx(({ id: fid, invalid }, _push, _parent, _scopeId) => {
												if (_push) _push(ssrRenderComponent(unref(KInput), {
													id: fid,
													"model-value": def.value.redirect ?? "",
													class: "k-mono-input",
													maxlength: "1000",
													spellcheck: "false",
													invalid,
													"onUpdate:modelValue": (v) => def.value.redirect = v.trim() || null
												}, null, _parent, _scopeId));
												else return [createVNode(unref(KInput), {
													id: fid,
													"model-value": def.value.redirect ?? "",
													class: "k-mono-input",
													maxlength: "1000",
													spellcheck: "false",
													invalid,
													"onUpdate:modelValue": (v) => def.value.redirect = v.trim() || null
												}, null, 8, [
													"id",
													"model-value",
													"invalid",
													"onUpdate:modelValue"
												])];
											}),
											_: 1
										}, _parent, _scopeId));
										_push(ssrRenderComponent(unref(KField), { label: unref(__)("When the form is closed") }, {
											default: withCtx(({ id: fid }, _push, _parent, _scopeId) => {
												if (_push) _push(ssrRenderComponent(unref(KInput), {
													id: fid,
													modelValue: def.value.closed_message,
													"onUpdate:modelValue": ($event) => def.value.closed_message = $event,
													maxlength: "500"
												}, null, _parent, _scopeId));
												else return [createVNode(unref(KInput), {
													id: fid,
													modelValue: def.value.closed_message,
													"onUpdate:modelValue": ($event) => def.value.closed_message = $event,
													maxlength: "500"
												}, null, 8, [
													"id",
													"modelValue",
													"onUpdate:modelValue"
												])];
											}),
											_: 1
										}, _parent, _scopeId));
									} else return [
										createVNode(unref(KField), {
											label: unref(__)("Button text"),
											error: err("submit_label")
										}, {
											default: withCtx(({ id: fid, invalid }) => [createVNode(unref(KInput), {
												id: fid,
												modelValue: def.value.submit_label,
												"onUpdate:modelValue": ($event) => def.value.submit_label = $event,
												maxlength: "60",
												invalid
											}, null, 8, [
												"id",
												"modelValue",
												"onUpdate:modelValue",
												"invalid"
											])]),
											_: 1
										}, 8, ["label", "error"]),
										createVNode(unref(KField), {
											label: unref(__)("Thank-you message"),
											error: err("success_message")
										}, {
											default: withCtx(({ id: fid, invalid }) => [withDirectives(createVNode("textarea", {
												id: fid,
												"onUpdate:modelValue": ($event) => def.value.success_message = $event,
												class: "k-input k-ff-textarea",
												rows: "3",
												maxlength: "1000",
												"aria-invalid": invalid ? "true" : void 0
											}, null, 8, [
												"id",
												"onUpdate:modelValue",
												"aria-invalid"
											]), [[vModelText, def.value.success_message]])]),
											_: 1
										}, 8, ["label", "error"]),
										createVNode(unref(KField), {
											label: unref(__)("Or go to a page"),
											help: unref(__)("Such as /thanks. Replaces the message."),
											error: err("redirect")
										}, {
											default: withCtx(({ id: fid, invalid }) => [createVNode(unref(KInput), {
												id: fid,
												"model-value": def.value.redirect ?? "",
												class: "k-mono-input",
												maxlength: "1000",
												spellcheck: "false",
												invalid,
												"onUpdate:modelValue": (v) => def.value.redirect = v.trim() || null
											}, null, 8, [
												"id",
												"model-value",
												"invalid",
												"onUpdate:modelValue"
											])]),
											_: 1
										}, 8, [
											"label",
											"help",
											"error"
										]),
										createVNode(unref(KField), { label: unref(__)("When the form is closed") }, {
											default: withCtx(({ id: fid }) => [createVNode(unref(KInput), {
												id: fid,
												modelValue: def.value.closed_message,
												"onUpdate:modelValue": ($event) => def.value.closed_message = $event,
												maxlength: "500"
											}, null, 8, [
												"id",
												"modelValue",
												"onUpdate:modelValue"
											])]),
											_: 1
										}, 8, ["label"])
									];
								}),
								_: 1
							}, _parent, _scopeId));
							_push(ssrRenderComponent(unref(KCard), { title: unref(__)("Email") }, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) {
										if (!def.value.notifications.length) _push(`<p class="k-muted k-small k-ff-note" data-v-978749f7${_scopeId}>${ssrInterpolate(unref(__)("Nobody is emailed. Submissions wait on the Submissions screen."))}</p>`);
										else _push(`<!---->`);
										_push(`<!--[-->`);
										ssrRenderList(def.value.notifications, (n, i) => {
											_push(`<div class="k-ff-mail" data-v-978749f7${_scopeId}>`);
											_push(ssrRenderComponent(unref(KField), {
												label: unref(__)("Send to"),
												help: unref(__)("One or more addresses, separated by commas."),
												error: err(`notifications.${i}.to`)
											}, {
												default: withCtx(({ id: fid, invalid }, _push, _parent, _scopeId) => {
													if (_push) _push(ssrRenderComponent(unref(KInput), {
														id: fid,
														modelValue: n.to,
														"onUpdate:modelValue": ($event) => n.to = $event,
														type: "text",
														autocomplete: "off",
														spellcheck: "false",
														invalid
													}, null, _parent, _scopeId));
													else return [createVNode(unref(KInput), {
														id: fid,
														modelValue: n.to,
														"onUpdate:modelValue": ($event) => n.to = $event,
														type: "text",
														autocomplete: "off",
														spellcheck: "false",
														invalid
													}, null, 8, [
														"id",
														"modelValue",
														"onUpdate:modelValue",
														"invalid"
													])];
												}),
												_: 2
											}, _parent, _scopeId));
											_push(ssrRenderComponent(unref(KField), {
												label: unref(__)("Subject"),
												help: unref(__)("Empty: New submission: {name}", { name: form.value.title })
											}, {
												default: withCtx(({ id: fid }, _push, _parent, _scopeId) => {
													if (_push) _push(ssrRenderComponent(unref(KInput), {
														id: fid,
														modelValue: n.subject,
														"onUpdate:modelValue": ($event) => n.subject = $event,
														maxlength: "200"
													}, null, _parent, _scopeId));
													else return [createVNode(unref(KInput), {
														id: fid,
														modelValue: n.subject,
														"onUpdate:modelValue": ($event) => n.subject = $event,
														maxlength: "200"
													}, null, 8, [
														"id",
														"modelValue",
														"onUpdate:modelValue"
													])];
												}),
												_: 2
											}, _parent, _scopeId));
											if (emailFields.value.length) _push(ssrRenderComponent(unref(KField), {
												label: unref(__)("Replies go to"),
												error: err(`notifications.${i}.reply_to`)
											}, {
												default: withCtx(({ id: fid, invalid }, _push, _parent, _scopeId) => {
													if (_push) _push(ssrRenderComponent(unref(KSelect), {
														id: fid,
														modelValue: n.reply_to,
														"onUpdate:modelValue": ($event) => n.reply_to = $event,
														invalid,
														options: [{
															value: "",
															label: unref(__)("The site's address")
														}, ...emailFields.value.map((f) => ({
															value: f.name,
															label: unref(__)("The address in {field}", { field: f.label || f.name })
														}))]
													}, null, _parent, _scopeId));
													else return [createVNode(unref(KSelect), {
														id: fid,
														modelValue: n.reply_to,
														"onUpdate:modelValue": ($event) => n.reply_to = $event,
														invalid,
														options: [{
															value: "",
															label: unref(__)("The site's address")
														}, ...emailFields.value.map((f) => ({
															value: f.name,
															label: unref(__)("The address in {field}", { field: f.label || f.name })
														}))]
													}, null, 8, [
														"id",
														"modelValue",
														"onUpdate:modelValue",
														"invalid",
														"options"
													])];
												}),
												_: 2
											}, _parent, _scopeId));
											else _push(`<!---->`);
											_push(ssrRenderComponent(unref(KButton), {
												size: "sm",
												variant: "ghost",
												icon: "trash",
												onClick: ($event) => def.value.notifications.splice(i, 1)
											}, {
												default: withCtx((_, _push, _parent, _scopeId) => {
													if (_push) _push(`${ssrInterpolate(unref(__)("Stop this email"))}`);
													else return [createTextVNode(toDisplayString(unref(__)("Stop this email")), 1)];
												}),
												_: 2
											}, _parent, _scopeId));
											_push(`</div>`);
										});
										_push(`<!--]-->`);
										if (def.value.notifications.length < 5) _push(ssrRenderComponent(unref(KButton), {
											size: "sm",
											icon: "plus",
											onClick: ($event) => def.value.notifications.push({
												to: "",
												subject: "",
												reply_to: emailFields.value[0]?.name ?? ""
											})
										}, {
											default: withCtx((_, _push, _parent, _scopeId) => {
												if (_push) _push(`${ssrInterpolate(unref(__)("Email each submission"))}`);
												else return [createTextVNode(toDisplayString(unref(__)("Email each submission")), 1)];
											}),
											_: 1
										}, _parent, _scopeId));
										else _push(`<!---->`);
									} else return [
										!def.value.notifications.length ? (openBlock(), createBlock("p", {
											key: 0,
											class: "k-muted k-small k-ff-note"
										}, toDisplayString(unref(__)("Nobody is emailed. Submissions wait on the Submissions screen.")), 1)) : createCommentVNode("v-if", true),
										(openBlock(true), createBlock(Fragment, null, renderList(def.value.notifications, (n, i) => {
											return openBlock(), createBlock("div", {
												key: i,
												class: "k-ff-mail"
											}, [
												createVNode(unref(KField), {
													label: unref(__)("Send to"),
													help: unref(__)("One or more addresses, separated by commas."),
													error: err(`notifications.${i}.to`)
												}, {
													default: withCtx(({ id: fid, invalid }) => [createVNode(unref(KInput), {
														id: fid,
														modelValue: n.to,
														"onUpdate:modelValue": ($event) => n.to = $event,
														type: "text",
														autocomplete: "off",
														spellcheck: "false",
														invalid
													}, null, 8, [
														"id",
														"modelValue",
														"onUpdate:modelValue",
														"invalid"
													])]),
													_: 2
												}, 1032, [
													"label",
													"help",
													"error"
												]),
												createVNode(unref(KField), {
													label: unref(__)("Subject"),
													help: unref(__)("Empty: New submission: {name}", { name: form.value.title })
												}, {
													default: withCtx(({ id: fid }) => [createVNode(unref(KInput), {
														id: fid,
														modelValue: n.subject,
														"onUpdate:modelValue": ($event) => n.subject = $event,
														maxlength: "200"
													}, null, 8, [
														"id",
														"modelValue",
														"onUpdate:modelValue"
													])]),
													_: 2
												}, 1032, ["label", "help"]),
												emailFields.value.length ? (openBlock(), createBlock(unref(KField), {
													key: 0,
													label: unref(__)("Replies go to"),
													error: err(`notifications.${i}.reply_to`)
												}, {
													default: withCtx(({ id: fid, invalid }) => [createVNode(unref(KSelect), {
														id: fid,
														modelValue: n.reply_to,
														"onUpdate:modelValue": ($event) => n.reply_to = $event,
														invalid,
														options: [{
															value: "",
															label: unref(__)("The site's address")
														}, ...emailFields.value.map((f) => ({
															value: f.name,
															label: unref(__)("The address in {field}", { field: f.label || f.name })
														}))]
													}, null, 8, [
														"id",
														"modelValue",
														"onUpdate:modelValue",
														"invalid",
														"options"
													])]),
													_: 2
												}, 1032, ["label", "error"])) : createCommentVNode("v-if", true),
												createVNode(unref(KButton), {
													size: "sm",
													variant: "ghost",
													icon: "trash",
													onClick: ($event) => def.value.notifications.splice(i, 1)
												}, {
													default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Stop this email")), 1)]),
													_: 1
												}, 8, ["onClick"])
											]);
										}), 128)),
										def.value.notifications.length < 5 ? (openBlock(), createBlock(unref(KButton), {
											key: 1,
											size: "sm",
											icon: "plus",
											onClick: ($event) => def.value.notifications.push({
												to: "",
												subject: "",
												reply_to: emailFields.value[0]?.name ?? ""
											})
										}, {
											default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Email each submission")), 1)]),
											_: 1
										}, 8, ["onClick"])) : createCommentVNode("v-if", true)
									];
								}),
								_: 1
							}, _parent, _scopeId));
							_push(ssrRenderComponent(unref(KCard), { title: unref(__)("Keeping and spam") }, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) {
										_push(`<div class="k-ff-switches" data-v-978749f7${_scopeId}>`);
										_push(ssrRenderComponent(unref(KSwitch), {
											modelValue: def.value.store,
											"onUpdate:modelValue": ($event) => def.value.store = $event,
											label: unref(__)("Keep submissions here")
										}, null, _parent, _scopeId));
										if (err("store")) _push(`<p class="k-field__error" role="alert" data-v-978749f7${_scopeId}>${ssrInterpolate(err("store"))}</p>`);
										else _push(`<!---->`);
										_push(`</div><div class="k-ff-pair" data-v-978749f7${_scopeId}>`);
										if (def.value.store) _push(ssrRenderComponent(unref(KField), {
											label: unref(__)("Delete after (days)"),
											help: unref(__)("0 keeps them until you delete them.")
										}, {
											default: withCtx(({ id: fid }, _push, _parent, _scopeId) => {
												if (_push) _push(ssrRenderComponent(unref(KInput), {
													id: fid,
													modelValue: def.value.retention_days,
													"onUpdate:modelValue": ($event) => def.value.retention_days = $event,
													modelModifiers: { number: true },
													type: "number",
													min: "0",
													max: "3650"
												}, null, _parent, _scopeId));
												else return [createVNode(unref(KInput), {
													id: fid,
													modelValue: def.value.retention_days,
													"onUpdate:modelValue": ($event) => def.value.retention_days = $event,
													modelModifiers: { number: true },
													type: "number",
													min: "0",
													max: "3650"
												}, null, 8, [
													"id",
													"modelValue",
													"onUpdate:modelValue"
												])];
											}),
											_: 1
										}, _parent, _scopeId));
										else _push(`<!---->`);
										_push(ssrRenderComponent(unref(KField), {
											label: unref(__)("Per visitor and hour"),
											help: unref(__)("More are refused.")
										}, {
											default: withCtx(({ id: fid }, _push, _parent, _scopeId) => {
												if (_push) _push(ssrRenderComponent(unref(KInput), {
													id: fid,
													modelValue: def.value.per_ip_hour,
													"onUpdate:modelValue": ($event) => def.value.per_ip_hour = $event,
													modelModifiers: { number: true },
													type: "number",
													min: "1",
													max: "1000"
												}, null, _parent, _scopeId));
												else return [createVNode(unref(KInput), {
													id: fid,
													modelValue: def.value.per_ip_hour,
													"onUpdate:modelValue": ($event) => def.value.per_ip_hour = $event,
													modelModifiers: { number: true },
													type: "number",
													min: "1",
													max: "1000"
												}, null, 8, [
													"id",
													"modelValue",
													"onUpdate:modelValue"
												])];
											}),
											_: 1
										}, _parent, _scopeId));
										_push(`</div>`);
										_push(ssrRenderComponent(unref(KSwitch), {
											modelValue: def.value.captcha,
											"onUpdate:modelValue": ($event) => def.value.captcha = $event,
											disabled: !meta.value.captcha && !def.value.captcha,
											label: unref(__)("Ask for a captcha")
										}, null, _parent, _scopeId));
										_push(`<p class="k-muted k-small k-ff-note" data-v-978749f7${_scopeId}>${ssrInterpolate(meta.value.captcha ? unref(__)("Shown by the captcha plugin you installed.") : unref(__)("Needs a captcha plugin. A hidden trap field, a minimum fill time and the limit above already stop most spam."))}</p>`);
										if (err("captcha")) _push(`<p class="k-field__error" role="alert" data-v-978749f7${_scopeId}>${ssrInterpolate(err("captcha"))}</p>`);
										else _push(`<!---->`);
									} else return [
										createVNode("div", { class: "k-ff-switches" }, [createVNode(unref(KSwitch), {
											modelValue: def.value.store,
											"onUpdate:modelValue": ($event) => def.value.store = $event,
											label: unref(__)("Keep submissions here")
										}, null, 8, [
											"modelValue",
											"onUpdate:modelValue",
											"label"
										]), err("store") ? (openBlock(), createBlock("p", {
											key: 0,
											class: "k-field__error",
											role: "alert"
										}, toDisplayString(err("store")), 1)) : createCommentVNode("v-if", true)]),
										createVNode("div", { class: "k-ff-pair" }, [def.value.store ? (openBlock(), createBlock(unref(KField), {
											key: 0,
											label: unref(__)("Delete after (days)"),
											help: unref(__)("0 keeps them until you delete them.")
										}, {
											default: withCtx(({ id: fid }) => [createVNode(unref(KInput), {
												id: fid,
												modelValue: def.value.retention_days,
												"onUpdate:modelValue": ($event) => def.value.retention_days = $event,
												modelModifiers: { number: true },
												type: "number",
												min: "0",
												max: "3650"
											}, null, 8, [
												"id",
												"modelValue",
												"onUpdate:modelValue"
											])]),
											_: 1
										}, 8, ["label", "help"])) : createCommentVNode("v-if", true), createVNode(unref(KField), {
											label: unref(__)("Per visitor and hour"),
											help: unref(__)("More are refused.")
										}, {
											default: withCtx(({ id: fid }) => [createVNode(unref(KInput), {
												id: fid,
												modelValue: def.value.per_ip_hour,
												"onUpdate:modelValue": ($event) => def.value.per_ip_hour = $event,
												modelModifiers: { number: true },
												type: "number",
												min: "1",
												max: "1000"
											}, null, 8, [
												"id",
												"modelValue",
												"onUpdate:modelValue"
											])]),
											_: 1
										}, 8, ["label", "help"])]),
										createVNode(unref(KSwitch), {
											modelValue: def.value.captcha,
											"onUpdate:modelValue": ($event) => def.value.captcha = $event,
											disabled: !meta.value.captcha && !def.value.captcha,
											label: unref(__)("Ask for a captcha")
										}, null, 8, [
											"modelValue",
											"onUpdate:modelValue",
											"disabled",
											"label"
										]),
										createVNode("p", { class: "k-muted k-small k-ff-note" }, toDisplayString(meta.value.captcha ? unref(__)("Shown by the captcha plugin you installed.") : unref(__)("Needs a captcha plugin. A hidden trap field, a minimum fill time and the limit above already stop most spam.")), 1),
										err("captcha") ? (openBlock(), createBlock("p", {
											key: 0,
											class: "k-field__error",
											role: "alert"
										}, toDisplayString(err("captcha")), 1)) : createCommentVNode("v-if", true)
									];
								}),
								_: 1
							}, _parent, _scopeId));
							_push(`<div class="k-editor__trash" data-v-978749f7${_scopeId}>`);
							_push(ssrRenderComponent(unref(KButton), {
								variant: "ghost",
								icon: "trash",
								onClick: remove
							}, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate(unref(__)("Delete this form"))}`);
									else return [createTextVNode(toDisplayString(unref(__)("Delete this form")), 1)];
								}),
								_: 1
							}, _parent, _scopeId));
							_push(`</div>`);
						} else return [
							createVNode(unref(KCard), { title: unref(__)("After sending") }, {
								default: withCtx(() => [
									createVNode(unref(KField), {
										label: unref(__)("Button text"),
										error: err("submit_label")
									}, {
										default: withCtx(({ id: fid, invalid }) => [createVNode(unref(KInput), {
											id: fid,
											modelValue: def.value.submit_label,
											"onUpdate:modelValue": ($event) => def.value.submit_label = $event,
											maxlength: "60",
											invalid
										}, null, 8, [
											"id",
											"modelValue",
											"onUpdate:modelValue",
											"invalid"
										])]),
										_: 1
									}, 8, ["label", "error"]),
									createVNode(unref(KField), {
										label: unref(__)("Thank-you message"),
										error: err("success_message")
									}, {
										default: withCtx(({ id: fid, invalid }) => [withDirectives(createVNode("textarea", {
											id: fid,
											"onUpdate:modelValue": ($event) => def.value.success_message = $event,
											class: "k-input k-ff-textarea",
											rows: "3",
											maxlength: "1000",
											"aria-invalid": invalid ? "true" : void 0
										}, null, 8, [
											"id",
											"onUpdate:modelValue",
											"aria-invalid"
										]), [[vModelText, def.value.success_message]])]),
										_: 1
									}, 8, ["label", "error"]),
									createVNode(unref(KField), {
										label: unref(__)("Or go to a page"),
										help: unref(__)("Such as /thanks. Replaces the message."),
										error: err("redirect")
									}, {
										default: withCtx(({ id: fid, invalid }) => [createVNode(unref(KInput), {
											id: fid,
											"model-value": def.value.redirect ?? "",
											class: "k-mono-input",
											maxlength: "1000",
											spellcheck: "false",
											invalid,
											"onUpdate:modelValue": (v) => def.value.redirect = v.trim() || null
										}, null, 8, [
											"id",
											"model-value",
											"invalid",
											"onUpdate:modelValue"
										])]),
										_: 1
									}, 8, [
										"label",
										"help",
										"error"
									]),
									createVNode(unref(KField), { label: unref(__)("When the form is closed") }, {
										default: withCtx(({ id: fid }) => [createVNode(unref(KInput), {
											id: fid,
											modelValue: def.value.closed_message,
											"onUpdate:modelValue": ($event) => def.value.closed_message = $event,
											maxlength: "500"
										}, null, 8, [
											"id",
											"modelValue",
											"onUpdate:modelValue"
										])]),
										_: 1
									}, 8, ["label"])
								]),
								_: 1
							}, 8, ["title"]),
							createVNode(unref(KCard), { title: unref(__)("Email") }, {
								default: withCtx(() => [
									!def.value.notifications.length ? (openBlock(), createBlock("p", {
										key: 0,
										class: "k-muted k-small k-ff-note"
									}, toDisplayString(unref(__)("Nobody is emailed. Submissions wait on the Submissions screen.")), 1)) : createCommentVNode("v-if", true),
									(openBlock(true), createBlock(Fragment, null, renderList(def.value.notifications, (n, i) => {
										return openBlock(), createBlock("div", {
											key: i,
											class: "k-ff-mail"
										}, [
											createVNode(unref(KField), {
												label: unref(__)("Send to"),
												help: unref(__)("One or more addresses, separated by commas."),
												error: err(`notifications.${i}.to`)
											}, {
												default: withCtx(({ id: fid, invalid }) => [createVNode(unref(KInput), {
													id: fid,
													modelValue: n.to,
													"onUpdate:modelValue": ($event) => n.to = $event,
													type: "text",
													autocomplete: "off",
													spellcheck: "false",
													invalid
												}, null, 8, [
													"id",
													"modelValue",
													"onUpdate:modelValue",
													"invalid"
												])]),
												_: 2
											}, 1032, [
												"label",
												"help",
												"error"
											]),
											createVNode(unref(KField), {
												label: unref(__)("Subject"),
												help: unref(__)("Empty: New submission: {name}", { name: form.value.title })
											}, {
												default: withCtx(({ id: fid }) => [createVNode(unref(KInput), {
													id: fid,
													modelValue: n.subject,
													"onUpdate:modelValue": ($event) => n.subject = $event,
													maxlength: "200"
												}, null, 8, [
													"id",
													"modelValue",
													"onUpdate:modelValue"
												])]),
												_: 2
											}, 1032, ["label", "help"]),
											emailFields.value.length ? (openBlock(), createBlock(unref(KField), {
												key: 0,
												label: unref(__)("Replies go to"),
												error: err(`notifications.${i}.reply_to`)
											}, {
												default: withCtx(({ id: fid, invalid }) => [createVNode(unref(KSelect), {
													id: fid,
													modelValue: n.reply_to,
													"onUpdate:modelValue": ($event) => n.reply_to = $event,
													invalid,
													options: [{
														value: "",
														label: unref(__)("The site's address")
													}, ...emailFields.value.map((f) => ({
														value: f.name,
														label: unref(__)("The address in {field}", { field: f.label || f.name })
													}))]
												}, null, 8, [
													"id",
													"modelValue",
													"onUpdate:modelValue",
													"invalid",
													"options"
												])]),
												_: 2
											}, 1032, ["label", "error"])) : createCommentVNode("v-if", true),
											createVNode(unref(KButton), {
												size: "sm",
												variant: "ghost",
												icon: "trash",
												onClick: ($event) => def.value.notifications.splice(i, 1)
											}, {
												default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Stop this email")), 1)]),
												_: 1
											}, 8, ["onClick"])
										]);
									}), 128)),
									def.value.notifications.length < 5 ? (openBlock(), createBlock(unref(KButton), {
										key: 1,
										size: "sm",
										icon: "plus",
										onClick: ($event) => def.value.notifications.push({
											to: "",
											subject: "",
											reply_to: emailFields.value[0]?.name ?? ""
										})
									}, {
										default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Email each submission")), 1)]),
										_: 1
									}, 8, ["onClick"])) : createCommentVNode("v-if", true)
								]),
								_: 1
							}, 8, ["title"]),
							createVNode(unref(KCard), { title: unref(__)("Keeping and spam") }, {
								default: withCtx(() => [
									createVNode("div", { class: "k-ff-switches" }, [createVNode(unref(KSwitch), {
										modelValue: def.value.store,
										"onUpdate:modelValue": ($event) => def.value.store = $event,
										label: unref(__)("Keep submissions here")
									}, null, 8, [
										"modelValue",
										"onUpdate:modelValue",
										"label"
									]), err("store") ? (openBlock(), createBlock("p", {
										key: 0,
										class: "k-field__error",
										role: "alert"
									}, toDisplayString(err("store")), 1)) : createCommentVNode("v-if", true)]),
									createVNode("div", { class: "k-ff-pair" }, [def.value.store ? (openBlock(), createBlock(unref(KField), {
										key: 0,
										label: unref(__)("Delete after (days)"),
										help: unref(__)("0 keeps them until you delete them.")
									}, {
										default: withCtx(({ id: fid }) => [createVNode(unref(KInput), {
											id: fid,
											modelValue: def.value.retention_days,
											"onUpdate:modelValue": ($event) => def.value.retention_days = $event,
											modelModifiers: { number: true },
											type: "number",
											min: "0",
											max: "3650"
										}, null, 8, [
											"id",
											"modelValue",
											"onUpdate:modelValue"
										])]),
										_: 1
									}, 8, ["label", "help"])) : createCommentVNode("v-if", true), createVNode(unref(KField), {
										label: unref(__)("Per visitor and hour"),
										help: unref(__)("More are refused.")
									}, {
										default: withCtx(({ id: fid }) => [createVNode(unref(KInput), {
											id: fid,
											modelValue: def.value.per_ip_hour,
											"onUpdate:modelValue": ($event) => def.value.per_ip_hour = $event,
											modelModifiers: { number: true },
											type: "number",
											min: "1",
											max: "1000"
										}, null, 8, [
											"id",
											"modelValue",
											"onUpdate:modelValue"
										])]),
										_: 1
									}, 8, ["label", "help"])]),
									createVNode(unref(KSwitch), {
										modelValue: def.value.captcha,
										"onUpdate:modelValue": ($event) => def.value.captcha = $event,
										disabled: !meta.value.captcha && !def.value.captcha,
										label: unref(__)("Ask for a captcha")
									}, null, 8, [
										"modelValue",
										"onUpdate:modelValue",
										"disabled",
										"label"
									]),
									createVNode("p", { class: "k-muted k-small k-ff-note" }, toDisplayString(meta.value.captcha ? unref(__)("Shown by the captcha plugin you installed.") : unref(__)("Needs a captcha plugin. A hidden trap field, a minimum fill time and the limit above already stop most spam.")), 1),
									err("captcha") ? (openBlock(), createBlock("p", {
										key: 0,
										class: "k-field__error",
										role: "alert"
									}, toDisplayString(err("captcha")), 1)) : createCommentVNode("v-if", true)
								]),
								_: 1
							}, 8, ["title"]),
							createVNode("div", { class: "k-editor__trash" }, [createVNode(unref(KButton), {
								variant: "ghost",
								icon: "trash",
								onClick: remove
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Delete this form")), 1)]),
								_: 1
							})])
						];
					}),
					_: 1
				}, _parent));
				_push(`</form>`);
			} else _push(`<!---->`);
			_push(`<!--]-->`);
		};
	}
});
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/admin/forms/Edit.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
var Edit_default = /*#__PURE__*/ _plugin_vue_export_helper_default(_sfc_main, [["__scopeId", "data-v-978749f7"]]);
export { Edit_default as default };
