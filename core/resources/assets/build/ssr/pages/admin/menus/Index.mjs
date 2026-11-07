import { t as _plugin_vue_export_helper_default } from "../../../chunks/_plugin-vue_export-helper-DjkACiAr.mjs";
import { Fragment, computed, createBlock, createCommentVNode, createTextVNode, createVNode, nextTick, onBeforeUnmount, onMounted, openBlock, reactive, ref, renderList, toDisplayString, unref, useSSRContext, vModelCheckbox, vModelText, watch, withCtx, withDirectives, withModifiers } from "vue";
import { ssrIncludeBooleanAttr, ssrInterpolate, ssrLooseContain, ssrRenderAttr, ssrRenderClass, ssrRenderComponent, ssrRenderList, ssrRenderStyle } from "vue/server-renderer";
import { Head } from "@kestello/app";
import { ApiError, __, confirm, notify, useApi, useLeaveGuard, useResource } from "@kestello/sdk";
import { KAlert, KBadge, KButton, KCard, KEmpty, KField, KIcon, KInput, KPageHeader, KPicker, KSelect, KTabs } from "@kestello/ui";
// core/resources/js/pages/admin/menus/Index.vue
var MAX_DEPTH = 4;
var _sfc_main = /*@__PURE__*/ Object.assign({ layout: "Admin" }, {
	__name: "Index",
	__ssrInlineRender: true,
	setup(__props) {
		const api = useApi();
		const overview = useResource("/menus");
		const types = useResource("/content/types");
		const taxonomies = useResource("/taxonomies");
		const locations = computed(() => overview.data.value?.locations ?? []);
		const menuOptions = computed(() => [{
			value: "",
			label: __("No menu")
		}, ...(overview.data.value?.menus ?? []).map((m) => ({
			value: String(m.id),
			label: m.name
		}))]);
		async function assign(location, value) {
			try {
				await api.put("/menus/locations", { locations: { [location.name]: value === "" ? null : Number(value) } });
				notify.success(value === "" ? __("{location} is now empty.", { location: location.label }) : __("{location} now shows this menu.", { location: location.label }));
				overview.reload({ silent: true });
			} catch (e) {
				notify.error(e);
			}
		}
		const selectedId = ref(null);
		const name = ref("");
		const items = ref([]);
		const saved = ref("");
		const errors = ref({});
		const saving = ref(false);
		let nextKey = 1;
		watch(() => overview.data.value?.menus, (menus) => {
			if (!menus) return;
			if (selectedId.value === null && menus.length) open(menus[0].id);
		}, { immediate: true });
		async function open(id) {
			if (dirty.value && !await confirm({
				title: __("Discard unsaved changes?"),
				message: __("Your changes to “{name}” are not saved.", { name: name.value }),
				confirmLabel: __("Discard")
			})) return;
			try {
				const { data } = await api.get(`/menus/${id}`);
				load(data);
			} catch (e) {
				notify.error(e);
			}
		}
		function load(menu) {
			selectedId.value = menu.id;
			name.value = menu.name;
			items.value = flatten(menu.items);
			errors.value = {};
			saved.value = snapshot();
		}
		function flatten(tree, depth = 0, out = []) {
			for (const item of tree) {
				const { children, ...rest } = item;
				out.push({
					key: nextKey++,
					depth,
					label: "",
					new_tab: false,
					...rest
				});
				flatten(children ?? [], depth + 1, out);
			}
			return out;
		}
		function toTree() {
			const root = [];
			const stack = [{
				depth: -1,
				children: root
			}];
			for (const it of items.value) {
				const node = {
					kind: it.kind,
					...it.kind === "url" ? { url: it.url } : { ref: it.ref },
					...it.label ? { label: it.label } : {},
					...it.new_tab ? { new_tab: true } : {},
					children: []
				};
				while (stack[stack.length - 1].depth >= it.depth) stack.pop();
				stack[stack.length - 1].children.push(node);
				stack.push({
					depth: it.depth,
					children: node.children
				});
			}
			const strip = (list) => list.map(({ children, ...n }) => children.length ? {
				...n,
				children: strip(children)
			} : n);
			return strip(root);
		}
		const snapshot = () => JSON.stringify({
			name: name.value,
			items: toTree()
		});
		const dirty = computed(() => selectedId.value !== null && snapshot() !== saved.value);
		async function save() {
			saving.value = true;
			errors.value = {};
			try {
				const { data } = await api.put(`/menus/${selectedId.value}`, {
					name: name.value,
					items: toTree()
				});
				load(data);
				notify.success(__("Menu saved."));
				overview.reload({ silent: true });
			} catch (e) {
				if (e instanceof ApiError && e.status === 422) {
					errors.value = e.fieldErrors();
					notify.error(__("Some items need attention."));
				} else notify.error(e);
			} finally {
				saving.value = false;
			}
		}
		const rowErrors = computed(() => {
			const out = {};
			const counters = [];
			const path = [];
			items.value.forEach((it) => {
				counters.length = it.depth + 1;
				counters[it.depth] = (counters[it.depth] ?? -1) + 1;
				path.length = it.depth;
				path[it.depth] = counters[it.depth];
				const prefix = "items." + path.slice(0, it.depth + 1).join(".children.");
				const msgs = Object.entries(errors.value).filter(([k]) => k === prefix || k.startsWith(prefix + ".") && !k.slice(prefix.length + 1).startsWith("children")).map(([, v]) => v);
				if (msgs.length) out[it.key] = msgs.join(" ");
			});
			return out;
		});
		const creating = ref(false);
		const newName = ref("");
		const creatingBusy = ref(false);
		const newForm = ref(null);
		watch(creating, (on) => on && nextTick(() => newForm.value?.querySelector("input")?.focus()));
		async function create() {
			if (creatingBusy.value) return;
			if (dirty.value && !await confirm({
				title: __("Discard unsaved changes?"),
				message: __("Your changes to “{name}” are not saved.", { name: name.value }),
				confirmLabel: __("Discard")
			})) return;
			creatingBusy.value = true;
			try {
				const { data } = await api.post("/menus", { name: newName.value });
				creating.value = false;
				newName.value = "";
				saved.value = snapshot();
				load(data);
				overview.reload({ silent: true });
			} catch (e) {
				notify.error(e);
			} finally {
				creatingBusy.value = false;
			}
		}
		async function removeMenu() {
			const where = (overview.data.value?.menus ?? []).find((m) => m.id === selectedId.value)?.locations ?? [];
			if (!await confirm({
				title: __("Delete “{name}”?", { name: name.value }),
				message: where.length ? __("{n, plural, one {It is shown in # theme location, which will be empty.} other {It is shown in # theme locations, which will be empty.}}", { n: where.length }) : __("It is not shown anywhere."),
				confirmLabel: __("Delete menu"),
				danger: true
			})) return;
			try {
				await api.delete(`/menus/${selectedId.value}`);
				selectedId.value = null;
				items.value = [];
				saved.value = snapshot();
				await overview.reload({ silent: true });
				const first = overview.data.value?.menus?.[0];
				if (first) open(first.id);
			} catch (e) {
				notify.error(e);
			}
		}
		const end = (i) => {
			let j = i + 1;
			while (j < items.value.length && items.value[j].depth > items.value[i].depth) j++;
			return j;
		};
		function prevSibling(i) {
			for (let p = i - 1; p >= 0; p--) {
				if (items.value[p].depth === items.value[i].depth) return p;
				if (items.value[p].depth < items.value[i].depth) return -1;
			}
			return -1;
		}
		function nextSibling(i) {
			const j = end(i);
			return j < items.value.length && items.value[j].depth === items.value[i].depth ? j : -1;
		}
		function moveBlock(from, to) {
			const list = [...items.value];
			const block = list.splice(from, end(from) - from);
			list.splice(to, 0, ...block);
			items.value = list;
		}
		function up(i) {
			const p = prevSibling(i);
			if (p < 0) return;
			moveBlock(i, p);
			focusRow(items.value[p].key);
		}
		function down(i) {
			const n = nextSibling(i);
			if (n < 0) return;
			const key = items.value[i].key;
			const size = end(i) - i;
			moveBlock(i, end(n) - size);
			focusRow(key);
		}
		function indent(i) {
			if (prevSibling(i) < 0) return;
			const list = [...items.value];
			for (let j = i; j < end(i); j++) list[j] = {
				...list[j],
				depth: list[j].depth + 1
			};
			if (list.slice(i, end(i)).some((x) => x.depth >= MAX_DEPTH)) return notify.warning(__("Menus nest at most {n} levels.", { n: MAX_DEPTH }));
			items.value = list;
		}
		function outdent(i) {
			const d = items.value[i].depth;
			if (d === 0) return;
			let parent = i - 1;
			while (items.value[parent].depth !== d - 1) parent--;
			const key = items.value[i].key;
			const target = end(parent);
			const size = end(i) - i;
			const list = [...items.value];
			for (let j = i; j < i + size; j++) list[j] = {
				...list[j],
				depth: list[j].depth - 1
			};
			items.value = list;
			moveBlock(i, target - size);
			focusRow(key);
		}
		function remove(i) {
			const list = [...items.value];
			const removed = list.splice(i, end(i) - i);
			items.value = list;
			if (removed.length > 1) notify.info(__("{n, plural, one {Removed with # sub-item.} other {Removed with # sub-items.}}", { n: removed.length - 1 }));
		}
		const expanded = reactive(/* @__PURE__ */ new Set());
		const toggle = (key) => expanded.has(key) ? expanded.delete(key) : expanded.add(key);
		function focusRow(key) {
			setTimeout(() => document.querySelector(`[data-menu-row="${key}"] .k-menu-row__title`)?.focus());
		}
		function keydown(e, i) {
			if (!e.altKey) return;
			const map = {
				ArrowUp: up,
				ArrowDown: down,
				ArrowRight: indent,
				ArrowLeft: outdent
			};
			if (map[e.key]) {
				e.preventDefault();
				map[e.key](i);
			}
		}
		function onKeys(e) {
			if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === "s" && dirty.value) {
				e.preventDefault();
				save();
			}
		}
		useLeaveGuard(dirty);
		onMounted(() => window.addEventListener("keydown", onKeys));
		onBeforeUnmount(() => window.removeEventListener("keydown", onKeys));
		const source = ref("entries");
		const sources = computed(() => [
			{
				id: "entries",
				label: __("Content")
			},
			...(taxonomies.data.value ?? []).filter((t) => t.path).length ? [{
				id: "terms",
				label: __("Terms")
			}] : [],
			...archives.value.length ? [{
				id: "archives",
				label: __("Archives")
			}] : [],
			{
				id: "url",
				label: __("Link")
			}
		]);
		const archives = computed(() => (types.data.value ?? []).filter((t) => t.archive));
		function add(item) {
			items.value = [...items.value, {
				key: nextKey++,
				depth: 0,
				label: "",
				new_tab: false,
				...item
			}];
			notify.success(__("Added “{label}” at the end.", { label: item.label || item.resolved?.label || "" }));
		}
		const picked = ref([]);
		const entrySearch = async (q) => (await api.get("/content/lookup", { search: q || void 0 })).data.map((e) => ({
			id: e.id,
			label: e.title || __("(untitled)"),
			meta: e.type_label + (e.status !== "published" ? ` · ${__("not published")}` : ""),
			entry: e
		}));
		const entryResolve = async (ids) => (await api.get("/content/lookup", { ids: ids.join(",") })).data.map((e) => ({
			id: e.id,
			label: e.title,
			entry: e
		}));
		watch(picked, async (ids) => {
			if (!ids.length) return;
			const list = (await api.get("/content/lookup", { ids: ids.join(",") })).data;
			for (const e of list) add({
				kind: "entry",
				ref: e.id,
				resolved: {
					label: e.title,
					url: e.path,
					live: e.status === "published" && !!e.path,
					type_label: e.type_label
				}
			});
			picked.value = [];
		});
		const termTax = ref("");
		watch(() => taxonomies.data.value, (list) => {
			if (!termTax.value && list?.length) termTax.value = list.find((t) => t.path)?.name ?? "";
		});
		const termOptions = computed(() => (taxonomies.data.value ?? []).filter((t) => t.path).map((t) => ({
			value: t.name,
			label: t.plural
		})));
		const pickedTerms = ref([]);
		const termSearch = async (q) => (await api.get(`/taxonomies/${termTax.value}/terms`, {
			search: q || void 0,
			limit: 20
		})).data.map((t) => ({
			id: t.id,
			label: t.name,
			meta: t.path,
			term: t
		}));
		const termResolve = async (ids) => (await api.get(`/taxonomies/${termTax.value}/terms`, { ids: ids.join(",") })).data.map((t) => ({
			id: t.id,
			label: t.name
		}));
		watch(pickedTerms, async (ids) => {
			if (!ids.length) return;
			const list = (await api.get(`/taxonomies/${termTax.value}/terms`, { ids: ids.join(",") })).data;
			const tax = (taxonomies.data.value ?? []).find((x) => x.name === termTax.value);
			for (const t of list) add({
				kind: "term",
				ref: t.id,
				resolved: {
					label: t.name,
					url: t.path,
					live: !!t.path,
					type_label: tax?.singular
				}
			});
			pickedTerms.value = [];
		});
		const link = reactive({
			url: "",
			label: ""
		});
		function addLink() {
			add({
				kind: "url",
				url: link.url.trim(),
				label: link.label.trim(),
				resolved: {
					label: "",
					url: link.url.trim(),
					live: true
				}
			});
			Object.assign(link, {
				url: "",
				label: ""
			});
		}
		const KIND = {
			entry: __("Page or post"),
			term: __("Term"),
			archive: __("Archive"),
			url: __("Link")
		};
		const title = (it) => it.label || it.resolved?.label || (it.kind === "url" ? it.url : __("(untitled)"));
		const hidden = (it) => it.resolved && !it.resolved.live;
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<!--[-->`);
			_push(ssrRenderComponent(unref(Head), { title: unref(__)("Menus") }, null, _parent));
			_push(ssrRenderComponent(unref(KPageHeader), {
				title: unref(__)("Menus"),
				description: unref(__)("How visitors find their way around. Build a menu here; your theme decides where each one appears.")
			}, {
				actions: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(ssrRenderComponent(unref(KButton), {
						icon: "plus",
						onClick: ($event) => creating.value = true
					}, {
						default: withCtx((_, _push, _parent, _scopeId) => {
							if (_push) _push(`${ssrInterpolate(unref(__)("New menu"))}`);
							else return [createTextVNode(toDisplayString(unref(__)("New menu")), 1)];
						}),
						_: 1
					}, _parent, _scopeId));
					else return [createVNode(unref(KButton), {
						icon: "plus",
						onClick: ($event) => creating.value = true
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("New menu")), 1)]),
						_: 1
					}, 8, ["onClick"])];
				}),
				_: 1
			}, _parent));
			if (unref(overview).error.value) _push(ssrRenderComponent(unref(KAlert), { tone: "danger" }, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(`${ssrInterpolate(unref(overview).error.value.message)}`);
					else return [createTextVNode(toDisplayString(unref(overview).error.value.message), 1)];
				}),
				_: 1
			}, _parent));
			else _push(`<!---->`);
			if (locations.value.length) _push(ssrRenderComponent(unref(KCard), {
				coord: "A1",
				title: unref(__)("Theme locations"),
				description: unref(__)("Which menu shows where. Changes apply right away.")
			}, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(`<div class="k-locations" data-v-4fdf2b0b${_scopeId}><!--[-->`);
						ssrRenderList(locations.value, (loc) => {
							_push(ssrRenderComponent(unref(KField), {
								key: loc.name,
								label: loc.label,
								help: loc.description
							}, {
								default: withCtx(({ id }, _push, _parent, _scopeId) => {
									if (_push) _push(ssrRenderComponent(unref(KSelect), {
										id,
										"model-value": loc.menu_id === null ? "" : String(loc.menu_id),
										options: menuOptions.value,
										"onUpdate:modelValue": ($event) => assign(loc, $event)
									}, null, _parent, _scopeId));
									else return [createVNode(unref(KSelect), {
										id,
										"model-value": loc.menu_id === null ? "" : String(loc.menu_id),
										options: menuOptions.value,
										"onUpdate:modelValue": ($event) => assign(loc, $event)
									}, null, 8, [
										"id",
										"model-value",
										"options",
										"onUpdate:modelValue"
									])];
								}),
								_: 2
							}, _parent, _scopeId));
						});
						_push(`<!--]--></div>`);
					} else return [createVNode("div", { class: "k-locations" }, [(openBlock(true), createBlock(Fragment, null, renderList(locations.value, (loc) => {
						return openBlock(), createBlock(unref(KField), {
							key: loc.name,
							label: loc.label,
							help: loc.description
						}, {
							default: withCtx(({ id }) => [createVNode(unref(KSelect), {
								id,
								"model-value": loc.menu_id === null ? "" : String(loc.menu_id),
								options: menuOptions.value,
								"onUpdate:modelValue": ($event) => assign(loc, $event)
							}, null, 8, [
								"id",
								"model-value",
								"options",
								"onUpdate:modelValue"
							])]),
							_: 2
						}, 1032, ["label", "help"]);
					}), 128))])];
				}),
				_: 1
			}, _parent));
			else if (unref(overview).data.value) _push(ssrRenderComponent(unref(KAlert), { tone: "info" }, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(`${ssrInterpolate(unref(__)("The active theme declares no menu locations, so menus built here are not shown yet."))}`);
					else return [createTextVNode(toDisplayString(unref(__)("The active theme declares no menu locations, so menus built here are not shown yet.")), 1)];
				}),
				_: 1
			}, _parent));
			else _push(`<!---->`);
			if (creating.value) _push(ssrRenderComponent(unref(KCard), { title: unref(__)("New menu") }, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(`<form class="k-input-row" data-v-4fdf2b0b${_scopeId}>`);
						_push(ssrRenderComponent(unref(KField), {
							label: unref(__)("Name"),
							help: unref(__)("Only editors see it. Such as “Main” or “Footer links”."),
							class: "k-menus__new-name"
						}, {
							default: withCtx(({ id }, _push, _parent, _scopeId) => {
								if (_push) _push(ssrRenderComponent(unref(KInput), {
									id,
									modelValue: newName.value,
									"onUpdate:modelValue": ($event) => newName.value = $event,
									maxlength: "100",
									autocomplete: "off"
								}, null, _parent, _scopeId));
								else return [createVNode(unref(KInput), {
									id,
									modelValue: newName.value,
									"onUpdate:modelValue": ($event) => newName.value = $event,
									maxlength: "100",
									autocomplete: "off"
								}, null, 8, [
									"id",
									"modelValue",
									"onUpdate:modelValue"
								])];
							}),
							_: 1
						}, _parent, _scopeId));
						_push(ssrRenderComponent(unref(KButton), {
							type: "submit",
							variant: "primary",
							loading: creatingBusy.value,
							disabled: !newName.value.trim()
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Create"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Create")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						_push(ssrRenderComponent(unref(KButton), {
							variant: "ghost",
							onClick: ($event) => creating.value = false
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Cancel"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Cancel")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						_push(`</form>`);
					} else return [createVNode("form", {
						ref_key: "newForm",
						ref: newForm,
						class: "k-input-row",
						onSubmit: withModifiers(create, ["prevent"])
					}, [
						createVNode(unref(KField), {
							label: unref(__)("Name"),
							help: unref(__)("Only editors see it. Such as “Main” or “Footer links”."),
							class: "k-menus__new-name"
						}, {
							default: withCtx(({ id }) => [createVNode(unref(KInput), {
								id,
								modelValue: newName.value,
								"onUpdate:modelValue": ($event) => newName.value = $event,
								maxlength: "100",
								autocomplete: "off"
							}, null, 8, [
								"id",
								"modelValue",
								"onUpdate:modelValue"
							])]),
							_: 1
						}, 8, ["label", "help"]),
						createVNode(unref(KButton), {
							type: "submit",
							variant: "primary",
							loading: creatingBusy.value,
							disabled: !newName.value.trim()
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Create")), 1)]),
							_: 1
						}, 8, ["loading", "disabled"]),
						createVNode(unref(KButton), {
							variant: "ghost",
							onClick: ($event) => creating.value = false
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Cancel")), 1)]),
							_: 1
						}, 8, ["onClick"])
					], 544)];
				}),
				_: 1
			}, _parent));
			else _push(`<!---->`);
			if (unref(overview).data.value && !unref(overview).data.value.menus.length && !creating.value) _push(ssrRenderComponent(unref(KEmpty), {
				icon: "tree-view",
				title: unref(__)("No menus yet")
			}, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(`<p data-v-4fdf2b0b${_scopeId}>${ssrInterpolate(unref(__)("Create one, add pages and links, then put it in a theme location."))}</p>`);
						_push(ssrRenderComponent(unref(KButton), {
							variant: "primary",
							icon: "plus",
							onClick: ($event) => creating.value = true
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("New menu"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("New menu")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
					} else return [createVNode("p", null, toDisplayString(unref(__)("Create one, add pages and links, then put it in a theme location.")), 1), createVNode(unref(KButton), {
						variant: "primary",
						icon: "plus",
						onClick: ($event) => creating.value = true
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("New menu")), 1)]),
						_: 1
					}, 8, ["onClick"])];
				}),
				_: 1
			}, _parent));
			else _push(`<!---->`);
			if (selectedId.value !== null) {
				_push(`<div class="k-cells k-menu-editor" style="--k-cols:3;" data-v-4fdf2b0b>`);
				_push(ssrRenderComponent(unref(KCard), {
					coord: "A2",
					title: unref(__)("Add items")
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) {
							_push(ssrRenderComponent(unref(KTabs), {
								modelValue: source.value,
								"onUpdate:modelValue": ($event) => source.value = $event,
								tabs: sources.value,
								label: unref(__)("What to add")
							}, null, _parent, _scopeId));
							_push(`<div class="k-menu-source" data-v-4fdf2b0b${_scopeId}>`);
							if (source.value === "entries") _push(ssrRenderComponent(unref(KField), {
								label: unref(__)("Find a page or post"),
								help: unref(__)("Titles and addresses stay in sync when the page changes.")
							}, {
								default: withCtx(({ id }, _push, _parent, _scopeId) => {
									if (_push) _push(ssrRenderComponent(unref(KPicker), {
										id,
										modelValue: picked.value,
										"onUpdate:modelValue": ($event) => picked.value = $event,
										multiple: "",
										search: entrySearch,
										resolve: entryResolve
									}, null, _parent, _scopeId));
									else return [createVNode(unref(KPicker), {
										id,
										modelValue: picked.value,
										"onUpdate:modelValue": ($event) => picked.value = $event,
										multiple: "",
										search: entrySearch,
										resolve: entryResolve
									}, null, 8, [
										"id",
										"modelValue",
										"onUpdate:modelValue"
									])];
								}),
								_: 1
							}, _parent, _scopeId));
							else if (source.value === "terms") {
								_push(`<!--[-->`);
								_push(ssrRenderComponent(unref(KField), { label: unref(__)("Taxonomy") }, {
									default: withCtx(({ id }, _push, _parent, _scopeId) => {
										if (_push) _push(ssrRenderComponent(unref(KSelect), {
											id,
											modelValue: termTax.value,
											"onUpdate:modelValue": ($event) => termTax.value = $event,
											options: termOptions.value
										}, null, _parent, _scopeId));
										else return [createVNode(unref(KSelect), {
											id,
											modelValue: termTax.value,
											"onUpdate:modelValue": ($event) => termTax.value = $event,
											options: termOptions.value
										}, null, 8, [
											"id",
											"modelValue",
											"onUpdate:modelValue",
											"options"
										])];
									}),
									_: 1
								}, _parent, _scopeId));
								_push(ssrRenderComponent(unref(KField), { label: unref(__)("Find a term") }, {
									default: withCtx(({ id }, _push, _parent, _scopeId) => {
										if (_push) _push(ssrRenderComponent(unref(KPicker), {
											id,
											key: termTax.value,
											modelValue: pickedTerms.value,
											"onUpdate:modelValue": ($event) => pickedTerms.value = $event,
											multiple: "",
											search: termSearch,
											resolve: termResolve
										}, null, _parent, _scopeId));
										else return [(openBlock(), createBlock(unref(KPicker), {
											id,
											key: termTax.value,
											modelValue: pickedTerms.value,
											"onUpdate:modelValue": ($event) => pickedTerms.value = $event,
											multiple: "",
											search: termSearch,
											resolve: termResolve
										}, null, 8, [
											"id",
											"modelValue",
											"onUpdate:modelValue"
										]))];
									}),
									_: 1
								}, _parent, _scopeId));
								_push(`<!--]-->`);
							} else if (source.value === "archives") {
								_push(`<ul class="k-menu-archives" data-v-4fdf2b0b${_scopeId}><!--[-->`);
								ssrRenderList(archives.value, (t) => {
									_push(`<li data-v-4fdf2b0b${_scopeId}><span data-v-4fdf2b0b${_scopeId}><strong data-v-4fdf2b0b${_scopeId}>${ssrInterpolate(t.plural)}</strong> <span class="k-muted k-mono-input" data-v-4fdf2b0b${_scopeId}>${ssrInterpolate(t.path)}</span></span>`);
									_push(ssrRenderComponent(unref(KButton), {
										size: "sm",
										icon: "plus",
										onClick: ($event) => add({
											kind: "archive",
											ref: t.name,
											resolved: {
												label: t.plural,
												url: t.path,
												live: true
											}
										})
									}, {
										default: withCtx((_, _push, _parent, _scopeId) => {
											if (_push) _push(`${ssrInterpolate(unref(__)("Add"))}`);
											else return [createTextVNode(toDisplayString(unref(__)("Add")), 1)];
										}),
										_: 2
									}, _parent, _scopeId));
									_push(`</li>`);
								});
								_push(`<!--]--></ul>`);
							} else {
								_push(`<form class="k-form" data-v-4fdf2b0b${_scopeId}>`);
								_push(ssrRenderComponent(unref(KField), {
									label: unref(__)("Address"),
									help: unref(__)("https://…, /a-page, #section, mailto: or tel:")
								}, {
									default: withCtx(({ id }, _push, _parent, _scopeId) => {
										if (_push) _push(ssrRenderComponent(unref(KInput), {
											id,
											modelValue: link.url,
											"onUpdate:modelValue": ($event) => link.url = $event,
											inputmode: "url",
											spellcheck: "false",
											autocomplete: "off",
											class: "k-mono-input"
										}, null, _parent, _scopeId));
										else return [createVNode(unref(KInput), {
											id,
											modelValue: link.url,
											"onUpdate:modelValue": ($event) => link.url = $event,
											inputmode: "url",
											spellcheck: "false",
											autocomplete: "off",
											class: "k-mono-input"
										}, null, 8, [
											"id",
											"modelValue",
											"onUpdate:modelValue"
										])];
									}),
									_: 1
								}, _parent, _scopeId));
								_push(ssrRenderComponent(unref(KField), { label: unref(__)("Label") }, {
									default: withCtx(({ id }, _push, _parent, _scopeId) => {
										if (_push) _push(ssrRenderComponent(unref(KInput), {
											id,
											modelValue: link.label,
											"onUpdate:modelValue": ($event) => link.label = $event,
											maxlength: "100",
											autocomplete: "off"
										}, null, _parent, _scopeId));
										else return [createVNode(unref(KInput), {
											id,
											modelValue: link.label,
											"onUpdate:modelValue": ($event) => link.label = $event,
											maxlength: "100",
											autocomplete: "off"
										}, null, 8, [
											"id",
											"modelValue",
											"onUpdate:modelValue"
										])];
									}),
									_: 1
								}, _parent, _scopeId));
								_push(`<div data-v-4fdf2b0b${_scopeId}>`);
								_push(ssrRenderComponent(unref(KButton), {
									type: "submit",
									icon: "plus",
									disabled: !link.url.trim() || !link.label.trim()
								}, {
									default: withCtx((_, _push, _parent, _scopeId) => {
										if (_push) _push(`${ssrInterpolate(unref(__)("Add link"))}`);
										else return [createTextVNode(toDisplayString(unref(__)("Add link")), 1)];
									}),
									_: 1
								}, _parent, _scopeId));
								_push(`</div></form>`);
							}
							_push(`</div>`);
						} else return [createVNode(unref(KTabs), {
							modelValue: source.value,
							"onUpdate:modelValue": ($event) => source.value = $event,
							tabs: sources.value,
							label: unref(__)("What to add")
						}, null, 8, [
							"modelValue",
							"onUpdate:modelValue",
							"tabs",
							"label"
						]), createVNode("div", { class: "k-menu-source" }, [source.value === "entries" ? (openBlock(), createBlock(unref(KField), {
							key: 0,
							label: unref(__)("Find a page or post"),
							help: unref(__)("Titles and addresses stay in sync when the page changes.")
						}, {
							default: withCtx(({ id }) => [createVNode(unref(KPicker), {
								id,
								modelValue: picked.value,
								"onUpdate:modelValue": ($event) => picked.value = $event,
								multiple: "",
								search: entrySearch,
								resolve: entryResolve
							}, null, 8, [
								"id",
								"modelValue",
								"onUpdate:modelValue"
							])]),
							_: 1
						}, 8, ["label", "help"])) : source.value === "terms" ? (openBlock(), createBlock(Fragment, { key: 1 }, [createVNode(unref(KField), { label: unref(__)("Taxonomy") }, {
							default: withCtx(({ id }) => [createVNode(unref(KSelect), {
								id,
								modelValue: termTax.value,
								"onUpdate:modelValue": ($event) => termTax.value = $event,
								options: termOptions.value
							}, null, 8, [
								"id",
								"modelValue",
								"onUpdate:modelValue",
								"options"
							])]),
							_: 1
						}, 8, ["label"]), createVNode(unref(KField), { label: unref(__)("Find a term") }, {
							default: withCtx(({ id }) => [(openBlock(), createBlock(unref(KPicker), {
								id,
								key: termTax.value,
								modelValue: pickedTerms.value,
								"onUpdate:modelValue": ($event) => pickedTerms.value = $event,
								multiple: "",
								search: termSearch,
								resolve: termResolve
							}, null, 8, [
								"id",
								"modelValue",
								"onUpdate:modelValue"
							]))]),
							_: 1
						}, 8, ["label"])], 64)) : source.value === "archives" ? (openBlock(), createBlock("ul", {
							key: 2,
							class: "k-menu-archives"
						}, [(openBlock(true), createBlock(Fragment, null, renderList(archives.value, (t) => {
							return openBlock(), createBlock("li", { key: t.name }, [createVNode("span", null, [
								createVNode("strong", null, toDisplayString(t.plural), 1),
								createTextVNode(),
								createVNode("span", { class: "k-muted k-mono-input" }, toDisplayString(t.path), 1)
							]), createVNode(unref(KButton), {
								size: "sm",
								icon: "plus",
								onClick: ($event) => add({
									kind: "archive",
									ref: t.name,
									resolved: {
										label: t.plural,
										url: t.path,
										live: true
									}
								})
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Add")), 1)]),
								_: 1
							}, 8, ["onClick"])]);
						}), 128))])) : (openBlock(), createBlock("form", {
							key: 3,
							class: "k-form",
							onSubmit: withModifiers(addLink, ["prevent"])
						}, [
							createVNode(unref(KField), {
								label: unref(__)("Address"),
								help: unref(__)("https://…, /a-page, #section, mailto: or tel:")
							}, {
								default: withCtx(({ id }) => [createVNode(unref(KInput), {
									id,
									modelValue: link.url,
									"onUpdate:modelValue": ($event) => link.url = $event,
									inputmode: "url",
									spellcheck: "false",
									autocomplete: "off",
									class: "k-mono-input"
								}, null, 8, [
									"id",
									"modelValue",
									"onUpdate:modelValue"
								])]),
								_: 1
							}, 8, ["label", "help"]),
							createVNode(unref(KField), { label: unref(__)("Label") }, {
								default: withCtx(({ id }) => [createVNode(unref(KInput), {
									id,
									modelValue: link.label,
									"onUpdate:modelValue": ($event) => link.label = $event,
									maxlength: "100",
									autocomplete: "off"
								}, null, 8, [
									"id",
									"modelValue",
									"onUpdate:modelValue"
								])]),
								_: 1
							}, 8, ["label"]),
							createVNode("div", null, [createVNode(unref(KButton), {
								type: "submit",
								icon: "plus",
								disabled: !link.url.trim() || !link.label.trim()
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Add link")), 1)]),
								_: 1
							}, 8, ["disabled"])])
						], 32))])];
					}),
					_: 1
				}, _parent));
				_push(ssrRenderComponent(unref(KCard), {
					coord: "B2",
					class: "k-span-2",
					flush: ""
				}, {
					title: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`<label class="k-sr-only" for="k-menu-name" data-v-4fdf2b0b${_scopeId}>${ssrInterpolate(unref(__)("Menu name"))}</label><input id="k-menu-name"${ssrRenderAttr("value", name.value)} class="k-menu-name" maxlength="100" autocomplete="off" data-v-4fdf2b0b${_scopeId}>`);
						else return [createVNode("label", {
							class: "k-sr-only",
							for: "k-menu-name"
						}, toDisplayString(unref(__)("Menu name")), 1), withDirectives(createVNode("input", {
							id: "k-menu-name",
							"onUpdate:modelValue": ($event) => name.value = $event,
							class: "k-menu-name",
							maxlength: "100",
							autocomplete: "off"
						}, null, 8, ["onUpdate:modelValue"]), [[vModelText, name.value]])];
					}),
					actions: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) {
							if ((unref(overview).data.value?.menus ?? []).length > 1) _push(ssrRenderComponent(unref(KSelect), {
								"model-value": String(selectedId.value),
								options: menuOptions.value.slice(1),
								"aria-label": unref(__)("Edit another menu"),
								class: "k-menu-switch",
								"onUpdate:modelValue": ($event) => open(Number($event))
							}, null, _parent, _scopeId));
							else _push(`<!---->`);
						} else return [(unref(overview).data.value?.menus ?? []).length > 1 ? (openBlock(), createBlock(unref(KSelect), {
							key: 0,
							"model-value": String(selectedId.value),
							options: menuOptions.value.slice(1),
							"aria-label": unref(__)("Edit another menu"),
							class: "k-menu-switch",
							"onUpdate:modelValue": ($event) => open(Number($event))
						}, null, 8, [
							"model-value",
							"options",
							"aria-label",
							"onUpdate:modelValue"
						])) : createCommentVNode("v-if", true)];
					}),
					footer: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) {
							_push(`<div class="k-form__actions" data-v-4fdf2b0b${_scopeId}>`);
							_push(ssrRenderComponent(unref(KButton), {
								variant: "ghost",
								icon: "trash",
								class: "k-menu-delete",
								onClick: removeMenu
							}, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate(unref(__)("Delete menu"))}`);
									else return [createTextVNode(toDisplayString(unref(__)("Delete menu")), 1)];
								}),
								_: 1
							}, _parent, _scopeId));
							if (dirty.value) _push(`<span class="k-muted k-small" data-v-4fdf2b0b${_scopeId}>${ssrInterpolate(unref(__)("Unsaved changes"))}</span>`);
							else _push(`<!---->`);
							_push(ssrRenderComponent(unref(KButton), {
								variant: "primary",
								loading: saving.value,
								disabled: !dirty.value,
								onClick: save
							}, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate(unref(__)("Save menu"))}`);
									else return [createTextVNode(toDisplayString(unref(__)("Save menu")), 1)];
								}),
								_: 1
							}, _parent, _scopeId));
							_push(`</div>`);
						} else return [createVNode("div", { class: "k-form__actions" }, [
							createVNode(unref(KButton), {
								variant: "ghost",
								icon: "trash",
								class: "k-menu-delete",
								onClick: removeMenu
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Delete menu")), 1)]),
								_: 1
							}),
							dirty.value ? (openBlock(), createBlock("span", {
								key: 0,
								class: "k-muted k-small"
							}, toDisplayString(unref(__)("Unsaved changes")), 1)) : createCommentVNode("v-if", true),
							createVNode(unref(KButton), {
								variant: "primary",
								loading: saving.value,
								disabled: !dirty.value,
								onClick: save
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Save menu")), 1)]),
								_: 1
							}, 8, ["loading", "disabled"])
						])];
					}),
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) {
							if (errors.value.name) _push(`<p class="k-field__error k-menu-error" data-v-4fdf2b0b${_scopeId}>${ssrInterpolate(errors.value.name)}</p>`);
							else _push(`<!---->`);
							if (errors.value.items) _push(`<p class="k-field__error k-menu-error" data-v-4fdf2b0b${_scopeId}>${ssrInterpolate(errors.value.items)}</p>`);
							else _push(`<!---->`);
							if (items.value.length) {
								_push(`<ol class="k-menu-tree"${ssrRenderAttr("aria-label", unref(__)("Menu items"))} data-v-4fdf2b0b${_scopeId}><!--[-->`);
								ssrRenderList(items.value, (it, i) => {
									_push(`<li class="${ssrRenderClass([{
										"is-hidden": hidden(it),
										"has-error": rowErrors.value[it.key],
										"is-nested": it.depth > 0
									}, "k-menu-row"])}" style="${ssrRenderStyle({ "--k-depth": it.depth })}"${ssrRenderAttr("data-menu-row", it.key)} data-v-4fdf2b0b${_scopeId}><div class="k-menu-row__head" data-v-4fdf2b0b${_scopeId}><button type="button" class="k-menu-row__title"${ssrRenderAttr("aria-expanded", expanded.has(it.key) ? "true" : "false")}${ssrRenderAttr("aria-label", unref(__)("{title}, level {level}. Alt and arrow keys move it.", {
										title: title(it),
										level: it.depth + 1
									}))} data-v-4fdf2b0b${_scopeId}>`);
									_push(ssrRenderComponent(unref(KIcon), { name: expanded.has(it.key) ? "caret-down" : "caret-right" }, null, _parent, _scopeId));
									_push(`<span class="k-menu-row__label" data-v-4fdf2b0b${_scopeId}>${ssrInterpolate(title(it))}</span></button><span class="k-menu-row__meta" data-v-4fdf2b0b${_scopeId}>`);
									if (hidden(it)) _push(ssrRenderComponent(unref(KBadge), { tone: "warning" }, {
										default: withCtx((_, _push, _parent, _scopeId) => {
											if (_push) _push(`${ssrInterpolate(unref(__)("Hidden: not published"))}`);
											else return [createTextVNode(toDisplayString(unref(__)("Hidden: not published")), 1)];
										}),
										_: 2
									}, _parent, _scopeId));
									else _push(`<!---->`);
									_push(`<span class="k-muted k-small" data-v-4fdf2b0b${_scopeId}>${ssrInterpolate(it.resolved?.type_label || KIND[it.kind])}</span></span><span class="k-menu-row__tools" data-v-4fdf2b0b${_scopeId}>`);
									_push(ssrRenderComponent(unref(KButton), {
										size: "sm",
										variant: "ghost",
										icon: "arrow-up",
										disabled: prevSibling(i) < 0,
										"aria-label": unref(__)("Move up"),
										onClick: ($event) => up(i)
									}, null, _parent, _scopeId));
									_push(ssrRenderComponent(unref(KButton), {
										size: "sm",
										variant: "ghost",
										icon: "arrow-down",
										disabled: nextSibling(i) < 0,
										"aria-label": unref(__)("Move down"),
										onClick: ($event) => down(i)
									}, null, _parent, _scopeId));
									_push(ssrRenderComponent(unref(KButton), {
										size: "sm",
										variant: "ghost",
										icon: "arrow-line-left",
										disabled: it.depth === 0,
										"aria-label": unref(__)("Move out a level"),
										onClick: ($event) => outdent(i)
									}, null, _parent, _scopeId));
									_push(ssrRenderComponent(unref(KButton), {
										size: "sm",
										variant: "ghost",
										icon: "arrow-line-right",
										disabled: prevSibling(i) < 0,
										"aria-label": unref(__)("Nest under the item above"),
										onClick: ($event) => indent(i)
									}, null, _parent, _scopeId));
									_push(ssrRenderComponent(unref(KButton), {
										size: "sm",
										variant: "ghost",
										icon: "x",
										"aria-label": unref(__)("Remove {title}", { title: title(it) }),
										onClick: ($event) => remove(i)
									}, null, _parent, _scopeId));
									_push(`</span></div>`);
									if (rowErrors.value[it.key]) _push(`<p class="k-field__error k-menu-row__error" data-v-4fdf2b0b${_scopeId}>${ssrInterpolate(rowErrors.value[it.key])}</p>`);
									else _push(`<!---->`);
									if (expanded.has(it.key)) {
										_push(`<div class="k-menu-row__body" data-v-4fdf2b0b${_scopeId}>`);
										_push(ssrRenderComponent(unref(KField), {
											label: unref(__)("Label"),
											help: it.kind === "url" ? "" : unref(__)("Leave empty to use “{title}”.", { title: it.resolved?.label ?? "" })
										}, {
											default: withCtx(({ id }, _push, _parent, _scopeId) => {
												if (_push) _push(ssrRenderComponent(unref(KInput), {
													id,
													modelValue: it.label,
													"onUpdate:modelValue": ($event) => it.label = $event,
													maxlength: "100",
													autocomplete: "off"
												}, null, _parent, _scopeId));
												else return [createVNode(unref(KInput), {
													id,
													modelValue: it.label,
													"onUpdate:modelValue": ($event) => it.label = $event,
													maxlength: "100",
													autocomplete: "off"
												}, null, 8, [
													"id",
													"modelValue",
													"onUpdate:modelValue"
												])];
											}),
											_: 2
										}, _parent, _scopeId));
										if (it.kind === "url") _push(ssrRenderComponent(unref(KField), { label: unref(__)("Address") }, {
											default: withCtx(({ id }, _push, _parent, _scopeId) => {
												if (_push) _push(ssrRenderComponent(unref(KInput), {
													id,
													modelValue: it.url,
													"onUpdate:modelValue": ($event) => it.url = $event,
													spellcheck: "false",
													autocomplete: "off",
													class: "k-mono-input"
												}, null, _parent, _scopeId));
												else return [createVNode(unref(KInput), {
													id,
													modelValue: it.url,
													"onUpdate:modelValue": ($event) => it.url = $event,
													spellcheck: "false",
													autocomplete: "off",
													class: "k-mono-input"
												}, null, 8, [
													"id",
													"modelValue",
													"onUpdate:modelValue"
												])];
											}),
											_: 2
										}, _parent, _scopeId));
										else _push(`<p class="k-muted k-small" data-v-4fdf2b0b${_scopeId}>${ssrInterpolate(unref(__)("Links to"))} <span class="k-mono-input" data-v-4fdf2b0b${_scopeId}>${ssrInterpolate(it.resolved?.url ?? "—")}</span></p>`);
										_push(`<label class="k-choice" data-v-4fdf2b0b${_scopeId}><input${ssrIncludeBooleanAttr(Array.isArray(it.new_tab) ? ssrLooseContain(it.new_tab, null) : it.new_tab) ? " checked" : ""} type="checkbox" data-v-4fdf2b0b${_scopeId}> <span data-v-4fdf2b0b${_scopeId}>${ssrInterpolate(unref(__)("Open in a new tab"))}</span></label></div>`);
									} else _push(`<!---->`);
									_push(`</li>`);
								});
								_push(`<!--]--></ol>`);
							} else _push(`<p class="k-card__body k-muted" data-v-4fdf2b0b${_scopeId}>${ssrInterpolate(unref(__)("This menu is empty. Add pages, posts or links from the left."))}</p>`);
						} else return [
							errors.value.name ? (openBlock(), createBlock("p", {
								key: 0,
								class: "k-field__error k-menu-error"
							}, toDisplayString(errors.value.name), 1)) : createCommentVNode("v-if", true),
							errors.value.items ? (openBlock(), createBlock("p", {
								key: 1,
								class: "k-field__error k-menu-error"
							}, toDisplayString(errors.value.items), 1)) : createCommentVNode("v-if", true),
							items.value.length ? (openBlock(), createBlock("ol", {
								key: 2,
								class: "k-menu-tree",
								"aria-label": unref(__)("Menu items")
							}, [(openBlock(true), createBlock(Fragment, null, renderList(items.value, (it, i) => {
								return openBlock(), createBlock("li", {
									key: it.key,
									class: ["k-menu-row", {
										"is-hidden": hidden(it),
										"has-error": rowErrors.value[it.key],
										"is-nested": it.depth > 0
									}],
									style: { "--k-depth": it.depth },
									"data-menu-row": it.key
								}, [
									createVNode("div", { class: "k-menu-row__head" }, [
										createVNode("button", {
											type: "button",
											class: "k-menu-row__title",
											"aria-expanded": expanded.has(it.key) ? "true" : "false",
											"aria-label": unref(__)("{title}, level {level}. Alt and arrow keys move it.", {
												title: title(it),
												level: it.depth + 1
											}),
											onClick: ($event) => toggle(it.key),
											onKeydown: ($event) => keydown($event, i)
										}, [createVNode(unref(KIcon), { name: expanded.has(it.key) ? "caret-down" : "caret-right" }, null, 8, ["name"]), createVNode("span", { class: "k-menu-row__label" }, toDisplayString(title(it)), 1)], 40, [
											"aria-expanded",
											"aria-label",
											"onClick",
											"onKeydown"
										]),
										createVNode("span", { class: "k-menu-row__meta" }, [hidden(it) ? (openBlock(), createBlock(unref(KBadge), {
											key: 0,
											tone: "warning"
										}, {
											default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Hidden: not published")), 1)]),
											_: 1
										})) : createCommentVNode("v-if", true), createVNode("span", { class: "k-muted k-small" }, toDisplayString(it.resolved?.type_label || KIND[it.kind]), 1)]),
										createVNode("span", { class: "k-menu-row__tools" }, [
											createVNode(unref(KButton), {
												size: "sm",
												variant: "ghost",
												icon: "arrow-up",
												disabled: prevSibling(i) < 0,
												"aria-label": unref(__)("Move up"),
												onClick: ($event) => up(i)
											}, null, 8, [
												"disabled",
												"aria-label",
												"onClick"
											]),
											createVNode(unref(KButton), {
												size: "sm",
												variant: "ghost",
												icon: "arrow-down",
												disabled: nextSibling(i) < 0,
												"aria-label": unref(__)("Move down"),
												onClick: ($event) => down(i)
											}, null, 8, [
												"disabled",
												"aria-label",
												"onClick"
											]),
											createVNode(unref(KButton), {
												size: "sm",
												variant: "ghost",
												icon: "arrow-line-left",
												disabled: it.depth === 0,
												"aria-label": unref(__)("Move out a level"),
												onClick: ($event) => outdent(i)
											}, null, 8, [
												"disabled",
												"aria-label",
												"onClick"
											]),
											createVNode(unref(KButton), {
												size: "sm",
												variant: "ghost",
												icon: "arrow-line-right",
												disabled: prevSibling(i) < 0,
												"aria-label": unref(__)("Nest under the item above"),
												onClick: ($event) => indent(i)
											}, null, 8, [
												"disabled",
												"aria-label",
												"onClick"
											]),
											createVNode(unref(KButton), {
												size: "sm",
												variant: "ghost",
												icon: "x",
												"aria-label": unref(__)("Remove {title}", { title: title(it) }),
												onClick: ($event) => remove(i)
											}, null, 8, ["aria-label", "onClick"])
										])
									]),
									rowErrors.value[it.key] ? (openBlock(), createBlock("p", {
										key: 0,
										class: "k-field__error k-menu-row__error"
									}, toDisplayString(rowErrors.value[it.key]), 1)) : createCommentVNode("v-if", true),
									expanded.has(it.key) ? (openBlock(), createBlock("div", {
										key: 1,
										class: "k-menu-row__body"
									}, [
										createVNode(unref(KField), {
											label: unref(__)("Label"),
											help: it.kind === "url" ? "" : unref(__)("Leave empty to use “{title}”.", { title: it.resolved?.label ?? "" })
										}, {
											default: withCtx(({ id }) => [createVNode(unref(KInput), {
												id,
												modelValue: it.label,
												"onUpdate:modelValue": ($event) => it.label = $event,
												maxlength: "100",
												autocomplete: "off"
											}, null, 8, [
												"id",
												"modelValue",
												"onUpdate:modelValue"
											])]),
											_: 2
										}, 1032, ["label", "help"]),
										it.kind === "url" ? (openBlock(), createBlock(unref(KField), {
											key: 0,
											label: unref(__)("Address")
										}, {
											default: withCtx(({ id }) => [createVNode(unref(KInput), {
												id,
												modelValue: it.url,
												"onUpdate:modelValue": ($event) => it.url = $event,
												spellcheck: "false",
												autocomplete: "off",
												class: "k-mono-input"
											}, null, 8, [
												"id",
												"modelValue",
												"onUpdate:modelValue"
											])]),
											_: 2
										}, 1032, ["label"])) : (openBlock(), createBlock("p", {
											key: 1,
											class: "k-muted k-small"
										}, [createTextVNode(toDisplayString(unref(__)("Links to")) + " ", 1), createVNode("span", { class: "k-mono-input" }, toDisplayString(it.resolved?.url ?? "—"), 1)])),
										createVNode("label", { class: "k-choice" }, [
											withDirectives(createVNode("input", {
												"onUpdate:modelValue": ($event) => it.new_tab = $event,
												type: "checkbox"
											}, null, 8, ["onUpdate:modelValue"]), [[vModelCheckbox, it.new_tab]]),
											createTextVNode(),
											createVNode("span", null, toDisplayString(unref(__)("Open in a new tab")), 1)
										])
									])) : createCommentVNode("v-if", true)
								], 14, ["data-menu-row"]);
							}), 128))], 8, ["aria-label"])) : (openBlock(), createBlock("p", {
								key: 3,
								class: "k-card__body k-muted"
							}, toDisplayString(unref(__)("This menu is empty. Add pages, posts or links from the left.")), 1))
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
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/admin/menus/Index.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
var Index_default = /*#__PURE__*/ _plugin_vue_export_helper_default(_sfc_main, [["__scopeId", "data-v-4fdf2b0b"]]);
export { Index_default as default };
