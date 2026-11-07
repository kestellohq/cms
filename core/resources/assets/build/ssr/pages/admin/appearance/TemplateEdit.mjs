import { t as _plugin_vue_export_helper_default } from "../../../chunks/_plugin-vue_export-helper-DjkACiAr.mjs";
import { Fragment, computed, createBlock, createCommentVNode, createTextVNode, createVNode, openBlock, ref, renderList, toDisplayString, unref, useSSRContext, withCtx, withModifiers } from "vue";
import { ssrInterpolate, ssrRenderAttr, ssrRenderClass, ssrRenderComponent, ssrRenderList } from "vue/server-renderer";
import { Head, Link, usePage, useRouter } from "@kestello/app";
import { ApiError, __, confirm, notify, useApi, useLeaveGuard } from "@kestello/sdk";
import { KAlert, KBadge, KBuilder, KButton, KField, KIcon, KInput, KSelect, KSwitch, KTime } from "@kestello/ui";
// core/resources/js/pages/admin/appearance/TemplateEdit.vue
var _sfc_main = /*@__PURE__*/ Object.assign({ layout: "Admin" }, {
	__name: "TemplateEdit",
	__ssrInlineRender: true,
	setup(__props) {
		/**
		* One template, part or pattern: its blocks and settings. Save keeps a draft
		* that only people who design can preview (?design=draft); Publish puts it
		* live. Every published version stays in the history.
		*/
		const api = useApi();
		const page = usePage();
		const router = useRouter();
		const kind = computed(() => String(page.props.params?.kind ?? ""));
		const key = computed(() => String(page.props.params?.key ?? ""));
		const KIND = {
			template: {
				one: __("Template"),
				back: __("Templates")
			},
			part: {
				one: __("Part"),
				back: __("Parts")
			},
			pattern: {
				one: __("Pattern"),
				back: __("Patterns")
			}
		};
		const doc = ref(null);
		const lists = ref(null);
		const loadError = ref(null);
		const form = ref(null);
		const errors = ref({});
		const busy = ref("");
		const FIELDS = [
			"title",
			"description",
			"blocks",
			"layout",
			"for",
			"area",
			"category",
			"synced"
		];
		const pick = (d) => Object.fromEntries(FIELDS.filter((f) => d && f in d).map((f) => [f, JSON.parse(JSON.stringify(d[f] ?? null))]));
		const working = computed(() => doc.value?.draft ?? doc.value?.published ?? null);
		function adopt(d) {
			doc.value = d;
			form.value = {
				blocks: [],
				...pick(d.draft ?? d.published)
			};
			errors.value = {};
		}
		async function load() {
			try {
				const [one, all] = await Promise.all([api.get(`/design/templates/${kind.value}/${key.value}`), lists.value ? null : api.get("/design/templates")]);
				if (all) lists.value = all.data;
				adopt(one.data);
			} catch (e) {
				loadError.value = e;
			}
		}
		load();
		const dirty = computed(() => !!form.value && JSON.stringify(form.value) !== JSON.stringify({
			blocks: [],
			...pick(working.value)
		}));
		const isLive = computed(() => !!doc.value?.published);
		const reserved = computed(() => key.value === "entry" || key.value.startsWith("entry-"));
		const typeNames = computed(() => Object.fromEntries((lists.value?.types ?? []).map((t) => [t.name, t.plural])));
		const purpose = computed(() => {
			if (kind.value === "template") {
				if (key.value === "entry") return __("Lays out every entry that has no template of its own.");
				if (key.value.startsWith("entry-")) return __("Lays out every entry of {type}.", { type: typeNames.value[key.value.slice(6)] ?? key.value.slice(6) });
				return __("Entries choose it under Details → Template.");
			}
			if (kind.value === "part") return __("Place it in templates with the Template part block.");
			return form.value?.synced ? __("Synced: pages that use it show these blocks and change with it.") : __("Offered in the block inserter. Each page gets its own copy.");
		});
		const layoutOptions = [{
			value: "site",
			label: __("Inside the theme's header and footer")
		}, {
			value: "bare",
			label: __("On its own (use parts for header and footer)")
		}];
		const areaOptions = computed(() => Object.entries(lists.value?.areas ?? {}).map(([value, label]) => ({
			value,
			label
		})));
		function toggleType(name, on) {
			const now = new Set(form.value.for ?? []);
			on ? now.add(name) : now.delete(name);
			form.value.for = now.size ? [...now] : null;
		}
		const historyKey = computed(() => `${doc.value?.info?.revision ?? 0}:${doc.value?.draft_info?.revision ?? 0}:${doc.value?.draft ? "d" : "p"}`);
		function payload(publish) {
			const out = {
				...form.value,
				publish
			};
			if (kind.value !== "template") {
				delete out.layout;
				delete out.for;
			}
			if (kind.value !== "part") delete out.area;
			if (kind.value !== "pattern") {
				delete out.category;
				delete out.synced;
			}
			return out;
		}
		async function run(name, request, done) {
			busy.value = name;
			errors.value = {};
			try {
				const body = (await request()).data;
				if (body.deleted) {
					router.visit(`/admin/appearance/templates?kind=${kind.value}`, { discardUnsaved: true });
					return;
				}
				adopt(body);
				if (done) notify.success(done);
			} catch (e) {
				if (e instanceof ApiError && e.status === 422) {
					errors.value = e.fieldErrors();
					notify.error(__("Some blocks need attention before this can be saved."));
				} else notify.error(e);
			} finally {
				busy.value = "";
			}
		}
		const save = () => run("save", () => api.put(`/design/templates/${kind.value}/${key.value}`, payload(false)), __("Draft saved. Visitors still see the published version."));
		const publish = () => dirty.value ? run("publish", () => api.put(`/design/templates/${kind.value}/${key.value}`, payload(true)), __("Published.")) : run("publish", () => api.post(`/design/templates/${kind.value}/${key.value}/publish`), __("Published."));
		async function discard() {
			const gone = !isLive.value;
			if (!await confirm({
				title: gone ? __("Delete this draft?") : __("Discard the draft?"),
				message: gone ? __("It was never published, so nothing will be left.") : __("The published version stays as it is."),
				confirmLabel: gone ? __("Delete") : __("Discard"),
				danger: true
			})) return;
			run("discard", () => api.delete(`/design/templates/${kind.value}/${key.value}/draft`), gone ? null : __("Draft discarded."));
		}
		async function reset() {
			const file = doc.value.file;
			if (!await confirm({
				title: file ? __("Go back to the theme's version?") : __("Delete this {kind}?", { kind: KIND[kind.value].one.toLowerCase() }),
				message: file ? __("The site's changes and any draft go. The current version stays in the history.") : __("The last version stays in the history and can be restored from another one of its versions."),
				confirmLabel: file ? __("Use the theme's version") : __("Delete"),
				danger: true
			})) return;
			run("reset", () => api.delete(`/design/templates/${kind.value}/${key.value}`), file ? __("Back to the theme's version.") : null);
		}
		async function restore(rev) {
			if (dirty.value && !await confirm({
				title: __("Restore this version?"),
				message: __("Your unsaved changes will be lost."),
				confirmLabel: __("Restore")
			})) return;
			run("restore", () => api.post(`/design/templates/revisions/${rev.id}/restore`), __("Restored and published."));
		}
		function flat(nodes, out = /* @__PURE__ */ new Map(), depth = 0) {
			for (const n of nodes ?? []) {
				const { children, ...own } = n;
				out.set(n.id, {
					node: n,
					own: JSON.stringify(own),
					depth
				});
				flat(children, out, depth + 1);
			}
			return out;
		}
		const blockName = (n) => {
			const p = n.props ?? {};
			const text = typeof p.text === "string" ? p.text.replace(/<[^>]+>/g, "").trim() : typeof p.part === "string" ? p.part : "";
			const type = n.type.split("/").pop().replace(/-/g, " ");
			return text ? `${type}: ${text.length > 48 ? text.slice(0, 47) + "…" : text}` : type;
		};
		const themeDiff = computed(() => {
			if (!doc.value?.theme_update || !doc.value.theme_version) return null;
			const mine = flat(doc.value.published?.blocks);
			const theirs = flat(doc.value.theme_version.blocks);
			const rows = [];
			for (const [id, t] of theirs) {
				const m = mine.get(id);
				if (!m) rows.push({
					id,
					change: "added",
					label: blockName(t.node)
				});
				else if (m.own !== t.own) rows.push({
					id,
					change: "changed",
					label: blockName(t.node)
				});
			}
			for (const [id, m] of mine) if (!theirs.has(id)) rows.push({
				id,
				change: "removed",
				label: blockName(m.node)
			});
			return {
				rows,
				settings: [
					"title",
					"layout",
					"area",
					"category",
					"synced",
					"for"
				].filter((f) => JSON.stringify(doc.value.published?.[f] ?? null) !== JSON.stringify(doc.value.theme_version[f] ?? null))
			};
		});
		const CHANGE = {
			added: __("New in the theme"),
			changed: __("Changed by the theme"),
			removed: __("Only in yours")
		};
		const showDiff = ref(false);
		async function resolveUpdate(action) {
			if (dirty.value && !await confirm({
				title: __("Leave your unsaved changes?"),
				message: __("They will be lost."),
				confirmLabel: __("Continue"),
				danger: true
			})) return;
			if (action === "theirs" && !await confirm({
				title: __("Use the theme's new version?"),
				message: __("This site's changes are replaced. They stay in the history."),
				confirmLabel: __("Use the theme's version"),
				danger: true
			})) return;
			const done = {
				keep: __("Kept your version."),
				theirs: __("Now using the theme's version."),
				draft: __("The theme's version is open as a draft. Yours stays live until you publish.")
			}[action];
			run(`update:${action}`, () => api.post(`/design/templates/${kind.value}/${key.value}/theme-update`, { action }), done);
		}
		function revert() {
			form.value = {
				blocks: [],
				...pick(working.value)
			};
			errors.value = {};
		}
		const previewPath = ref("/");
		const previewUrl = computed(() => {
			const p = previewPath.value.startsWith("/") && !previewPath.value.startsWith("//") ? previewPath.value : "/";
			return `${p}${p.includes("?") ? "&" : "?"}design=draft`;
		});
		useLeaveGuard(dirty);
		const errorList = computed(() => Object.entries(errors.value).filter(([k]) => !k.startsWith("blocks")).flatMap(([, v]) => Array.isArray(v) ? v : [v]));
		const fieldError = (name) => {
			const e = errors.value[name];
			return Array.isArray(e) ? e[0] : e ?? "";
		};
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<!--[-->`);
			_push(ssrRenderComponent(unref(Head), { title: doc.value ? `${form.value.title || key.value} · ${KIND[kind.value]?.one}` : unref(__)("Template") }, null, _parent));
			if (loadError.value) _push(ssrRenderComponent(unref(KAlert), { tone: "danger" }, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(`${ssrInterpolate(loadError.value.message)}`);
					else return [createTextVNode(toDisplayString(loadError.value.message), 1)];
				}),
				_: 1
			}, _parent));
			else _push(`<!---->`);
			if (doc.value && form.value) {
				_push(`<div class="k-tpl-ed" data-v-1d8c222d><header class="k-tpl-ed__head" data-v-1d8c222d><div data-v-1d8c222d>`);
				_push(ssrRenderComponent(unref(Link), {
					href: `/admin/appearance/templates?kind=${kind.value}`,
					class: "k-tpl-ed__back k-small"
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) {
							_push(ssrRenderComponent(unref(KIcon), { name: "arrow-left" }, null, _parent, _scopeId));
							_push(`${ssrInterpolate(KIND[kind.value].back)}`);
						} else return [createVNode(unref(KIcon), { name: "arrow-left" }), createTextVNode(toDisplayString(KIND[kind.value].back), 1)];
					}),
					_: 1
				}, _parent));
				_push(`<h1 class="k-tpl-ed__title" data-v-1d8c222d>${ssrInterpolate(form.value.title || key.value)}</h1><p class="k-muted k-small k-tpl-ed__sub" data-v-1d8c222d><span class="k-mono" data-v-1d8c222d>${ssrInterpolate(key.value)}</span> · ${ssrInterpolate(purpose.value)}</p></div><div class="k-tpl-ed__actions" data-v-1d8c222d>`);
				if (dirty.value) _push(ssrRenderComponent(unref(KBadge), { tone: "warning" }, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`${ssrInterpolate(unref(__)("Unsaved"))}`);
						else return [createTextVNode(toDisplayString(unref(__)("Unsaved")), 1)];
					}),
					_: 1
				}, _parent));
				else if (doc.value.draft) _push(ssrRenderComponent(unref(KBadge), { tone: "info" }, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`${ssrInterpolate(isLive.value ? unref(__)("Draft saved, not published") : unref(__)("Draft, never published"))}`);
						else return [createTextVNode(toDisplayString(isLive.value ? unref(__)("Draft saved, not published") : unref(__)("Draft, never published")), 1)];
					}),
					_: 1
				}, _parent));
				else _push(`<!---->`);
				if (dirty.value) _push(ssrRenderComponent(unref(KButton), {
					variant: "ghost",
					onClick: revert
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`${ssrInterpolate(unref(__)("Undo changes"))}`);
						else return [createTextVNode(toDisplayString(unref(__)("Undo changes")), 1)];
					}),
					_: 1
				}, _parent));
				else _push(`<!---->`);
				_push(ssrRenderComponent(unref(KButton), {
					icon: "floppy-disk",
					loading: busy.value === "save",
					disabled: !dirty.value || !!busy.value,
					onClick: save
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`${ssrInterpolate(unref(__)("Save draft"))}`);
						else return [createTextVNode(toDisplayString(unref(__)("Save draft")), 1)];
					}),
					_: 1
				}, _parent));
				_push(ssrRenderComponent(unref(KButton), {
					variant: "primary",
					icon: "paper-plane-tilt",
					loading: busy.value === "publish",
					disabled: !dirty.value && !doc.value.draft || !!busy.value,
					onClick: publish
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`${ssrInterpolate(unref(__)("Publish"))}`);
						else return [createTextVNode(toDisplayString(unref(__)("Publish")), 1)];
					}),
					_: 1
				}, _parent));
				_push(`</div></header>`);
				if (themeDiff.value) _push(ssrRenderComponent(unref(KAlert), {
					tone: "info",
					title: unref(__)("The theme has a new version of this {kind}", { kind: KIND[kind.value].one.toLowerCase() })
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) {
							_push(`<p class="k-tpl-ed__update" data-v-1d8c222d${_scopeId}>${ssrInterpolate(unref(__)("This site changed it before the theme was updated. Keep yours, take the theme's, or open the theme's as a draft and bring your changes over."))}</p><div class="k-tpl-ed__update-actions" data-v-1d8c222d${_scopeId}>`);
							_push(ssrRenderComponent(unref(KButton), {
								size: "sm",
								loading: busy.value === "update:keep",
								disabled: !!busy.value,
								onClick: ($event) => resolveUpdate("keep")
							}, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate(unref(__)("Keep mine"))}`);
									else return [createTextVNode(toDisplayString(unref(__)("Keep mine")), 1)];
								}),
								_: 1
							}, _parent, _scopeId));
							_push(ssrRenderComponent(unref(KButton), {
								size: "sm",
								loading: busy.value === "update:draft",
								disabled: !!busy.value,
								onClick: ($event) => resolveUpdate("draft")
							}, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate(unref(__)("Open theirs as a draft"))}`);
									else return [createTextVNode(toDisplayString(unref(__)("Open theirs as a draft")), 1)];
								}),
								_: 1
							}, _parent, _scopeId));
							_push(ssrRenderComponent(unref(KButton), {
								size: "sm",
								variant: "ghost",
								loading: busy.value === "update:theirs",
								disabled: !!busy.value,
								onClick: ($event) => resolveUpdate("theirs")
							}, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate(unref(__)("Use the theme's"))}`);
									else return [createTextVNode(toDisplayString(unref(__)("Use the theme's")), 1)];
								}),
								_: 1
							}, _parent, _scopeId));
							_push(`<button type="button" class="k-tpl-ed__diff-toggle"${ssrRenderAttr("aria-expanded", String(showDiff.value))} data-v-1d8c222d${_scopeId}>`);
							_push(ssrRenderComponent(unref(KIcon), { name: showDiff.value ? "caret-up" : "caret-down" }, null, _parent, _scopeId));
							_push(`${ssrInterpolate(unref(__)("What changed ({n})", { n: themeDiff.value.rows.length + themeDiff.value.settings.length }))}</button></div>`);
							if (showDiff.value) {
								_push(`<ul class="k-tpl-ed__diff" data-v-1d8c222d${_scopeId}><!--[-->`);
								ssrRenderList(themeDiff.value.settings, (f) => {
									_push(`<li data-v-1d8c222d${_scopeId}><span class="k-tpl-ed__diff-kind" data-v-1d8c222d${_scopeId}>${ssrInterpolate(unref(__)("Setting"))}</span>${ssrInterpolate(f)}</li>`);
								});
								_push(`<!--]--><!--[-->`);
								ssrRenderList(themeDiff.value.rows, (row) => {
									_push(`<li data-v-1d8c222d${_scopeId}><span class="${ssrRenderClass([`is-${row.change}`, "k-tpl-ed__diff-kind"])}" data-v-1d8c222d${_scopeId}>${ssrInterpolate(CHANGE[row.change])}</span>${ssrInterpolate(row.label)}</li>`);
								});
								_push(`<!--]-->`);
								if (!themeDiff.value.rows.length && !themeDiff.value.settings.length) _push(`<li class="k-muted" data-v-1d8c222d${_scopeId}>${ssrInterpolate(unref(__)("Only formatting changed."))}</li>`);
								else _push(`<!---->`);
								_push(`</ul>`);
							} else _push(`<!---->`);
						} else return [
							createVNode("p", { class: "k-tpl-ed__update" }, toDisplayString(unref(__)("This site changed it before the theme was updated. Keep yours, take the theme's, or open the theme's as a draft and bring your changes over.")), 1),
							createVNode("div", { class: "k-tpl-ed__update-actions" }, [
								createVNode(unref(KButton), {
									size: "sm",
									loading: busy.value === "update:keep",
									disabled: !!busy.value,
									onClick: ($event) => resolveUpdate("keep")
								}, {
									default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Keep mine")), 1)]),
									_: 1
								}, 8, [
									"loading",
									"disabled",
									"onClick"
								]),
								createVNode(unref(KButton), {
									size: "sm",
									loading: busy.value === "update:draft",
									disabled: !!busy.value,
									onClick: ($event) => resolveUpdate("draft")
								}, {
									default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Open theirs as a draft")), 1)]),
									_: 1
								}, 8, [
									"loading",
									"disabled",
									"onClick"
								]),
								createVNode(unref(KButton), {
									size: "sm",
									variant: "ghost",
									loading: busy.value === "update:theirs",
									disabled: !!busy.value,
									onClick: ($event) => resolveUpdate("theirs")
								}, {
									default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Use the theme's")), 1)]),
									_: 1
								}, 8, [
									"loading",
									"disabled",
									"onClick"
								]),
								createVNode("button", {
									type: "button",
									class: "k-tpl-ed__diff-toggle",
									"aria-expanded": String(showDiff.value),
									onClick: ($event) => showDiff.value = !showDiff.value
								}, [createVNode(unref(KIcon), { name: showDiff.value ? "caret-up" : "caret-down" }, null, 8, ["name"]), createTextVNode(toDisplayString(unref(__)("What changed ({n})", { n: themeDiff.value.rows.length + themeDiff.value.settings.length })), 1)], 8, ["aria-expanded", "onClick"])
							]),
							showDiff.value ? (openBlock(), createBlock("ul", {
								key: 0,
								class: "k-tpl-ed__diff"
							}, [
								(openBlock(true), createBlock(Fragment, null, renderList(themeDiff.value.settings, (f) => {
									return openBlock(), createBlock("li", { key: f }, [createVNode("span", { class: "k-tpl-ed__diff-kind" }, toDisplayString(unref(__)("Setting")), 1), createTextVNode(toDisplayString(f), 1)]);
								}), 128)),
								(openBlock(true), createBlock(Fragment, null, renderList(themeDiff.value.rows, (row) => {
									return openBlock(), createBlock("li", { key: row.id + row.change }, [createVNode("span", { class: ["k-tpl-ed__diff-kind", `is-${row.change}`] }, toDisplayString(CHANGE[row.change]), 3), createTextVNode(toDisplayString(row.label), 1)]);
								}), 128)),
								!themeDiff.value.rows.length && !themeDiff.value.settings.length ? (openBlock(), createBlock("li", {
									key: 0,
									class: "k-muted"
								}, toDisplayString(unref(__)("Only formatting changed.")), 1)) : createCommentVNode("v-if", true)
							])) : createCommentVNode("v-if", true)
						];
					}),
					_: 1
				}, _parent));
				else _push(`<!---->`);
				if (errorList.value.length) _push(ssrRenderComponent(unref(KAlert), { tone: "danger" }, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) {
							_push(`<ul class="k-tpl-ed__errors" data-v-1d8c222d${_scopeId}><!--[-->`);
							ssrRenderList(errorList.value, (m, i) => {
								_push(`<li data-v-1d8c222d${_scopeId}>${ssrInterpolate(m)}</li>`);
							});
							_push(`<!--]--></ul>`);
						} else return [createVNode("ul", { class: "k-tpl-ed__errors" }, [(openBlock(true), createBlock(Fragment, null, renderList(errorList.value, (m, i) => {
							return openBlock(), createBlock("li", { key: i }, toDisplayString(m), 1);
						}), 128))])];
					}),
					_: 1
				}, _parent));
				else _push(`<!---->`);
				_push(ssrRenderComponent(unref(KBuilder), {
					modelValue: form.value.blocks,
					"onUpdate:modelValue": ($event) => form.value.blocks = $event,
					kind: kind.value,
					"doc-key": key.value,
					"reset-key": historyKey.value,
					disabled: !!busy.value,
					errors: errors.value
				}, {
					document: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) {
							if (kind.value === "template" && !JSON.stringify(form.value.blocks).includes("core/entry-content")) _push(`<p class="k-muted k-small k-tpl-ed__hint" data-v-1d8c222d${_scopeId}>${ssrInterpolate(unref(__)("Add an Entry content block where the entry's own text should go. Without one, only the blocks above show."))}</p>`);
							else _push(`<!---->`);
							_push(`<section class="k-tpl-ed__section" data-v-1d8c222d${_scopeId}><h2 data-v-1d8c222d${_scopeId}>${ssrInterpolate(unref(__)("Settings"))}</h2><div class="k-form" data-v-1d8c222d${_scopeId}>`);
							_push(ssrRenderComponent(unref(KField), {
								label: unref(__)("Name"),
								error: fieldError("title")
							}, {
								default: withCtx(({ id }, _push, _parent, _scopeId) => {
									if (_push) _push(ssrRenderComponent(unref(KInput), {
										id,
										modelValue: form.value.title,
										"onUpdate:modelValue": ($event) => form.value.title = $event,
										maxlength: "120"
									}, null, _parent, _scopeId));
									else return [createVNode(unref(KInput), {
										id,
										modelValue: form.value.title,
										"onUpdate:modelValue": ($event) => form.value.title = $event,
										maxlength: "120"
									}, null, 8, [
										"id",
										"modelValue",
										"onUpdate:modelValue"
									])];
								}),
								_: 1
							}, _parent, _scopeId));
							_push(ssrRenderComponent(unref(KField), {
								label: unref(__)("Description"),
								error: fieldError("description")
							}, {
								default: withCtx(({ id }, _push, _parent, _scopeId) => {
									if (_push) _push(ssrRenderComponent(unref(KInput), {
										id,
										modelValue: form.value.description,
										"onUpdate:modelValue": ($event) => form.value.description = $event,
										maxlength: "300"
									}, null, _parent, _scopeId));
									else return [createVNode(unref(KInput), {
										id,
										modelValue: form.value.description,
										"onUpdate:modelValue": ($event) => form.value.description = $event,
										maxlength: "300"
									}, null, 8, [
										"id",
										"modelValue",
										"onUpdate:modelValue"
									])];
								}),
								_: 1
							}, _parent, _scopeId));
							if (kind.value === "template") {
								_push(`<!--[-->`);
								_push(ssrRenderComponent(unref(KField), { label: unref(__)("Page frame") }, {
									default: withCtx(({ id }, _push, _parent, _scopeId) => {
										if (_push) _push(ssrRenderComponent(unref(KSelect), {
											id,
											modelValue: form.value.layout,
											"onUpdate:modelValue": ($event) => form.value.layout = $event,
											options: layoutOptions
										}, null, _parent, _scopeId));
										else return [createVNode(unref(KSelect), {
											id,
											modelValue: form.value.layout,
											"onUpdate:modelValue": ($event) => form.value.layout = $event,
											options: layoutOptions
										}, null, 8, [
											"id",
											"modelValue",
											"onUpdate:modelValue"
										])];
									}),
									_: 1
								}, _parent, _scopeId));
								if (!reserved.value) {
									_push(`<fieldset class="k-tpl-ed__types" data-v-1d8c222d${_scopeId}><legend class="k-small" data-v-1d8c222d${_scopeId}>${ssrInterpolate(unref(__)("Entries that may choose it"))}</legend><!--[-->`);
									ssrRenderList(lists.value?.types ?? [], (t) => {
										_push(ssrRenderComponent(unref(KSwitch), {
											key: t.name,
											"model-value": !form.value.for || form.value.for.includes(t.name),
											label: t.plural,
											"onUpdate:modelValue": (on) => form.value.for ? toggleType(t.name, on) : form.value.for = lists.value.types.map((x) => x.name).filter((n) => n !== t.name)
										}, null, _parent, _scopeId));
									});
									_push(`<!--]--></fieldset>`);
								} else _push(`<!---->`);
								_push(`<!--]-->`);
							} else _push(`<!---->`);
							if (kind.value === "part") _push(ssrRenderComponent(unref(KField), {
								label: unref(__)("Area"),
								help: unref(__)("Headers render as <header>, footers as <footer>, sidebars as <aside>.")
							}, {
								default: withCtx(({ id }, _push, _parent, _scopeId) => {
									if (_push) _push(ssrRenderComponent(unref(KSelect), {
										id,
										modelValue: form.value.area,
										"onUpdate:modelValue": ($event) => form.value.area = $event,
										options: areaOptions.value
									}, null, _parent, _scopeId));
									else return [createVNode(unref(KSelect), {
										id,
										modelValue: form.value.area,
										"onUpdate:modelValue": ($event) => form.value.area = $event,
										options: areaOptions.value
									}, null, 8, [
										"id",
										"modelValue",
										"onUpdate:modelValue",
										"options"
									])];
								}),
								_: 1
							}, _parent, _scopeId));
							else _push(`<!---->`);
							if (kind.value === "pattern") {
								_push(`<!--[-->`);
								_push(ssrRenderComponent(unref(KField), { label: unref(__)("Category") }, {
									default: withCtx(({ id }, _push, _parent, _scopeId) => {
										if (_push) _push(ssrRenderComponent(unref(KInput), {
											id,
											modelValue: form.value.category,
											"onUpdate:modelValue": ($event) => form.value.category = $event,
											maxlength: "40"
										}, null, _parent, _scopeId));
										else return [createVNode(unref(KInput), {
											id,
											modelValue: form.value.category,
											"onUpdate:modelValue": ($event) => form.value.category = $event,
											maxlength: "40"
										}, null, 8, [
											"id",
											"modelValue",
											"onUpdate:modelValue"
										])];
									}),
									_: 1
								}, _parent, _scopeId));
								_push(ssrRenderComponent(unref(KSwitch), {
									modelValue: form.value.synced,
									"onUpdate:modelValue": ($event) => form.value.synced = $event,
									label: unref(__)("Synced")
								}, null, _parent, _scopeId));
								_push(`<!--]-->`);
							} else _push(`<!---->`);
							_push(`</div></section>`);
							if (kind.value !== "pattern" || form.value.synced) {
								_push(`<section class="k-tpl-ed__section" data-v-1d8c222d${_scopeId}><h2 data-v-1d8c222d${_scopeId}>${ssrInterpolate(unref(__)("Preview"))}</h2><p class="k-muted k-small" data-v-1d8c222d${_scopeId}>${ssrInterpolate(unref(__)("Opens a page of the site with saved drafts in place. Only people who may design see them."))}</p><form class="k-tpl-ed__preview" data-v-1d8c222d${_scopeId}>`);
								_push(ssrRenderComponent(unref(KInput), {
									modelValue: previewPath.value,
									"onUpdate:modelValue": ($event) => previewPath.value = $event,
									"aria-label": unref(__)("Page address"),
									class: "k-mono"
								}, null, _parent, _scopeId));
								_push(`<a${ssrRenderAttr("href", previewUrl.value)} target="_blank" rel="noopener" class="k-btn k-btn--secondary k-btn--sm" data-v-1d8c222d${_scopeId}>`);
								_push(ssrRenderComponent(unref(KIcon), { name: "arrow-square-out" }, null, _parent, _scopeId));
								_push(`${ssrInterpolate(unref(__)("Open"))}</a></form>`);
								if (dirty.value) _push(`<p class="k-muted k-small" data-v-1d8c222d${_scopeId}>${ssrInterpolate(unref(__)("Save the draft first to see these changes."))}</p>`);
								else _push(`<!---->`);
								_push(`</section>`);
							} else _push(`<!---->`);
							if (doc.value.used_by.length) {
								_push(`<section class="k-tpl-ed__section" data-v-1d8c222d${_scopeId}><h2 data-v-1d8c222d${_scopeId}>${ssrInterpolate(unref(__)("Used in"))}</h2><ul class="k-tpl-ed__used" data-v-1d8c222d${_scopeId}><!--[-->`);
								ssrRenderList(doc.value.used_by, (u) => {
									_push(`<li data-v-1d8c222d${_scopeId}>`);
									_push(ssrRenderComponent(unref(Link), { href: `/admin/appearance/templates/${u.kind}/${u.key}` }, {
										default: withCtx((_, _push, _parent, _scopeId) => {
											if (_push) _push(`${ssrInterpolate(u.title)}`);
											else return [createTextVNode(toDisplayString(u.title), 1)];
										}),
										_: 2
									}, _parent, _scopeId));
									_push(`</li>`);
								});
								_push(`<!--]--></ul></section>`);
							} else _push(`<!---->`);
							_push(`<section class="k-tpl-ed__section" data-v-1d8c222d${_scopeId}><h2 data-v-1d8c222d${_scopeId}>${ssrInterpolate(unref(__)("History"))}</h2>`);
							if (!doc.value.revisions.length) _push(`<p class="k-muted k-small" data-v-1d8c222d${_scopeId}>${ssrInterpolate(doc.value.file ? unref(__)("Not changed on this site: the theme's version is live.") : unref(__)("Not published yet."))}</p>`);
							else {
								_push(`<ol class="k-tpl-ed__history" data-v-1d8c222d${_scopeId}><!--[-->`);
								ssrRenderList(doc.value.revisions, (r, i) => {
									_push(`<li data-v-1d8c222d${_scopeId}><span data-v-1d8c222d${_scopeId}>`);
									_push(ssrRenderComponent(unref(KTime), { value: r.created_at }, null, _parent, _scopeId));
									_push(`<span class="k-muted k-small" data-v-1d8c222d${_scopeId}> · ${ssrInterpolate(r.reset ? doc.value.file ? unref(__)("Theme's version") : unref(__)("Deleted") : r.author_name ?? unref(__)("Someone"))}</span></span>`);
									if (i === 0 && (isLive.value || r.reset)) _push(ssrRenderComponent(unref(KBadge), { tone: "success" }, {
										default: withCtx((_, _push, _parent, _scopeId) => {
											if (_push) _push(`${ssrInterpolate(unref(__)("Live"))}`);
											else return [createTextVNode(toDisplayString(unref(__)("Live")), 1)];
										}),
										_: 2
									}, _parent, _scopeId));
									else _push(ssrRenderComponent(unref(KButton), {
										size: "sm",
										variant: "ghost",
										disabled: !!busy.value,
										onClick: ($event) => restore(r)
									}, {
										default: withCtx((_, _push, _parent, _scopeId) => {
											if (_push) _push(`${ssrInterpolate(unref(__)("Restore"))}`);
											else return [createTextVNode(toDisplayString(unref(__)("Restore")), 1)];
										}),
										_: 2
									}, _parent, _scopeId));
									_push(`</li>`);
								});
								_push(`<!--]--></ol>`);
							}
							_push(`<div class="k-tpl-ed__danger" data-v-1d8c222d${_scopeId}>`);
							if (doc.value.draft) _push(ssrRenderComponent(unref(KButton), {
								size: "sm",
								variant: "ghost",
								icon: "x",
								disabled: !!busy.value,
								onClick: discard
							}, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate(isLive.value ? unref(__)("Discard draft") : unref(__)("Delete draft"))}`);
									else return [createTextVNode(toDisplayString(isLive.value ? unref(__)("Discard draft") : unref(__)("Delete draft")), 1)];
								}),
								_: 1
							}, _parent, _scopeId));
							else _push(`<!---->`);
							if (doc.value.source === "customized") _push(ssrRenderComponent(unref(KButton), {
								size: "sm",
								variant: "ghost",
								icon: "arrow-counter-clockwise",
								disabled: !!busy.value,
								onClick: reset
							}, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate(unref(__)("Back to the original version"))}`);
									else return [createTextVNode(toDisplayString(unref(__)("Back to the original version")), 1)];
								}),
								_: 1
							}, _parent, _scopeId));
							else if (doc.value.source === "site" && isLive.value) _push(ssrRenderComponent(unref(KButton), {
								size: "sm",
								variant: "ghost",
								icon: "trash",
								disabled: !!busy.value || doc.value.used_by.length > 0,
								onClick: reset
							}, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate(unref(__)("Delete"))}`);
									else return [createTextVNode(toDisplayString(unref(__)("Delete")), 1)];
								}),
								_: 1
							}, _parent, _scopeId));
							else _push(`<!---->`);
							_push(`</div>`);
							if (doc.value.source === "site" && isLive.value && doc.value.used_by.length) _push(`<p class="k-muted k-small" data-v-1d8c222d${_scopeId}>${ssrInterpolate(unref(__)("Take it out of the templates above before deleting it."))}</p>`);
							else _push(`<!---->`);
							_push(`</section>`);
						} else return [
							kind.value === "template" && !JSON.stringify(form.value.blocks).includes("core/entry-content") ? (openBlock(), createBlock("p", {
								key: 0,
								class: "k-muted k-small k-tpl-ed__hint"
							}, toDisplayString(unref(__)("Add an Entry content block where the entry's own text should go. Without one, only the blocks above show.")), 1)) : createCommentVNode("v-if", true),
							createVNode("section", { class: "k-tpl-ed__section" }, [createVNode("h2", null, toDisplayString(unref(__)("Settings")), 1), createVNode("div", { class: "k-form" }, [
								createVNode(unref(KField), {
									label: unref(__)("Name"),
									error: fieldError("title")
								}, {
									default: withCtx(({ id }) => [createVNode(unref(KInput), {
										id,
										modelValue: form.value.title,
										"onUpdate:modelValue": ($event) => form.value.title = $event,
										maxlength: "120"
									}, null, 8, [
										"id",
										"modelValue",
										"onUpdate:modelValue"
									])]),
									_: 1
								}, 8, ["label", "error"]),
								createVNode(unref(KField), {
									label: unref(__)("Description"),
									error: fieldError("description")
								}, {
									default: withCtx(({ id }) => [createVNode(unref(KInput), {
										id,
										modelValue: form.value.description,
										"onUpdate:modelValue": ($event) => form.value.description = $event,
										maxlength: "300"
									}, null, 8, [
										"id",
										"modelValue",
										"onUpdate:modelValue"
									])]),
									_: 1
								}, 8, ["label", "error"]),
								kind.value === "template" ? (openBlock(), createBlock(Fragment, { key: 0 }, [createVNode(unref(KField), { label: unref(__)("Page frame") }, {
									default: withCtx(({ id }) => [createVNode(unref(KSelect), {
										id,
										modelValue: form.value.layout,
										"onUpdate:modelValue": ($event) => form.value.layout = $event,
										options: layoutOptions
									}, null, 8, [
										"id",
										"modelValue",
										"onUpdate:modelValue"
									])]),
									_: 1
								}, 8, ["label"]), !reserved.value ? (openBlock(), createBlock("fieldset", {
									key: 0,
									class: "k-tpl-ed__types"
								}, [createVNode("legend", { class: "k-small" }, toDisplayString(unref(__)("Entries that may choose it")), 1), (openBlock(true), createBlock(Fragment, null, renderList(lists.value?.types ?? [], (t) => {
									return openBlock(), createBlock(unref(KSwitch), {
										key: t.name,
										"model-value": !form.value.for || form.value.for.includes(t.name),
										label: t.plural,
										"onUpdate:modelValue": (on) => form.value.for ? toggleType(t.name, on) : form.value.for = lists.value.types.map((x) => x.name).filter((n) => n !== t.name)
									}, null, 8, [
										"model-value",
										"label",
										"onUpdate:modelValue"
									]);
								}), 128))])) : createCommentVNode("v-if", true)], 64)) : createCommentVNode("v-if", true),
								kind.value === "part" ? (openBlock(), createBlock(unref(KField), {
									key: 1,
									label: unref(__)("Area"),
									help: unref(__)("Headers render as <header>, footers as <footer>, sidebars as <aside>.")
								}, {
									default: withCtx(({ id }) => [createVNode(unref(KSelect), {
										id,
										modelValue: form.value.area,
										"onUpdate:modelValue": ($event) => form.value.area = $event,
										options: areaOptions.value
									}, null, 8, [
										"id",
										"modelValue",
										"onUpdate:modelValue",
										"options"
									])]),
									_: 1
								}, 8, ["label", "help"])) : createCommentVNode("v-if", true),
								kind.value === "pattern" ? (openBlock(), createBlock(Fragment, { key: 2 }, [createVNode(unref(KField), { label: unref(__)("Category") }, {
									default: withCtx(({ id }) => [createVNode(unref(KInput), {
										id,
										modelValue: form.value.category,
										"onUpdate:modelValue": ($event) => form.value.category = $event,
										maxlength: "40"
									}, null, 8, [
										"id",
										"modelValue",
										"onUpdate:modelValue"
									])]),
									_: 1
								}, 8, ["label"]), createVNode(unref(KSwitch), {
									modelValue: form.value.synced,
									"onUpdate:modelValue": ($event) => form.value.synced = $event,
									label: unref(__)("Synced")
								}, null, 8, [
									"modelValue",
									"onUpdate:modelValue",
									"label"
								])], 64)) : createCommentVNode("v-if", true)
							])]),
							kind.value !== "pattern" || form.value.synced ? (openBlock(), createBlock("section", {
								key: 1,
								class: "k-tpl-ed__section"
							}, [
								createVNode("h2", null, toDisplayString(unref(__)("Preview")), 1),
								createVNode("p", { class: "k-muted k-small" }, toDisplayString(unref(__)("Opens a page of the site with saved drafts in place. Only people who may design see them.")), 1),
								createVNode("form", {
									class: "k-tpl-ed__preview",
									onSubmit: withModifiers(() => {}, ["prevent"])
								}, [createVNode(unref(KInput), {
									modelValue: previewPath.value,
									"onUpdate:modelValue": ($event) => previewPath.value = $event,
									"aria-label": unref(__)("Page address"),
									class: "k-mono"
								}, null, 8, [
									"modelValue",
									"onUpdate:modelValue",
									"aria-label"
								]), createVNode("a", {
									href: previewUrl.value,
									target: "_blank",
									rel: "noopener",
									class: "k-btn k-btn--secondary k-btn--sm"
								}, [createVNode(unref(KIcon), { name: "arrow-square-out" }), createTextVNode(toDisplayString(unref(__)("Open")), 1)], 8, ["href"])], 40, ["onSubmit"]),
								dirty.value ? (openBlock(), createBlock("p", {
									key: 0,
									class: "k-muted k-small"
								}, toDisplayString(unref(__)("Save the draft first to see these changes.")), 1)) : createCommentVNode("v-if", true)
							])) : createCommentVNode("v-if", true),
							doc.value.used_by.length ? (openBlock(), createBlock("section", {
								key: 2,
								class: "k-tpl-ed__section"
							}, [createVNode("h2", null, toDisplayString(unref(__)("Used in")), 1), createVNode("ul", { class: "k-tpl-ed__used" }, [(openBlock(true), createBlock(Fragment, null, renderList(doc.value.used_by, (u) => {
								return openBlock(), createBlock("li", { key: `${u.kind}/${u.key}` }, [createVNode(unref(Link), { href: `/admin/appearance/templates/${u.kind}/${u.key}` }, {
									default: withCtx(() => [createTextVNode(toDisplayString(u.title), 1)]),
									_: 2
								}, 1032, ["href"])]);
							}), 128))])])) : createCommentVNode("v-if", true),
							createVNode("section", { class: "k-tpl-ed__section" }, [
								createVNode("h2", null, toDisplayString(unref(__)("History")), 1),
								!doc.value.revisions.length ? (openBlock(), createBlock("p", {
									key: 0,
									class: "k-muted k-small"
								}, toDisplayString(doc.value.file ? unref(__)("Not changed on this site: the theme's version is live.") : unref(__)("Not published yet.")), 1)) : (openBlock(), createBlock("ol", {
									key: 1,
									class: "k-tpl-ed__history"
								}, [(openBlock(true), createBlock(Fragment, null, renderList(doc.value.revisions, (r, i) => {
									return openBlock(), createBlock("li", { key: r.id }, [createVNode("span", null, [createVNode(unref(KTime), { value: r.created_at }, null, 8, ["value"]), createVNode("span", { class: "k-muted k-small" }, " · " + toDisplayString(r.reset ? doc.value.file ? unref(__)("Theme's version") : unref(__)("Deleted") : r.author_name ?? unref(__)("Someone")), 1)]), i === 0 && (isLive.value || r.reset) ? (openBlock(), createBlock(unref(KBadge), {
										key: 0,
										tone: "success"
									}, {
										default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Live")), 1)]),
										_: 1
									})) : (openBlock(), createBlock(unref(KButton), {
										key: 1,
										size: "sm",
										variant: "ghost",
										disabled: !!busy.value,
										onClick: ($event) => restore(r)
									}, {
										default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Restore")), 1)]),
										_: 1
									}, 8, ["disabled", "onClick"]))]);
								}), 128))])),
								createVNode("div", { class: "k-tpl-ed__danger" }, [doc.value.draft ? (openBlock(), createBlock(unref(KButton), {
									key: 0,
									size: "sm",
									variant: "ghost",
									icon: "x",
									disabled: !!busy.value,
									onClick: discard
								}, {
									default: withCtx(() => [createTextVNode(toDisplayString(isLive.value ? unref(__)("Discard draft") : unref(__)("Delete draft")), 1)]),
									_: 1
								}, 8, ["disabled"])) : createCommentVNode("v-if", true), doc.value.source === "customized" ? (openBlock(), createBlock(unref(KButton), {
									key: 1,
									size: "sm",
									variant: "ghost",
									icon: "arrow-counter-clockwise",
									disabled: !!busy.value,
									onClick: reset
								}, {
									default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Back to the original version")), 1)]),
									_: 1
								}, 8, ["disabled"])) : doc.value.source === "site" && isLive.value ? (openBlock(), createBlock(unref(KButton), {
									key: 2,
									size: "sm",
									variant: "ghost",
									icon: "trash",
									disabled: !!busy.value || doc.value.used_by.length > 0,
									onClick: reset
								}, {
									default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Delete")), 1)]),
									_: 1
								}, 8, ["disabled"])) : createCommentVNode("v-if", true)]),
								doc.value.source === "site" && isLive.value && doc.value.used_by.length ? (openBlock(), createBlock("p", {
									key: 2,
									class: "k-muted k-small"
								}, toDisplayString(unref(__)("Take it out of the templates above before deleting it.")), 1)) : createCommentVNode("v-if", true)
							])
						];
					}),
					_: 1
				}, _parent));
				_push(`</div>`);
			} else _push(`<!---->`);
			_push(`<!--]-->`);
		};
	}
});
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/admin/appearance/TemplateEdit.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
var TemplateEdit_default = /*#__PURE__*/ _plugin_vue_export_helper_default(_sfc_main, [["__scopeId", "data-v-1d8c222d"]]);
export { TemplateEdit_default as default };
