import { t as _plugin_vue_export_helper_default } from "../../../chunks/_plugin-vue_export-helper-DjkACiAr.mjs";
import { Fragment, computed, createBlock, createCommentVNode, createSlots, createTextVNode, createVNode, openBlock, reactive, ref, renderList, toDisplayString, unref, useSSRContext, vModelText, watch, withCtx, withDirectives, withModifiers } from "vue";
import { ssrInterpolate, ssrRenderAttr, ssrRenderComponent, ssrRenderList } from "vue/server-renderer";
import { Head } from "@kestello/app";
import { ApiError, __, confirm, notify, useApi } from "@kestello/sdk";
import { KBadge, KButton, KCard, KField, KInput, KModal, KPageHeader, KSelect, KTable, KTabs, KTime } from "@kestello/ui";
// core/resources/js/pages/admin/redirects/Index.vue
var _sfc_main = /*@__PURE__*/ Object.assign({ layout: "Admin" }, {
	__name: "Index",
	__ssrInlineRender: true,
	setup(__props) {
		/**
		* Appearance → Redirects: rules for addresses that no longer exist, and
		* the addresses visitors asked for that found nothing.
		*/
		const api = useApi();
		const tab = ref("rules");
		const MATCHES = [
			{
				value: "exact",
				label: __("This address")
			},
			{
				value: "prefix",
				label: __("This address and everything below it")
			},
			{
				value: "regex",
				label: __("A pattern (regular expression)")
			}
		];
		const STATUSES = [
			{
				value: 301,
				label: __("Moved for good (301)")
			},
			{
				value: 302,
				label: __("Moved for now (302)")
			},
			{
				value: 307,
				label: __("Moved for now, keep the method (307)")
			},
			{
				value: 308,
				label: __("Moved for good, keep the method (308)")
			},
			{
				value: 410,
				label: __("Gone (410)")
			}
		];
		const ORIGINS = [
			{
				value: "",
				label: __("Added any way")
			},
			{
				value: "manual",
				label: __("Added here")
			},
			{
				value: "auto",
				label: __("When a page moved")
			},
			{
				value: "import",
				label: __("Imported")
			}
		];
		const rules = reactive({
			rows: [],
			total: null,
			page: 1,
			lastPage: 1,
			loading: false,
			search: "",
			origin: ""
		});
		let seq = 0;
		async function loadRules(page = 1) {
			const mine = ++seq;
			rules.loading = true;
			try {
				const body = await api.get("/redirects", {
					page,
					per_page: 50,
					search: rules.search.trim() || void 0,
					origin: rules.origin || void 0
				});
				if (mine !== seq) return;
				Object.assign(rules, {
					rows: body.data,
					total: body.meta.total,
					page: body.meta.page,
					lastPage: body.meta.last_page
				});
			} catch (e) {
				notify.error(e);
			} finally {
				if (mine === seq) rules.loading = false;
			}
		}
		let debounce = null;
		watch(() => rules.search, () => {
			clearTimeout(debounce);
			debounce = setTimeout(() => loadRules(), 250);
		});
		watch(() => rules.origin, () => loadRules());
		loadRules();
		const ruleColumns = [
			{
				key: "source",
				label: __("From")
			},
			{
				key: "target",
				label: __("To")
			},
			{
				key: "status",
				label: __("Kind")
			},
			{
				key: "hits",
				label: __("Used"),
				align: "end"
			},
			{
				key: "last_hit_at",
				label: __("Last used")
			},
			{
				key: "actions",
				label: "",
				align: "end"
			}
		];
		const statusShort = (s) => ({
			301: __("Permanent"),
			302: __("Temporary"),
			307: __("Temporary"),
			308: __("Permanent"),
			410: __("Gone")
		})[s] ?? s;
		const missing = reactive({
			rows: [],
			total: null,
			page: 1,
			lastPage: 1,
			loading: false,
			sort: "hits"
		});
		async function loadMissing(page = 1) {
			missing.loading = true;
			try {
				const body = await api.get("/redirects/not-found", {
					page,
					per_page: 50,
					sort: missing.sort
				});
				Object.assign(missing, {
					rows: body.data,
					total: body.meta.total,
					page: body.meta.page,
					lastPage: body.meta.last_page
				});
			} catch (e) {
				notify.error(e);
			} finally {
				missing.loading = false;
			}
		}
		watch(() => missing.sort, () => loadMissing());
		loadMissing();
		const missingColumns = [
			{
				key: "path",
				label: __("Address")
			},
			{
				key: "hits",
				label: __("Asked for"),
				align: "end"
			},
			{
				key: "last_seen_at",
				label: __("Last time")
			},
			{
				key: "referrer",
				label: __("Linked from")
			},
			{
				key: "actions",
				label: "",
				align: "end"
			}
		];
		async function forget(row) {
			try {
				await api.delete("/redirects/not-found", { query: { id: row.id } });
				missing.rows = missing.rows.filter((r) => r.id !== row.id);
				missing.total = Math.max(0, (missing.total ?? 1) - 1);
			} catch (e) {
				notify.error(e);
			}
		}
		async function clearLog() {
			if (!await confirm({
				title: __("Clear the list?"),
				message: __("Every logged address is forgotten. New ones appear as visitors find them."),
				confirmLabel: __("Clear the list"),
				danger: true
			})) return;
			try {
				await api.delete("/redirects/not-found");
				await loadMissing();
				notify.success(__("The list is empty."));
			} catch (e) {
				notify.error(e);
			}
		}
		const tabs = computed(() => [{
			id: "rules",
			label: __("Redirects"),
			count: rules.total
		}, {
			id: "missing",
			label: __("Not found"),
			count: missing.total
		}]);
		const editing = ref(null);
		const form = reactive({
			source: "",
			match: "exact",
			target: "",
			status: 301,
			note: ""
		});
		const errors = ref({});
		const saving = ref(false);
		function openRule(rule = null, source = "") {
			Object.assign(form, rule ? {
				source: rule.source,
				match: rule.match,
				target: rule.target,
				status: rule.status,
				note: rule.note
			} : {
				source,
				match: "exact",
				target: "",
				status: 301,
				note: ""
			});
			errors.value = {};
			editing.value = rule ? rule.id : "new";
		}
		async function saveRule() {
			saving.value = true;
			errors.value = {};
			const body = {
				...form,
				status: Number(form.status),
				target: Number(form.status) === 410 ? "" : form.target.trim(),
				source: form.source.trim()
			};
			try {
				if (editing.value === "new") await api.post("/redirects", body);
				else await api.patch(`/redirects/${editing.value}`, body);
				notify.success(editing.value === "new" ? __("Redirect added.") : __("Redirect saved."));
				editing.value = null;
				await Promise.all([loadRules(rules.page), loadMissing(missing.page)]);
			} catch (e) {
				if (e instanceof ApiError && e.status === 422) errors.value = e.fieldErrors();
				else notify.error(e);
			} finally {
				saving.value = false;
			}
		}
		async function removeRule(rule) {
			if (!await confirm({
				title: __("Remove this redirect?"),
				message: __("Visitors to {from} will see the \"not found\" page.", { from: rule.source }),
				confirmLabel: __("Remove"),
				danger: true
			})) return;
			try {
				await api.delete(`/redirects/${rule.id}`);
				notify.success(__("Redirect removed."));
				await loadRules(rules.rows.length === 1 && rules.page > 1 ? rules.page - 1 : rules.page);
			} catch (e) {
				notify.error(e);
			}
		}
		const sourceHelp = computed(() => ({
			exact: __("An address on this site, such as /old-page."),
			prefix: __("/old-blog also sends /old-blog/any/post to the same place below the new address."),
			regex: __("Matched against the whole address, such as ^/news/(\\d+)/(.+)$. Use (…) groups and $1, $2 in the target.")
		})[form.match]);
		const importing = ref(false);
		const csv = ref("");
		const importResult = ref(null);
		async function readFile(event) {
			const file = event.target.files?.[0];
			if (file) csv.value = await file.text();
		}
		async function runImport() {
			saving.value = true;
			try {
				const { data } = await api.post("/redirects/import", { csv: csv.value });
				importResult.value = data;
				await loadRules();
				if (!data.errors.length) {
					importing.value = false;
					notify.success(__("{created} added, {updated} updated.", {
						created: data.created,
						updated: data.updated
					}));
				}
			} catch (e) {
				notify.error(e);
			} finally {
				saving.value = false;
			}
		}
		async function exportCsv() {
			try {
				const response = await fetch("/api/v1/redirects/export", {
					credentials: "same-origin",
					headers: { "X-Requested-With": "XMLHttpRequest" }
				});
				if (!response.ok) throw new ApiError(response.status, { title: response.statusText });
				const url = URL.createObjectURL(await response.blob());
				Object.assign(document.createElement("a"), {
					href: url,
					download: `redirects-${(/* @__PURE__ */ new Date()).toISOString().slice(0, 10)}.csv`
				}).click();
				setTimeout(() => URL.revokeObjectURL(url), 1e3);
			} catch (e) {
				notify.error(e);
			}
		}
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<!--[-->`);
			_push(ssrRenderComponent(unref(Head), { title: unref(__)("Redirects") }, null, _parent));
			_push(ssrRenderComponent(unref(KPageHeader), {
				title: unref(__)("Redirects"),
				description: unref(__)("Send visitors from addresses that no longer exist to the right place. When a published page moves, its old address is added here for you.")
			}, {
				actions: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(ssrRenderComponent(unref(KButton), {
							icon: "download",
							disabled: rules.total === 0 && !rules.search && !rules.origin,
							onClick: exportCsv
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Export"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Export")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						_push(ssrRenderComponent(unref(KButton), {
							icon: "upload",
							onClick: ($event) => {
								importing.value = true;
								importResult.value = null;
								csv.value = "";
							}
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Import"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Import")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						_push(ssrRenderComponent(unref(KButton), {
							variant: "primary",
							icon: "plus",
							onClick: ($event) => openRule()
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Add a redirect"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Add a redirect")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
					} else return [
						createVNode(unref(KButton), {
							icon: "download",
							disabled: rules.total === 0 && !rules.search && !rules.origin,
							onClick: exportCsv
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Export")), 1)]),
							_: 1
						}, 8, ["disabled"]),
						createVNode(unref(KButton), {
							icon: "upload",
							onClick: ($event) => {
								importing.value = true;
								importResult.value = null;
								csv.value = "";
							}
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Import")), 1)]),
							_: 1
						}, 8, ["onClick"]),
						createVNode(unref(KButton), {
							variant: "primary",
							icon: "plus",
							onClick: ($event) => openRule()
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Add a redirect")), 1)]),
							_: 1
						}, 8, ["onClick"])
					];
				}),
				_: 1
			}, _parent));
			_push(ssrRenderComponent(unref(KTabs), {
				modelValue: tab.value,
				"onUpdate:modelValue": ($event) => tab.value = $event,
				tabs: tabs.value,
				label: unref(__)("Redirects")
			}, null, _parent));
			if (tab.value === "rules") {
				_push(`<!--[--><div class="k-toolbar" role="search" data-v-a4ae156a>`);
				_push(ssrRenderComponent(unref(KField), {
					label: unref(__)("Search"),
					class: "k-toolbar__grow"
				}, {
					default: withCtx(({ id }, _push, _parent, _scopeId) => {
						if (_push) _push(ssrRenderComponent(unref(KInput), {
							id,
							modelValue: rules.search,
							"onUpdate:modelValue": ($event) => rules.search = $event,
							type: "search",
							autocomplete: "off"
						}, null, _parent, _scopeId));
						else return [createVNode(unref(KInput), {
							id,
							modelValue: rules.search,
							"onUpdate:modelValue": ($event) => rules.search = $event,
							type: "search",
							autocomplete: "off"
						}, null, 8, [
							"id",
							"modelValue",
							"onUpdate:modelValue"
						])];
					}),
					_: 1
				}, _parent));
				_push(ssrRenderComponent(unref(KField), { label: unref(__)("Added") }, {
					default: withCtx(({ id }, _push, _parent, _scopeId) => {
						if (_push) _push(ssrRenderComponent(unref(KSelect), {
							id,
							modelValue: rules.origin,
							"onUpdate:modelValue": ($event) => rules.origin = $event,
							options: ORIGINS
						}, null, _parent, _scopeId));
						else return [createVNode(unref(KSelect), {
							id,
							modelValue: rules.origin,
							"onUpdate:modelValue": ($event) => rules.origin = $event,
							options: ORIGINS
						}, null, 8, [
							"id",
							"modelValue",
							"onUpdate:modelValue"
						])];
					}),
					_: 1
				}, _parent));
				_push(`</div>`);
				_push(ssrRenderComponent(unref(KCard), {
					coord: "R1",
					title: unref(__)("Redirects"),
					flush: ""
				}, createSlots({
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(ssrRenderComponent(unref(KTable), {
							columns: ruleColumns,
							rows: rules.rows,
							loading: rules.loading && !rules.rows.length,
							empty: rules.search || rules.origin ? unref(__)("No redirect matches.") : unref(__)("No redirects yet"),
							"empty-text": rules.search || rules.origin ? "" : unref(__)("Moving a published page adds one, or add one yourself.")
						}, {
							"cell-source": withCtx(({ row }, _push, _parent, _scopeId) => {
								if (_push) {
									_push(`<div class="k-redirect__path" data-v-a4ae156a${_scopeId}>${ssrInterpolate(row.source)}</div>`);
									if (row.match !== "exact" || row.origin !== "manual" || row.note) {
										_push(`<div class="k-muted k-small" data-v-a4ae156a${_scopeId}>`);
										if (row.match === "prefix") _push(`<!--[-->${ssrInterpolate(unref(__)("and everything below"))}<!--]-->`);
										else if (row.match === "regex") _push(`<!--[-->${ssrInterpolate(unref(__)("pattern"))}<!--]-->`);
										else _push(`<!---->`);
										if (row.origin === "auto") _push(`<!--[-->${ssrInterpolate(row.match !== "exact" ? " · " : "")}${ssrInterpolate(unref(__)("page moved"))}<!--]-->`);
										else if (row.origin === "import") _push(`<!--[-->${ssrInterpolate(row.match !== "exact" ? " · " : "")}${ssrInterpolate(unref(__)("imported"))}<!--]-->`);
										else _push(`<!---->`);
										if (row.note) _push(`<!--[--> · ${ssrInterpolate(row.note)}<!--]-->`);
										else _push(`<!---->`);
										_push(`</div>`);
									} else _push(`<!---->`);
								} else return [createVNode("div", { class: "k-redirect__path" }, toDisplayString(row.source), 1), row.match !== "exact" || row.origin !== "manual" || row.note ? (openBlock(), createBlock("div", {
									key: 0,
									class: "k-muted k-small"
								}, [
									row.match === "prefix" ? (openBlock(), createBlock(Fragment, { key: 0 }, [createTextVNode(toDisplayString(unref(__)("and everything below")), 1)], 64)) : row.match === "regex" ? (openBlock(), createBlock(Fragment, { key: 1 }, [createTextVNode(toDisplayString(unref(__)("pattern")), 1)], 64)) : createCommentVNode("v-if", true),
									row.origin === "auto" ? (openBlock(), createBlock(Fragment, { key: 2 }, [createTextVNode(toDisplayString(row.match !== "exact" ? " · " : "") + toDisplayString(unref(__)("page moved")), 1)], 64)) : row.origin === "import" ? (openBlock(), createBlock(Fragment, { key: 3 }, [createTextVNode(toDisplayString(row.match !== "exact" ? " · " : "") + toDisplayString(unref(__)("imported")), 1)], 64)) : createCommentVNode("v-if", true),
									row.note ? (openBlock(), createBlock(Fragment, { key: 4 }, [createTextVNode(" · " + toDisplayString(row.note), 1)], 64)) : createCommentVNode("v-if", true)
								])) : createCommentVNode("v-if", true)];
							}),
							"cell-target": withCtx(({ row }, _push, _parent, _scopeId) => {
								if (_push) {
									if (row.status === 410) _push(`<span class="k-muted" data-v-a4ae156a${_scopeId}>${ssrInterpolate(unref(__)("No new address"))}</span>`);
									else _push(`<a${ssrRenderAttr("href", row.target)} class="k-link k-redirect__path" target="_blank" rel="noopener noreferrer" data-v-a4ae156a${_scopeId}>${ssrInterpolate(row.target)}</a>`);
								} else return [row.status === 410 ? (openBlock(), createBlock("span", {
									key: 0,
									class: "k-muted"
								}, toDisplayString(unref(__)("No new address")), 1)) : (openBlock(), createBlock("a", {
									key: 1,
									href: row.target,
									class: "k-link k-redirect__path",
									target: "_blank",
									rel: "noopener noreferrer"
								}, toDisplayString(row.target), 9, ["href"]))];
							}),
							"cell-status": withCtx(({ row }, _push, _parent, _scopeId) => {
								if (_push) _push(ssrRenderComponent(unref(KBadge), null, {
									default: withCtx((_, _push, _parent, _scopeId) => {
										if (_push) _push(`${ssrInterpolate(statusShort(row.status))} ${ssrInterpolate(row.status)}`);
										else return [createTextVNode(toDisplayString(statusShort(row.status)) + " " + toDisplayString(row.status), 1)];
									}),
									_: 2
								}, _parent, _scopeId));
								else return [createVNode(unref(KBadge), null, {
									default: withCtx(() => [createTextVNode(toDisplayString(statusShort(row.status)) + " " + toDisplayString(row.status), 1)]),
									_: 2
								}, 1024)];
							}),
							"cell-hits": withCtx(({ row }, _push, _parent, _scopeId) => {
								if (_push) _push(`<span class="k-num" data-v-a4ae156a${_scopeId}>${ssrInterpolate(row.hits.toLocaleString())}</span>`);
								else return [createVNode("span", { class: "k-num" }, toDisplayString(row.hits.toLocaleString()), 1)];
							}),
							"cell-last_hit_at": withCtx(({ row }, _push, _parent, _scopeId) => {
								if (_push) {
									if (row.last_hit_at) _push(ssrRenderComponent(unref(KTime), { value: row.last_hit_at }, null, _parent, _scopeId));
									else _push(`<span class="k-muted" data-v-a4ae156a${_scopeId}>${ssrInterpolate(unref(__)("Never"))}</span>`);
								} else return [row.last_hit_at ? (openBlock(), createBlock(unref(KTime), {
									key: 0,
									value: row.last_hit_at
								}, null, 8, ["value"])) : (openBlock(), createBlock("span", {
									key: 1,
									class: "k-muted"
								}, toDisplayString(unref(__)("Never")), 1))];
							}),
							"cell-actions": withCtx(({ row }, _push, _parent, _scopeId) => {
								if (_push) {
									_push(`<div class="k-row-actions" data-v-a4ae156a${_scopeId}>`);
									_push(ssrRenderComponent(unref(KButton), {
										size: "sm",
										variant: "ghost",
										icon: "pencil",
										onClick: ($event) => openRule(row)
									}, {
										default: withCtx((_, _push, _parent, _scopeId) => {
											if (_push) _push(`${ssrInterpolate(unref(__)("Edit"))}`);
											else return [createTextVNode(toDisplayString(unref(__)("Edit")), 1)];
										}),
										_: 2
									}, _parent, _scopeId));
									_push(ssrRenderComponent(unref(KButton), {
										size: "sm",
										variant: "ghost",
										icon: "trash",
										"aria-label": unref(__)("Remove the redirect from {from}", { from: row.source }),
										onClick: ($event) => removeRule(row)
									}, {
										default: withCtx((_, _push, _parent, _scopeId) => {
											if (_push) _push(`${ssrInterpolate(unref(__)("Remove"))}`);
											else return [createTextVNode(toDisplayString(unref(__)("Remove")), 1)];
										}),
										_: 2
									}, _parent, _scopeId));
									_push(`</div>`);
								} else return [createVNode("div", { class: "k-row-actions" }, [createVNode(unref(KButton), {
									size: "sm",
									variant: "ghost",
									icon: "pencil",
									onClick: ($event) => openRule(row)
								}, {
									default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Edit")), 1)]),
									_: 1
								}, 8, ["onClick"]), createVNode(unref(KButton), {
									size: "sm",
									variant: "ghost",
									icon: "trash",
									"aria-label": unref(__)("Remove the redirect from {from}", { from: row.source }),
									onClick: ($event) => removeRule(row)
								}, {
									default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Remove")), 1)]),
									_: 1
								}, 8, ["aria-label", "onClick"])])];
							}),
							_: 1
						}, _parent, _scopeId));
						else return [createVNode(unref(KTable), {
							columns: ruleColumns,
							rows: rules.rows,
							loading: rules.loading && !rules.rows.length,
							empty: rules.search || rules.origin ? unref(__)("No redirect matches.") : unref(__)("No redirects yet"),
							"empty-text": rules.search || rules.origin ? "" : unref(__)("Moving a published page adds one, or add one yourself.")
						}, {
							"cell-source": withCtx(({ row }) => [createVNode("div", { class: "k-redirect__path" }, toDisplayString(row.source), 1), row.match !== "exact" || row.origin !== "manual" || row.note ? (openBlock(), createBlock("div", {
								key: 0,
								class: "k-muted k-small"
							}, [
								row.match === "prefix" ? (openBlock(), createBlock(Fragment, { key: 0 }, [createTextVNode(toDisplayString(unref(__)("and everything below")), 1)], 64)) : row.match === "regex" ? (openBlock(), createBlock(Fragment, { key: 1 }, [createTextVNode(toDisplayString(unref(__)("pattern")), 1)], 64)) : createCommentVNode("v-if", true),
								row.origin === "auto" ? (openBlock(), createBlock(Fragment, { key: 2 }, [createTextVNode(toDisplayString(row.match !== "exact" ? " · " : "") + toDisplayString(unref(__)("page moved")), 1)], 64)) : row.origin === "import" ? (openBlock(), createBlock(Fragment, { key: 3 }, [createTextVNode(toDisplayString(row.match !== "exact" ? " · " : "") + toDisplayString(unref(__)("imported")), 1)], 64)) : createCommentVNode("v-if", true),
								row.note ? (openBlock(), createBlock(Fragment, { key: 4 }, [createTextVNode(" · " + toDisplayString(row.note), 1)], 64)) : createCommentVNode("v-if", true)
							])) : createCommentVNode("v-if", true)]),
							"cell-target": withCtx(({ row }) => [row.status === 410 ? (openBlock(), createBlock("span", {
								key: 0,
								class: "k-muted"
							}, toDisplayString(unref(__)("No new address")), 1)) : (openBlock(), createBlock("a", {
								key: 1,
								href: row.target,
								class: "k-link k-redirect__path",
								target: "_blank",
								rel: "noopener noreferrer"
							}, toDisplayString(row.target), 9, ["href"]))]),
							"cell-status": withCtx(({ row }) => [createVNode(unref(KBadge), null, {
								default: withCtx(() => [createTextVNode(toDisplayString(statusShort(row.status)) + " " + toDisplayString(row.status), 1)]),
								_: 2
							}, 1024)]),
							"cell-hits": withCtx(({ row }) => [createVNode("span", { class: "k-num" }, toDisplayString(row.hits.toLocaleString()), 1)]),
							"cell-last_hit_at": withCtx(({ row }) => [row.last_hit_at ? (openBlock(), createBlock(unref(KTime), {
								key: 0,
								value: row.last_hit_at
							}, null, 8, ["value"])) : (openBlock(), createBlock("span", {
								key: 1,
								class: "k-muted"
							}, toDisplayString(unref(__)("Never")), 1))]),
							"cell-actions": withCtx(({ row }) => [createVNode("div", { class: "k-row-actions" }, [createVNode(unref(KButton), {
								size: "sm",
								variant: "ghost",
								icon: "pencil",
								onClick: ($event) => openRule(row)
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Edit")), 1)]),
								_: 1
							}, 8, ["onClick"]), createVNode(unref(KButton), {
								size: "sm",
								variant: "ghost",
								icon: "trash",
								"aria-label": unref(__)("Remove the redirect from {from}", { from: row.source }),
								onClick: ($event) => removeRule(row)
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Remove")), 1)]),
								_: 1
							}, 8, ["aria-label", "onClick"])])]),
							_: 2
						}, 1032, [
							"rows",
							"loading",
							"empty",
							"empty-text"
						])];
					}),
					_: 2
				}, [rules.lastPage > 1 ? {
					name: "footer",
					fn: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) {
							_push(`<div class="k-pager" data-v-a4ae156a${_scopeId}>`);
							_push(ssrRenderComponent(unref(KButton), {
								size: "sm",
								disabled: rules.page <= 1,
								onClick: ($event) => loadRules(rules.page - 1)
							}, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate(unref(__)("Previous"))}`);
									else return [createTextVNode(toDisplayString(unref(__)("Previous")), 1)];
								}),
								_: 1
							}, _parent, _scopeId));
							_push(`<span class="k-muted k-small" data-v-a4ae156a${_scopeId}>${ssrInterpolate(unref(__)("Page {page} of {last}", {
								page: rules.page,
								last: rules.lastPage
							}))}</span>`);
							_push(ssrRenderComponent(unref(KButton), {
								size: "sm",
								disabled: rules.page >= rules.lastPage,
								onClick: ($event) => loadRules(rules.page + 1)
							}, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate(unref(__)("Next"))}`);
									else return [createTextVNode(toDisplayString(unref(__)("Next")), 1)];
								}),
								_: 1
							}, _parent, _scopeId));
							_push(`</div>`);
						} else return [createVNode("div", { class: "k-pager" }, [
							createVNode(unref(KButton), {
								size: "sm",
								disabled: rules.page <= 1,
								onClick: ($event) => loadRules(rules.page - 1)
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Previous")), 1)]),
								_: 1
							}, 8, ["disabled", "onClick"]),
							createVNode("span", { class: "k-muted k-small" }, toDisplayString(unref(__)("Page {page} of {last}", {
								page: rules.page,
								last: rules.lastPage
							})), 1),
							createVNode(unref(KButton), {
								size: "sm",
								disabled: rules.page >= rules.lastPage,
								onClick: ($event) => loadRules(rules.page + 1)
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Next")), 1)]),
								_: 1
							}, 8, ["disabled", "onClick"])
						])];
					}),
					key: "0"
				} : void 0]), _parent));
				_push(`<!--]-->`);
			} else {
				_push(`<!--[--><div class="k-toolbar" data-v-a4ae156a>`);
				_push(ssrRenderComponent(unref(KField), { label: unref(__)("Order") }, {
					default: withCtx(({ id }, _push, _parent, _scopeId) => {
						if (_push) _push(ssrRenderComponent(unref(KSelect), {
							id,
							modelValue: missing.sort,
							"onUpdate:modelValue": ($event) => missing.sort = $event,
							options: [{
								value: "hits",
								label: unref(__)("Most asked for")
							}, {
								value: "recent",
								label: unref(__)("Most recent")
							}]
						}, null, _parent, _scopeId));
						else return [createVNode(unref(KSelect), {
							id,
							modelValue: missing.sort,
							"onUpdate:modelValue": ($event) => missing.sort = $event,
							options: [{
								value: "hits",
								label: unref(__)("Most asked for")
							}, {
								value: "recent",
								label: unref(__)("Most recent")
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
				_push(`<div class="k-toolbar__grow" data-v-a4ae156a></div>`);
				if (missing.total) _push(ssrRenderComponent(unref(KButton), {
					variant: "ghost",
					icon: "x-circle",
					onClick: clearLog
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`${ssrInterpolate(unref(__)("Clear the list"))}`);
						else return [createTextVNode(toDisplayString(unref(__)("Clear the list")), 1)];
					}),
					_: 1
				}, _parent));
				else _push(`<!---->`);
				_push(`</div>`);
				_push(ssrRenderComponent(unref(KCard), {
					coord: "R2",
					title: unref(__)("Addresses that found nothing"),
					flush: ""
				}, createSlots({
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(ssrRenderComponent(unref(KTable), {
							columns: missingColumns,
							rows: missing.rows,
							loading: missing.loading && !missing.rows.length,
							empty: unref(__)("Nothing yet"),
							"empty-text": unref(__)("Addresses visitors ask for that don't exist show up here, so you can send them somewhere useful.")
						}, {
							"cell-path": withCtx(({ row }, _push, _parent, _scopeId) => {
								if (_push) _push(`<span class="k-redirect__path" data-v-a4ae156a${_scopeId}>${ssrInterpolate(row.path)}</span>`);
								else return [createVNode("span", { class: "k-redirect__path" }, toDisplayString(row.path), 1)];
							}),
							"cell-hits": withCtx(({ row }, _push, _parent, _scopeId) => {
								if (_push) _push(`<span class="k-num" data-v-a4ae156a${_scopeId}>${ssrInterpolate(row.hits.toLocaleString())}</span>`);
								else return [createVNode("span", { class: "k-num" }, toDisplayString(row.hits.toLocaleString()), 1)];
							}),
							"cell-last_seen_at": withCtx(({ row }, _push, _parent, _scopeId) => {
								if (_push) _push(ssrRenderComponent(unref(KTime), { value: row.last_seen_at }, null, _parent, _scopeId));
								else return [createVNode(unref(KTime), { value: row.last_seen_at }, null, 8, ["value"])];
							}),
							"cell-referrer": withCtx(({ row }, _push, _parent, _scopeId) => {
								if (_push) {
									if (!row.referrer) _push(`<span class="k-muted" data-v-a4ae156a${_scopeId}>${ssrInterpolate(unref(__)("Typed or bookmarked"))}</span>`);
									else _push(`<span class="k-redirect__ref"${ssrRenderAttr("title", row.referrer)} data-v-a4ae156a${_scopeId}>${ssrInterpolate(row.referrer.replace(/^https?:\/\//, ""))}</span>`);
								} else return [!row.referrer ? (openBlock(), createBlock("span", {
									key: 0,
									class: "k-muted"
								}, toDisplayString(unref(__)("Typed or bookmarked")), 1)) : (openBlock(), createBlock("span", {
									key: 1,
									class: "k-redirect__ref",
									title: row.referrer
								}, toDisplayString(row.referrer.replace(/^https?:\/\//, "")), 9, ["title"]))];
							}),
							"cell-actions": withCtx(({ row }, _push, _parent, _scopeId) => {
								if (_push) {
									_push(`<div class="k-row-actions" data-v-a4ae156a${_scopeId}>`);
									_push(ssrRenderComponent(unref(KButton), {
										size: "sm",
										icon: "signpost",
										onClick: ($event) => openRule(null, row.path)
									}, {
										default: withCtx((_, _push, _parent, _scopeId) => {
											if (_push) _push(`${ssrInterpolate(unref(__)("Redirect"))}`);
											else return [createTextVNode(toDisplayString(unref(__)("Redirect")), 1)];
										}),
										_: 2
									}, _parent, _scopeId));
									_push(ssrRenderComponent(unref(KButton), {
										size: "sm",
										variant: "ghost",
										icon: "x",
										"aria-label": unref(__)("Forget {path}", { path: row.path }),
										onClick: ($event) => forget(row)
									}, null, _parent, _scopeId));
									_push(`</div>`);
								} else return [createVNode("div", { class: "k-row-actions" }, [createVNode(unref(KButton), {
									size: "sm",
									icon: "signpost",
									onClick: ($event) => openRule(null, row.path)
								}, {
									default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Redirect")), 1)]),
									_: 1
								}, 8, ["onClick"]), createVNode(unref(KButton), {
									size: "sm",
									variant: "ghost",
									icon: "x",
									"aria-label": unref(__)("Forget {path}", { path: row.path }),
									onClick: ($event) => forget(row)
								}, null, 8, ["aria-label", "onClick"])])];
							}),
							_: 1
						}, _parent, _scopeId));
						else return [createVNode(unref(KTable), {
							columns: missingColumns,
							rows: missing.rows,
							loading: missing.loading && !missing.rows.length,
							empty: unref(__)("Nothing yet"),
							"empty-text": unref(__)("Addresses visitors ask for that don't exist show up here, so you can send them somewhere useful.")
						}, {
							"cell-path": withCtx(({ row }) => [createVNode("span", { class: "k-redirect__path" }, toDisplayString(row.path), 1)]),
							"cell-hits": withCtx(({ row }) => [createVNode("span", { class: "k-num" }, toDisplayString(row.hits.toLocaleString()), 1)]),
							"cell-last_seen_at": withCtx(({ row }) => [createVNode(unref(KTime), { value: row.last_seen_at }, null, 8, ["value"])]),
							"cell-referrer": withCtx(({ row }) => [!row.referrer ? (openBlock(), createBlock("span", {
								key: 0,
								class: "k-muted"
							}, toDisplayString(unref(__)("Typed or bookmarked")), 1)) : (openBlock(), createBlock("span", {
								key: 1,
								class: "k-redirect__ref",
								title: row.referrer
							}, toDisplayString(row.referrer.replace(/^https?:\/\//, "")), 9, ["title"]))]),
							"cell-actions": withCtx(({ row }) => [createVNode("div", { class: "k-row-actions" }, [createVNode(unref(KButton), {
								size: "sm",
								icon: "signpost",
								onClick: ($event) => openRule(null, row.path)
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Redirect")), 1)]),
								_: 1
							}, 8, ["onClick"]), createVNode(unref(KButton), {
								size: "sm",
								variant: "ghost",
								icon: "x",
								"aria-label": unref(__)("Forget {path}", { path: row.path }),
								onClick: ($event) => forget(row)
							}, null, 8, ["aria-label", "onClick"])])]),
							_: 2
						}, 1032, [
							"rows",
							"loading",
							"empty",
							"empty-text"
						])];
					}),
					_: 2
				}, [missing.lastPage > 1 ? {
					name: "footer",
					fn: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) {
							_push(`<div class="k-pager" data-v-a4ae156a${_scopeId}>`);
							_push(ssrRenderComponent(unref(KButton), {
								size: "sm",
								disabled: missing.page <= 1,
								onClick: ($event) => loadMissing(missing.page - 1)
							}, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate(unref(__)("Previous"))}`);
									else return [createTextVNode(toDisplayString(unref(__)("Previous")), 1)];
								}),
								_: 1
							}, _parent, _scopeId));
							_push(`<span class="k-muted k-small" data-v-a4ae156a${_scopeId}>${ssrInterpolate(unref(__)("Page {page} of {last}", {
								page: missing.page,
								last: missing.lastPage
							}))}</span>`);
							_push(ssrRenderComponent(unref(KButton), {
								size: "sm",
								disabled: missing.page >= missing.lastPage,
								onClick: ($event) => loadMissing(missing.page + 1)
							}, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate(unref(__)("Next"))}`);
									else return [createTextVNode(toDisplayString(unref(__)("Next")), 1)];
								}),
								_: 1
							}, _parent, _scopeId));
							_push(`</div>`);
						} else return [createVNode("div", { class: "k-pager" }, [
							createVNode(unref(KButton), {
								size: "sm",
								disabled: missing.page <= 1,
								onClick: ($event) => loadMissing(missing.page - 1)
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Previous")), 1)]),
								_: 1
							}, 8, ["disabled", "onClick"]),
							createVNode("span", { class: "k-muted k-small" }, toDisplayString(unref(__)("Page {page} of {last}", {
								page: missing.page,
								last: missing.lastPage
							})), 1),
							createVNode(unref(KButton), {
								size: "sm",
								disabled: missing.page >= missing.lastPage,
								onClick: ($event) => loadMissing(missing.page + 1)
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Next")), 1)]),
								_: 1
							}, 8, ["disabled", "onClick"])
						])];
					}),
					key: "0"
				} : void 0]), _parent));
				_push(`<!--]-->`);
			}
			_push(ssrRenderComponent(unref(KModal), {
				open: editing.value !== null,
				title: editing.value === "new" ? unref(__)("Add a redirect") : unref(__)("Edit the redirect"),
				onClose: ($event) => editing.value = null
			}, {
				footer: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(ssrRenderComponent(unref(KButton), { onClick: ($event) => editing.value = null }, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Cancel"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Cancel")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						_push(ssrRenderComponent(unref(KButton), {
							variant: "primary",
							type: "submit",
							form: "k-redirect-form",
							loading: saving.value
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(editing.value === "new" ? unref(__)("Add the redirect") : unref(__)("Save"))}`);
								else return [createTextVNode(toDisplayString(editing.value === "new" ? unref(__)("Add the redirect") : unref(__)("Save")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
					} else return [createVNode(unref(KButton), { onClick: ($event) => editing.value = null }, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Cancel")), 1)]),
						_: 1
					}, 8, ["onClick"]), createVNode(unref(KButton), {
						variant: "primary",
						type: "submit",
						form: "k-redirect-form",
						loading: saving.value
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(editing.value === "new" ? unref(__)("Add the redirect") : unref(__)("Save")), 1)]),
						_: 1
					}, 8, ["loading"])];
				}),
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(`<form id="k-redirect-form" class="k-form" data-v-a4ae156a${_scopeId}>`);
						_push(ssrRenderComponent(unref(KField), { label: unref(__)("Which addresses") }, {
							default: withCtx(({ id }, _push, _parent, _scopeId) => {
								if (_push) _push(ssrRenderComponent(unref(KSelect), {
									id,
									modelValue: form.match,
									"onUpdate:modelValue": ($event) => form.match = $event,
									options: MATCHES
								}, null, _parent, _scopeId));
								else return [createVNode(unref(KSelect), {
									id,
									modelValue: form.match,
									"onUpdate:modelValue": ($event) => form.match = $event,
									options: MATCHES
								}, null, 8, [
									"id",
									"modelValue",
									"onUpdate:modelValue"
								])];
							}),
							_: 1
						}, _parent, _scopeId));
						_push(ssrRenderComponent(unref(KField), {
							label: form.match === "regex" ? unref(__)("Pattern") : unref(__)("From"),
							help: sourceHelp.value,
							error: errors.value.source ?? "",
							required: ""
						}, {
							default: withCtx(({ id, describedBy, invalid }, _push, _parent, _scopeId) => {
								if (_push) _push(ssrRenderComponent(unref(KInput), {
									id,
									modelValue: form.source,
									"onUpdate:modelValue": ($event) => form.source = $event,
									class: { "k-mono-input": form.match === "regex" },
									autocomplete: "off",
									spellcheck: "false",
									invalid,
									"aria-describedby": describedBy
								}, null, _parent, _scopeId));
								else return [createVNode(unref(KInput), {
									id,
									modelValue: form.source,
									"onUpdate:modelValue": ($event) => form.source = $event,
									class: { "k-mono-input": form.match === "regex" },
									autocomplete: "off",
									spellcheck: "false",
									invalid,
									"aria-describedby": describedBy
								}, null, 8, [
									"id",
									"modelValue",
									"onUpdate:modelValue",
									"class",
									"invalid",
									"aria-describedby"
								])];
							}),
							_: 1
						}, _parent, _scopeId));
						_push(ssrRenderComponent(unref(KField), {
							label: unref(__)("Kind"),
							help: Number(form.status) === 301 || Number(form.status) === 308 ? unref(__)("Search engines move the old address's ranking to the new one.") : ""
						}, {
							default: withCtx(({ id }, _push, _parent, _scopeId) => {
								if (_push) _push(ssrRenderComponent(unref(KSelect), {
									id,
									modelValue: form.status,
									"onUpdate:modelValue": ($event) => form.status = $event,
									options: STATUSES
								}, null, _parent, _scopeId));
								else return [createVNode(unref(KSelect), {
									id,
									modelValue: form.status,
									"onUpdate:modelValue": ($event) => form.status = $event,
									options: STATUSES
								}, null, 8, [
									"id",
									"modelValue",
									"onUpdate:modelValue"
								])];
							}),
							_: 1
						}, _parent, _scopeId));
						if (Number(form.status) !== 410) _push(ssrRenderComponent(unref(KField), {
							label: unref(__)("To"),
							help: unref(__)("A path on this site such as /new-page, or a full https:// address."),
							error: errors.value.target ?? "",
							required: ""
						}, {
							default: withCtx(({ id, describedBy, invalid }, _push, _parent, _scopeId) => {
								if (_push) _push(ssrRenderComponent(unref(KInput), {
									id,
									modelValue: form.target,
									"onUpdate:modelValue": ($event) => form.target = $event,
									autocomplete: "off",
									spellcheck: "false",
									invalid,
									"aria-describedby": describedBy
								}, null, _parent, _scopeId));
								else return [createVNode(unref(KInput), {
									id,
									modelValue: form.target,
									"onUpdate:modelValue": ($event) => form.target = $event,
									autocomplete: "off",
									spellcheck: "false",
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
						}, _parent, _scopeId));
						else _push(`<!---->`);
						_push(ssrRenderComponent(unref(KField), {
							label: unref(__)("Note"),
							help: unref(__)("Only you and other editors see it."),
							error: errors.value.note ?? ""
						}, {
							default: withCtx(({ id, describedBy, invalid }, _push, _parent, _scopeId) => {
								if (_push) _push(ssrRenderComponent(unref(KInput), {
									id,
									modelValue: form.note,
									"onUpdate:modelValue": ($event) => form.note = $event,
									maxlength: "255",
									invalid,
									"aria-describedby": describedBy
								}, null, _parent, _scopeId));
								else return [createVNode(unref(KInput), {
									id,
									modelValue: form.note,
									"onUpdate:modelValue": ($event) => form.note = $event,
									maxlength: "255",
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
						}, _parent, _scopeId));
						if (errors.value.match || errors.value.status) _push(`<p class="k-field__error" role="alert" data-v-a4ae156a${_scopeId}>${ssrInterpolate(errors.value.match ?? errors.value.status)}</p>`);
						else _push(`<!---->`);
						_push(`</form>`);
					} else return [createVNode("form", {
						id: "k-redirect-form",
						class: "k-form",
						onSubmit: withModifiers(saveRule, ["prevent"])
					}, [
						createVNode(unref(KField), { label: unref(__)("Which addresses") }, {
							default: withCtx(({ id }) => [createVNode(unref(KSelect), {
								id,
								modelValue: form.match,
								"onUpdate:modelValue": ($event) => form.match = $event,
								options: MATCHES
							}, null, 8, [
								"id",
								"modelValue",
								"onUpdate:modelValue"
							])]),
							_: 1
						}, 8, ["label"]),
						createVNode(unref(KField), {
							label: form.match === "regex" ? unref(__)("Pattern") : unref(__)("From"),
							help: sourceHelp.value,
							error: errors.value.source ?? "",
							required: ""
						}, {
							default: withCtx(({ id, describedBy, invalid }) => [createVNode(unref(KInput), {
								id,
								modelValue: form.source,
								"onUpdate:modelValue": ($event) => form.source = $event,
								class: { "k-mono-input": form.match === "regex" },
								autocomplete: "off",
								spellcheck: "false",
								invalid,
								"aria-describedby": describedBy
							}, null, 8, [
								"id",
								"modelValue",
								"onUpdate:modelValue",
								"class",
								"invalid",
								"aria-describedby"
							])]),
							_: 1
						}, 8, [
							"label",
							"help",
							"error"
						]),
						createVNode(unref(KField), {
							label: unref(__)("Kind"),
							help: Number(form.status) === 301 || Number(form.status) === 308 ? unref(__)("Search engines move the old address's ranking to the new one.") : ""
						}, {
							default: withCtx(({ id }) => [createVNode(unref(KSelect), {
								id,
								modelValue: form.status,
								"onUpdate:modelValue": ($event) => form.status = $event,
								options: STATUSES
							}, null, 8, [
								"id",
								"modelValue",
								"onUpdate:modelValue"
							])]),
							_: 1
						}, 8, ["label", "help"]),
						Number(form.status) !== 410 ? (openBlock(), createBlock(unref(KField), {
							key: 0,
							label: unref(__)("To"),
							help: unref(__)("A path on this site such as /new-page, or a full https:// address."),
							error: errors.value.target ?? "",
							required: ""
						}, {
							default: withCtx(({ id, describedBy, invalid }) => [createVNode(unref(KInput), {
								id,
								modelValue: form.target,
								"onUpdate:modelValue": ($event) => form.target = $event,
								autocomplete: "off",
								spellcheck: "false",
								invalid,
								"aria-describedby": describedBy
							}, null, 8, [
								"id",
								"modelValue",
								"onUpdate:modelValue",
								"invalid",
								"aria-describedby"
							])]),
							_: 1
						}, 8, [
							"label",
							"help",
							"error"
						])) : createCommentVNode("v-if", true),
						createVNode(unref(KField), {
							label: unref(__)("Note"),
							help: unref(__)("Only you and other editors see it."),
							error: errors.value.note ?? ""
						}, {
							default: withCtx(({ id, describedBy, invalid }) => [createVNode(unref(KInput), {
								id,
								modelValue: form.note,
								"onUpdate:modelValue": ($event) => form.note = $event,
								maxlength: "255",
								invalid,
								"aria-describedby": describedBy
							}, null, 8, [
								"id",
								"modelValue",
								"onUpdate:modelValue",
								"invalid",
								"aria-describedby"
							])]),
							_: 1
						}, 8, [
							"label",
							"help",
							"error"
						]),
						errors.value.match || errors.value.status ? (openBlock(), createBlock("p", {
							key: 1,
							class: "k-field__error",
							role: "alert"
						}, toDisplayString(errors.value.match ?? errors.value.status), 1)) : createCommentVNode("v-if", true)
					], 32)];
				}),
				_: 1
			}, _parent));
			_push(ssrRenderComponent(unref(KModal), {
				open: importing.value,
				title: unref(__)("Import redirects"),
				size: "lg",
				onClose: ($event) => importing.value = false
			}, {
				footer: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(ssrRenderComponent(unref(KButton), { onClick: ($event) => importing.value = false }, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(importResult.value ? unref(__)("Close") : unref(__)("Cancel"))}`);
								else return [createTextVNode(toDisplayString(importResult.value ? unref(__)("Close") : unref(__)("Cancel")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						_push(ssrRenderComponent(unref(KButton), {
							variant: "primary",
							type: "submit",
							form: "k-redirect-import",
							loading: saving.value,
							disabled: !csv.value.trim()
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Import"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Import")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
					} else return [createVNode(unref(KButton), { onClick: ($event) => importing.value = false }, {
						default: withCtx(() => [createTextVNode(toDisplayString(importResult.value ? unref(__)("Close") : unref(__)("Cancel")), 1)]),
						_: 1
					}, 8, ["onClick"]), createVNode(unref(KButton), {
						variant: "primary",
						type: "submit",
						form: "k-redirect-import",
						loading: saving.value,
						disabled: !csv.value.trim()
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Import")), 1)]),
						_: 1
					}, 8, ["loading", "disabled"])];
				}),
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(`<form id="k-redirect-import" class="k-form" data-v-a4ae156a${_scopeId}><p class="k-muted" data-v-a4ae156a${_scopeId}>${ssrInterpolate(unref(__)("A CSV file with one redirect per line: source, target, status, match, note. Only the first two are needed. Existing addresses are updated."))}</p>`);
						_push(ssrRenderComponent(unref(KField), { label: unref(__)("File") }, {
							default: withCtx(({ id }, _push, _parent, _scopeId) => {
								if (_push) _push(`<input${ssrRenderAttr("id", id)} type="file" accept=".csv,text/csv,text/plain" data-v-a4ae156a${_scopeId}>`);
								else return [createVNode("input", {
									id,
									type: "file",
									accept: ".csv,text/csv,text/plain",
									onChange: readFile
								}, null, 40, ["id"])];
							}),
							_: 1
						}, _parent, _scopeId));
						_push(ssrRenderComponent(unref(KField), {
							label: unref(__)("Or paste the lines"),
							help: unref(__)("Such as: /old-page,/new-page")
						}, {
							default: withCtx(({ id, describedBy }, _push, _parent, _scopeId) => {
								if (_push) _push(`<textarea${ssrRenderAttr("id", id)}${ssrRenderAttr("aria-describedby", describedBy)} class="k-input k-mono-input k-redirect__csv" rows="8" spellcheck="false" data-v-a4ae156a${_scopeId}>${ssrInterpolate(csv.value)}</textarea>`);
								else return [withDirectives(createVNode("textarea", {
									id,
									"aria-describedby": describedBy,
									"onUpdate:modelValue": ($event) => csv.value = $event,
									class: "k-input k-mono-input k-redirect__csv",
									rows: "8",
									spellcheck: "false"
								}, null, 8, [
									"id",
									"aria-describedby",
									"onUpdate:modelValue"
								]), [[vModelText, csv.value]])];
							}),
							_: 1
						}, _parent, _scopeId));
						if (importResult.value) {
							_push(`<div class="k-redirect__result" role="status" data-v-a4ae156a${_scopeId}><p data-v-a4ae156a${_scopeId}>${ssrInterpolate(unref(__)("{created} added, {updated} updated.", {
								created: importResult.value.created,
								updated: importResult.value.updated
							}))}</p>`);
							if (importResult.value.errors.length) {
								_push(`<!--[--><p data-v-a4ae156a${_scopeId}>${ssrInterpolate(unref(__)("These lines were skipped:"))}</p><ul data-v-a4ae156a${_scopeId}><!--[-->`);
								ssrRenderList(importResult.value.errors.slice(0, 50), (err) => {
									_push(`<li data-v-a4ae156a${_scopeId}>${ssrInterpolate(unref(__)("Line {line}: {message}", {
										line: err.line,
										message: err.message
									}))}</li>`);
								});
								_push(`<!--]--></ul><!--]-->`);
							} else _push(`<!---->`);
							_push(`</div>`);
						} else _push(`<!---->`);
						_push(`</form>`);
					} else return [createVNode("form", {
						id: "k-redirect-import",
						class: "k-form",
						onSubmit: withModifiers(runImport, ["prevent"])
					}, [
						createVNode("p", { class: "k-muted" }, toDisplayString(unref(__)("A CSV file with one redirect per line: source, target, status, match, note. Only the first two are needed. Existing addresses are updated.")), 1),
						createVNode(unref(KField), { label: unref(__)("File") }, {
							default: withCtx(({ id }) => [createVNode("input", {
								id,
								type: "file",
								accept: ".csv,text/csv,text/plain",
								onChange: readFile
							}, null, 40, ["id"])]),
							_: 1
						}, 8, ["label"]),
						createVNode(unref(KField), {
							label: unref(__)("Or paste the lines"),
							help: unref(__)("Such as: /old-page,/new-page")
						}, {
							default: withCtx(({ id, describedBy }) => [withDirectives(createVNode("textarea", {
								id,
								"aria-describedby": describedBy,
								"onUpdate:modelValue": ($event) => csv.value = $event,
								class: "k-input k-mono-input k-redirect__csv",
								rows: "8",
								spellcheck: "false"
							}, null, 8, [
								"id",
								"aria-describedby",
								"onUpdate:modelValue"
							]), [[vModelText, csv.value]])]),
							_: 1
						}, 8, ["label", "help"]),
						importResult.value ? (openBlock(), createBlock("div", {
							key: 0,
							class: "k-redirect__result",
							role: "status"
						}, [createVNode("p", null, toDisplayString(unref(__)("{created} added, {updated} updated.", {
							created: importResult.value.created,
							updated: importResult.value.updated
						})), 1), importResult.value.errors.length ? (openBlock(), createBlock(Fragment, { key: 0 }, [createVNode("p", null, toDisplayString(unref(__)("These lines were skipped:")), 1), createVNode("ul", null, [(openBlock(true), createBlock(Fragment, null, renderList(importResult.value.errors.slice(0, 50), (err) => {
							return openBlock(), createBlock("li", { key: err.line }, toDisplayString(unref(__)("Line {line}: {message}", {
								line: err.line,
								message: err.message
							})), 1);
						}), 128))])], 64)) : createCommentVNode("v-if", true)])) : createCommentVNode("v-if", true)
					], 32)];
				}),
				_: 1
			}, _parent));
			_push(`<!--]-->`);
		};
	}
});
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/admin/redirects/Index.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
var Index_default = /*#__PURE__*/ _plugin_vue_export_helper_default(_sfc_main, [["__scopeId", "data-v-a4ae156a"]]);
export { Index_default as default };
