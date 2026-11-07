import { Fragment, computed, createBlock, createCommentVNode, createTextVNode, createVNode, mergeModels, mergeProps, nextTick, openBlock, provide, reactive, ref, renderList, shallowRef, toDisplayString, unref, useModel, useSSRContext, vModelText, withCtx, withDirectives, withKeys, withModifiers } from "vue";
import { ssrInterpolate, ssrRenderAttr, ssrRenderAttrs, ssrRenderComponent, ssrRenderList } from "vue/server-renderer";
import { __, useApi } from "@kestello/sdk";
import { KBlockList, KButton, KIcon, KModal, KSpinner } from "@kestello/ui";
// core/resources/js/pages/fields/Blocks.vue
var catalogRequest = null;
var patternsRequest = null;
var loadedAt = 0;
var _sfc_main = {
	__name: "Blocks",
	__ssrInlineRender: true,
	props: /*@__PURE__*/ mergeModels({
		field: {
			type: Object,
			required: true
		},
		id: {
			type: String,
			default: ""
		},
		invalid: Boolean,
		disabled: Boolean,
		errors: {
			type: Object,
			default: () => ({})
		},
		path: {
			type: String,
			default: ""
		}
	}, {
		"modelValue": { default: null },
		"modelModifiers": {}
	}),
	emits: ["update:modelValue"],
	setup(__props) {
		const model = useModel(__props, "modelValue");
		const props = __props;
		const api = useApi();
		const catalog = shallowRef(/* @__PURE__ */ new Map());
		const definitions = shallowRef([]);
		const bindings = shallowRef([]);
		const patterns = shallowRef([]);
		const loading = ref(true);
		const failed = ref(false);
		async function load() {
			loading.value = true;
			failed.value = false;
			try {
				if (Date.now() - loadedAt > 6e4) {
					catalogRequest = null;
					patternsRequest = null;
					loadedAt = Date.now();
				}
				catalogRequest ??= api.get("/blocks").then((r) => r.data);
				const data = await catalogRequest;
				definitions.value = data.blocks;
				catalog.value = new Map(data.blocks.map((b) => [b.name, b]));
				bindings.value = data.bindings;
				patternsRequest ??= api.get("/design/patterns").then((r) => r.data.patterns).catch(() => []);
				patterns.value = await patternsRequest;
			} catch (e) {
				catalogRequest = null;
				failed.value = true;
			} finally {
				loading.value = false;
			}
		}
		load();
		let upgraded = null;
		const tree = computed({
			get: () => {
				if (Array.isArray(model.value)) return model.value;
				if (typeof model.value === "string" && model.value.trim() !== "") {
					if (upgraded?.html !== model.value) upgraded = {
						html: model.value,
						tree: [{
							type: "core/text",
							id: newId(),
							props: { text: model.value },
							children: []
						}]
					};
					return upgraded.tree;
				}
				return [];
			},
			set: (value) => {
				model.value = value.length ? value : null;
			}
		});
		const messages = computed(() => {
			const e = props.errors[props.path];
			return Array.isArray(e) ? e : e ? [e] : [];
		});
		const opened = reactive(/* @__PURE__ */ new Set());
		const newId = () => Array.from(crypto.getRandomValues(/* @__PURE__ */ new Uint8Array(4)), (b) => b.toString(16).padStart(2, "0")).join("");
		function reid(node) {
			return {
				...node,
				id: newId(),
				children: (node.children ?? []).map(reid)
			};
		}
		function create(def) {
			const values = Object.fromEntries(def.fields.filter((f) => f.default !== null && f.default !== void 0).map((f) => [f.name, f.default]));
			for (const f of def.fields) if (f.type === "select" && f.required && !(f.name in values) && f.options.choices?.length) values[f.name] = f.options.choices[0].value;
			const node = {
				type: def.name,
				id: newId(),
				props: values,
				children: []
			};
			if (Array.isArray(def.children) && def.children.length === 1 && catalog.value.has(def.children[0])) {
				const child = catalog.value.get(def.children[0]);
				const count = def.name === "core/columns" ? String(values.layout ?? "1-1").split("-").length : 2;
				node.children = Array.from({ length: count }, () => create(child));
			}
			return node;
		}
		const bindingLabel = (path) => {
			const exact = bindings.value.find((b) => b.path === path);
			if (exact) return `${path.startsWith("site.") ? __("Site") : __("Entry")}: ${exact.label}`;
			if (path.startsWith("entry.fields.")) return `${__("Entry")}: ${path.slice(13) || __("a field")}`;
			return path;
		};
		const inserter = reactive({
			open: false,
			query: "",
			parent: null,
			allowed: null,
			where: "",
			done: null
		});
		const search = ref(null);
		function insert(context, done) {
			Object.assign(inserter, {
				open: true,
				query: "",
				parent: context.parent,
				allowed: context.allowed,
				where: context.where,
				done
			});
			nextTick(() => search.value?.focus());
		}
		const TEMPLATE_ONLY = ["core/entry-content", "core/template-part"];
		const hidden = computed(() => {
			const ctx = props.field.options?.context;
			if (ctx === "template") return [];
			if (ctx === "part") return ["core/template-part"];
			if (ctx === "pattern") return [...TEMPLATE_ONLY, "core/pattern"];
			return TEMPLATE_ONLY;
		});
		function fits(def, parent, allowed) {
			if (hidden.value.includes(def.name)) return false;
			if (parent === null) return allowed ? allowed.includes(def.name) : def.parents === null;
			return (parent.children === true || Array.isArray(parent.children) && parent.children.includes(def.name)) && (def.parents === null || def.parents.includes(parent.name));
		}
		const CATEGORIES = [
			["text", __("Text")],
			["media", __("Media")],
			["layout", __("Layout")],
			["data", __("Entries and data")],
			["widgets", __("Widgets")]
		];
		const choices = computed(() => {
			const q = inserter.query.trim().toLowerCase();
			const allowed = inserter.parent ? null : inserter.allowed ?? props.field.options.allowed ?? null;
			const usable = definitions.value.filter((d) => fits(d, inserter.parent, allowed) && (!q || `${d.label} ${d.description} ${d.name}`.toLowerCase().includes(q)));
			return CATEGORIES.map(([key, label]) => ({
				key,
				label,
				blocks: usable.filter((d) => d.category === key)
			})).filter((g) => g.blocks.length);
		});
		const patternChoices = computed(() => {
			const q = inserter.query.trim().toLowerCase();
			const allowed = inserter.parent ? null : inserter.allowed ?? props.field.options.allowed ?? null;
			const synced = catalog.value.get("core/pattern");
			const group = catalog.value.get("core/group");
			return patterns.value.filter((p) => {
				if (q && !`${p.title} ${p.description} ${p.category}`.toLowerCase().includes(q)) return false;
				if (p.synced) return !!synced && fits(synced, inserter.parent, allowed);
				const tops = p.blocks.map((b) => catalog.value.get(b.type)).filter(Boolean);
				if (!tops.length) return false;
				return tops.length === 1 ? fits(tops[0], inserter.parent, allowed) : !!group && fits(group, inserter.parent, allowed);
			});
		});
		const count = computed(() => choices.value.reduce((n, g) => n + g.blocks.length, 0) + patternChoices.value.length);
		function pickPattern(p) {
			let node;
			if (p.synced) node = {
				type: "core/pattern",
				id: newId(),
				props: { pattern: p.key },
				children: []
			};
			else {
				const copies = p.blocks.filter((b) => catalog.value.has(b.type)).map((b) => reid(JSON.parse(JSON.stringify(b))));
				node = copies.length === 1 ? copies[0] : {
					...create(catalog.value.get("core/group")),
					children: copies
				};
			}
			inserter.done?.(node);
			inserter.open = false;
		}
		function pick(def) {
			const node = create(def);
			inserter.done?.(node);
			inserter.open = false;
		}
		function pickFirst() {
			const first = choices.value[0]?.blocks[0];
			if (first) pick(first);
		}
		provide("kestello.blocks", {
			catalog,
			bindings,
			insert,
			reid,
			bindingLabel,
			isOpen: (id) => opened.has(id),
			open: (id) => opened.add(id),
			toggle: (id) => opened.has(id) ? opened.delete(id) : opened.add(id)
		});
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<div${ssrRenderAttrs(mergeProps({
				id: __props.id,
				class: ["k-blocks-ed", { "is-invalid": __props.invalid }]
			}, _attrs))}>`);
			if (messages.value.length) {
				_push(`<div class="k-blocks-ed__errors" role="group"${ssrRenderAttr("aria-label", unref(__)("Problems with these blocks"))}><p class="k-blocks-ed__errors-title">`);
				_push(ssrRenderComponent(unref(KIcon), { name: "warning-circle" }, null, _parent));
				_push(`${ssrInterpolate(messages.value.length === 1 ? unref(__)("One block needs attention") : unref(__)("{n} problems need attention", { n: messages.value.length }))}</p><ul><!--[-->`);
				ssrRenderList(messages.value, (m, i) => {
					_push(`<li>${ssrInterpolate(m)}</li>`);
				});
				_push(`<!--]--></ul></div>`);
			} else _push(`<!---->`);
			if (loading.value) {
				_push(`<p class="k-blocks-ed__state">`);
				_push(ssrRenderComponent(unref(KSpinner), null, null, _parent));
				_push(`${ssrInterpolate(unref(__)("Loading blocks…"))}</p>`);
			} else if (failed.value) {
				_push(`<p class="k-blocks-ed__state">${ssrInterpolate(unref(__)("The block list could not be loaded."))} `);
				_push(ssrRenderComponent(unref(KButton), {
					size: "sm",
					variant: "ghost",
					onClick: load
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`${ssrInterpolate(unref(__)("Try again"))}`);
						else return [createTextVNode(toDisplayString(unref(__)("Try again")), 1)];
					}),
					_: 1
				}, _parent));
				_push(`</p>`);
			} else _push(ssrRenderComponent(unref(KBlockList), {
				modelValue: tree.value,
				"onUpdate:modelValue": ($event) => tree.value = $event,
				allowed: __props.field.options.allowed ?? null,
				disabled: __props.disabled
			}, null, _parent));
			_push(ssrRenderComponent(unref(KModal), {
				open: inserter.open,
				"onUpdate:open": ($event) => inserter.open = $event,
				title: inserter.parent ? unref(__)("Add to {where}", { where: inserter.where || inserter.parent.label }) : unref(__)("Add a block"),
				size: "lg"
			}, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(`<div class="k-blocks-ins"${_scopeId}><label class="k-field__label" for="k-blocks-ins-search"${_scopeId}>${ssrInterpolate(unref(__)("Search blocks"))}</label><input id="k-blocks-ins-search"${ssrRenderAttr("value", inserter.query)} type="search" autofocus class="k-input k-blocks-ins__search" aria-controls="k-blocks-ins-results"${_scopeId}><div id="k-blocks-ins-results" class="k-blocks-ins__results" aria-live="polite"${_scopeId}>`);
						if (!count.value) _push(`<p class="k-blocks-ins__none"${_scopeId}>${ssrInterpolate(unref(__)("No block matches “{q}”.", { q: inserter.query }))}</p>`);
						else _push(`<!---->`);
						if (patternChoices.value.length) {
							_push(`<section class="k-blocks-ins__group"${_scopeId}><h3 class="k-blocks-ins__heading"${_scopeId}>${ssrInterpolate(unref(__)("Patterns"))}</h3><ul class="k-blocks-ins__grid"${_scopeId}><!--[-->`);
							ssrRenderList(patternChoices.value, (p) => {
								_push(`<li${_scopeId}><button type="button" class="k-blocks-ins__choice"${_scopeId}>`);
								_push(ssrRenderComponent(unref(KIcon), {
									name: p.synced ? "star" : "bookmark",
									class: "k-blocks-ins__icon"
								}, null, _parent, _scopeId));
								_push(`<span class="k-blocks-ins__name"${_scopeId}>${ssrInterpolate(p.title)}</span><span class="k-blocks-ins__desc"${_scopeId}>${ssrInterpolate(p.synced ? unref(__)("Synced: changes everywhere it is used.") : p.description || p.category)}</span></button></li>`);
							});
							_push(`<!--]--></ul></section>`);
						} else _push(`<!---->`);
						_push(`<!--[-->`);
						ssrRenderList(choices.value, (group) => {
							_push(`<section class="k-blocks-ins__group"${_scopeId}><h3 class="k-blocks-ins__heading"${_scopeId}>${ssrInterpolate(group.label)}</h3><ul class="k-blocks-ins__grid"${_scopeId}><!--[-->`);
							ssrRenderList(group.blocks, (def) => {
								_push(`<li${_scopeId}><button type="button" class="k-blocks-ins__choice"${_scopeId}>`);
								_push(ssrRenderComponent(unref(KIcon), {
									name: def.icon,
									class: "k-blocks-ins__icon"
								}, null, _parent, _scopeId));
								_push(`<span class="k-blocks-ins__name"${_scopeId}>${ssrInterpolate(def.label)}</span>`);
								if (def.description) _push(`<span class="k-blocks-ins__desc"${_scopeId}>${ssrInterpolate(def.description)}</span>`);
								else _push(`<!---->`);
								_push(`</button></li>`);
							});
							_push(`<!--]--></ul></section>`);
						});
						_push(`<!--]--></div></div>`);
					} else return [createVNode("div", { class: "k-blocks-ins" }, [
						createVNode("label", {
							class: "k-field__label",
							for: "k-blocks-ins-search"
						}, toDisplayString(unref(__)("Search blocks")), 1),
						withDirectives(createVNode("input", {
							id: "k-blocks-ins-search",
							ref_key: "search",
							ref: search,
							"onUpdate:modelValue": ($event) => inserter.query = $event,
							type: "search",
							autofocus: "",
							class: "k-input k-blocks-ins__search",
							"aria-controls": "k-blocks-ins-results",
							onKeydown: withKeys(withModifiers(pickFirst, ["prevent"]), ["enter"])
						}, null, 40, ["onUpdate:modelValue", "onKeydown"]), [[vModelText, inserter.query]]),
						createVNode("div", {
							id: "k-blocks-ins-results",
							class: "k-blocks-ins__results",
							"aria-live": "polite"
						}, [
							!count.value ? (openBlock(), createBlock("p", {
								key: 0,
								class: "k-blocks-ins__none"
							}, toDisplayString(unref(__)("No block matches “{q}”.", { q: inserter.query })), 1)) : createCommentVNode("v-if", true),
							patternChoices.value.length ? (openBlock(), createBlock("section", {
								key: 1,
								class: "k-blocks-ins__group"
							}, [createVNode("h3", { class: "k-blocks-ins__heading" }, toDisplayString(unref(__)("Patterns")), 1), createVNode("ul", { class: "k-blocks-ins__grid" }, [(openBlock(true), createBlock(Fragment, null, renderList(patternChoices.value, (p) => {
								return openBlock(), createBlock("li", { key: p.key }, [createVNode("button", {
									type: "button",
									class: "k-blocks-ins__choice",
									onClick: ($event) => pickPattern(p)
								}, [
									createVNode(unref(KIcon), {
										name: p.synced ? "star" : "bookmark",
										class: "k-blocks-ins__icon"
									}, null, 8, ["name"]),
									createVNode("span", { class: "k-blocks-ins__name" }, toDisplayString(p.title), 1),
									createVNode("span", { class: "k-blocks-ins__desc" }, toDisplayString(p.synced ? unref(__)("Synced: changes everywhere it is used.") : p.description || p.category), 1)
								], 8, ["onClick"])]);
							}), 128))])])) : createCommentVNode("v-if", true),
							(openBlock(true), createBlock(Fragment, null, renderList(choices.value, (group) => {
								return openBlock(), createBlock("section", {
									key: group.key,
									class: "k-blocks-ins__group"
								}, [createVNode("h3", { class: "k-blocks-ins__heading" }, toDisplayString(group.label), 1), createVNode("ul", { class: "k-blocks-ins__grid" }, [(openBlock(true), createBlock(Fragment, null, renderList(group.blocks, (def) => {
									return openBlock(), createBlock("li", { key: def.name }, [createVNode("button", {
										type: "button",
										class: "k-blocks-ins__choice",
										onClick: ($event) => pick(def)
									}, [
										createVNode(unref(KIcon), {
											name: def.icon,
											class: "k-blocks-ins__icon"
										}, null, 8, ["name"]),
										createVNode("span", { class: "k-blocks-ins__name" }, toDisplayString(def.label), 1),
										def.description ? (openBlock(), createBlock("span", {
											key: 0,
											class: "k-blocks-ins__desc"
										}, toDisplayString(def.description), 1)) : createCommentVNode("v-if", true)
									], 8, ["onClick"])]);
								}), 128))])]);
							}), 128))
						])
					])];
				}),
				_: 1
			}, _parent));
			_push(`</div>`);
		};
	}
};
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/fields/Blocks.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export { _sfc_main as default };
