import { t as _plugin_vue_export_helper_default } from "../../../chunks/_plugin-vue_export-helper-DjkACiAr.mjs";
import { Fragment, computed, createBlock, createCommentVNode, createSlots, createTextVNode, createVNode, onBeforeUnmount, openBlock, reactive, ref, renderList, toDisplayString, unref, useSSRContext, watch, withCtx, withModifiers } from "vue";
import { ssrInterpolate, ssrRenderAttr, ssrRenderClass, ssrRenderComponent, ssrRenderList, ssrRenderStyle } from "vue/server-renderer";
import { ApiError, __, confirm, formatBytes, notify, useApi } from "@kestello/sdk";
import { KAlert, KBadge, KButton, KCard, KField, KPageHeader, KSelect, KSwitch, KTable, KTime } from "@kestello/ui";
// core/resources/js/pages/admin/system/Import.vue
var _sfc_main = /*@__PURE__*/ Object.assign({ layout: "Admin" }, {
	__name: "Import",
	__ssrInlineRender: true,
	setup(__props) {
		/**
		* System → Import: bring a WordPress site here from its export file.
		* Upload → review what will come over (authors, options) → it runs in the
		* background (or, with no queue worker, from this screen while it is open)
		* → a report of everything that was skipped or could not come over.
		*/
		const api = useApi();
		const list = ref(null);
		const current = ref(null);
		const loadError = ref(null);
		const busy = ref("");
		const KINDS = {
			entry: __("Posts and pages"),
			attachment: __("Files"),
			file: __("Files linked from posts"),
			term: __("Categories and tags"),
			menu: __("Menus"),
			redirect: __("Redirects from old addresses")
		};
		const PHASES = {
			terms: __("Categories and tags"),
			media: __("Files"),
			entries: __("Posts and pages"),
			links: __("Links between posts"),
			menus: __("Menus"),
			redirects: __("Redirects"),
			finish: __("Finishing")
		};
		const STATUS = {
			ready: {
				label: __("Not started"),
				tone: "neutral"
			},
			running: {
				label: __("Importing"),
				tone: "info"
			},
			done: {
				label: __("Done"),
				tone: "success"
			},
			cancelled: {
				label: __("Stopped"),
				tone: "warning"
			}
		};
		async function load() {
			try {
				list.value = (await api.get("/imports")).data;
				const open = list.value.imports.find((i) => i.status === "ready" || i.status === "running");
				if (open) await show(open.id);
			} catch (e) {
				loadError.value = e;
			}
		}
		load();
		async function show(id) {
			current.value = (await api.get(`/imports/${id}`)).data;
		}
		function rows(counts) {
			return Object.entries(counts ?? {}).filter(([kind]) => KINDS[kind]).map(([kind, c]) => ({
				kind,
				label: KINDS[kind],
				...c
			}));
		}
		const file = ref(null);
		const uploadError = ref("");
		async function upload() {
			if (!file.value) return;
			busy.value = "upload";
			uploadError.value = "";
			const form = new FormData();
			form.append("file", file.value);
			try {
				current.value = (await api.upload("/imports", form)).data;
				prepare();
			} catch (e) {
				if (e instanceof ApiError && (e.status === 422 || e.status === 409)) uploadError.value = e.fieldErrors().file ?? e.message;
				else notify.error(e);
			} finally {
				busy.value = "";
			}
		}
		const options = reactive({
			authors: {},
			default_author: "",
			media: true,
			redirects: true
		});
		function prepare() {
			options.authors = Object.fromEntries((current.value.summary.authors ?? []).map((a) => [a.login, a.match ?? ""]));
			options.default_author = "";
			options.media = true;
			options.redirects = true;
		}
		watch(() => current.value?.id, () => current.value?.status === "ready" && prepare());
		const people = computed(() => [{
			value: "",
			label: __("Me, the person importing")
		}, ...(current.value?.people ?? []).map((p) => ({
			value: p.id,
			label: p.username
		}))]);
		const toImport = computed(() => rows(current.value?.counts).filter((r) => r.total - r.skipped > 0 || r.skipped > 0));
		async function start() {
			busy.value = "start";
			const authors = Object.fromEntries(Object.entries(options.authors).filter(([, id]) => id !== ""));
			try {
				current.value = (await api.post(`/imports/${current.value.id}/start`, {
					authors,
					default_author: options.default_author === "" ? null : options.default_author,
					media: options.media,
					redirects: options.redirects
				})).data;
			} catch (e) {
				notify.error(e);
			} finally {
				busy.value = "";
			}
		}
		async function discard(i = current.value) {
			if (i.status !== "ready" && !await confirm({
				title: __("Forget this import?"),
				message: __("Its report goes. What it imported stays, and importing the same site again still updates instead of copying."),
				confirmLabel: __("Forget")
			})) return;
			busy.value = `discard:${i.id}`;
			try {
				await api.delete(`/imports/${i.id}`);
				if (current.value?.id === i.id) current.value = null;
				list.value = (await api.get("/imports")).data;
			} catch (e) {
				notify.error(e);
			} finally {
				busy.value = "";
			}
		}
		const running = computed(() => current.value?.status === "running");
		const progress = computed(() => {
			const p = current.value?.progress;
			return p && p.total ? Math.round(p.done / p.total * 100) : 0;
		});
		let alive = true;
		let timer = null;
		let failedSteps = 0;
		async function tick() {
			if (!alive || !running.value) return;
			const id = current.value.id;
			try {
				current.value = (await api.post(`/imports/${id}/step`)).data;
				failedSteps = 0;
			} catch (e) {
				failedSteps++;
				if (failedSteps === 1) notify.error(e);
				if (alive && running.value) timer = setTimeout(tick, Math.min(3e4, 1e3 * 2 ** failedSteps));
				return;
			}
			if (alive && running.value) timer = setTimeout(tick, current.value.busy ? 2e3 : 50);
			else if (current.value.status === "done") list.value = (await api.get("/imports")).data;
		}
		watch(running, (now) => {
			if (now) {
				clearTimeout(timer);
				timer = setTimeout(tick, 50);
			}
		});
		onBeforeUnmount(() => {
			alive = false;
			clearTimeout(timer);
		});
		async function act(action) {
			busy.value = action;
			try {
				current.value = (await api.post(`/imports/${current.value.id}/${action}`)).data;
			} catch (e) {
				notify.error(e);
			} finally {
				busy.value = "";
			}
		}
		const finished = computed(() => current.value && ["done", "cancelled"].includes(current.value.status));
		const total = (key) => rows(current.value?.counts).reduce((n, r) => n + (r[key] ?? 0), 0);
		const failed = computed(() => (current.value?.problems ?? []).filter((p) => p.result === "failed"));
		const kept = computed(() => (current.value?.problems ?? []).filter((p) => p.result === "kept"));
		const postsText = (n) => __("{n, plural, one {# post} other {# posts}}", { n });
		const commentsText = (n) => __("{n, plural, one {# comment is} other {# comments are}} not imported: visitor comments are not part of Kestello.", { n });
		const failedText = (n) => __("{n, plural, one {# item} other {# items}} could not be imported. The reason is next to each.", { n });
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<!--[-->`);
			_push(ssrRenderComponent(unref(KPageHeader), {
				title: unref(__)("Import"),
				description: unref(__)("Bring a WordPress site here: posts and pages, categories and tags, files, menus, and redirects from the old addresses. Importing the same site again updates what came over before instead of copying it.")
			}, createSlots({ _: 2 }, [current.value && !running.value ? {
				name: "actions",
				fn: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						if (finished.value) _push(ssrRenderComponent(unref(KButton), {
							icon: "arrow-left",
							onClick: ($event) => current.value = null
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("All imports"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("All imports")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						else _push(`<!---->`);
					} else return [finished.value ? (openBlock(), createBlock(unref(KButton), {
						key: 0,
						icon: "arrow-left",
						onClick: ($event) => current.value = null
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("All imports")), 1)]),
						_: 1
					}, 8, ["onClick"])) : createCommentVNode("v-if", true)];
				}),
				key: "0"
			} : void 0]), _parent));
			if (loadError.value) _push(ssrRenderComponent(unref(KAlert), { tone: "danger" }, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(`${ssrInterpolate(loadError.value.message)}`);
					else return [createTextVNode(toDisplayString(loadError.value.message), 1)];
				}),
				_: 1
			}, _parent));
			else _push(`<!---->`);
			_push(`<!-- upload, and earlier imports -->`);
			if (list.value && !current.value) {
				_push(`<!--[-->`);
				_push(ssrRenderComponent(unref(KCard), { title: unref(__)("Import from WordPress") }, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) {
							_push(`<ol class="k-import__how" data-v-e1b30992${_scopeId}><li data-v-e1b30992${_scopeId}>${ssrInterpolate(unref(__)("In WordPress, open Tools → Export, choose All content and download the export file."))}</li><li data-v-e1b30992${_scopeId}>${ssrInterpolate(unref(__)("Upload it here. Nothing is imported until you have seen what will come over."))}</li></ol><form class="k-import__upload" data-v-e1b30992${_scopeId}>`);
							_push(ssrRenderComponent(unref(KField), {
								label: unref(__)("WordPress export file"),
								error: uploadError.value,
								help: list.value.max_upload ? unref(__)("Up to {size}.", { size: unref(formatBytes)(list.value.max_upload) }) : ""
							}, {
								default: withCtx(({ id }, _push, _parent, _scopeId) => {
									if (_push) _push(`<input${ssrRenderAttr("id", id)} type="file" accept=".xml,text/xml,application/xml" class="k-input" required data-v-e1b30992${_scopeId}>`);
									else return [createVNode("input", {
										id,
										type: "file",
										accept: ".xml,text/xml,application/xml",
										class: "k-input",
										required: "",
										onChange: ($event) => {
											file.value = $event.target.files?.[0] ?? null;
											uploadError.value = "";
										}
									}, null, 40, ["id", "onChange"])];
								}),
								_: 1
							}, _parent, _scopeId));
							_push(ssrRenderComponent(unref(KButton), {
								type: "submit",
								variant: "primary",
								icon: "upload",
								loading: busy.value === "upload",
								disabled: !file.value
							}, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate(unref(__)("Read the file"))}`);
									else return [createTextVNode(toDisplayString(unref(__)("Read the file")), 1)];
								}),
								_: 1
							}, _parent, _scopeId));
							_push(`</form><p class="k-muted k-small k-import__cli" data-v-e1b30992${_scopeId}>${ssrInterpolate(unref(__)("A larger export, or a site whose old server is gone: copy the file (and wp-content/uploads) to this server and run"))} <code data-v-e1b30992${_scopeId}>bin/cms import:wordpress export.xml --uploads=path/to/uploads</code></p>`);
						} else return [
							createVNode("ol", { class: "k-import__how" }, [createVNode("li", null, toDisplayString(unref(__)("In WordPress, open Tools → Export, choose All content and download the export file.")), 1), createVNode("li", null, toDisplayString(unref(__)("Upload it here. Nothing is imported until you have seen what will come over.")), 1)]),
							createVNode("form", {
								class: "k-import__upload",
								onSubmit: withModifiers(upload, ["prevent"])
							}, [createVNode(unref(KField), {
								label: unref(__)("WordPress export file"),
								error: uploadError.value,
								help: list.value.max_upload ? unref(__)("Up to {size}.", { size: unref(formatBytes)(list.value.max_upload) }) : ""
							}, {
								default: withCtx(({ id }) => [createVNode("input", {
									id,
									type: "file",
									accept: ".xml,text/xml,application/xml",
									class: "k-input",
									required: "",
									onChange: ($event) => {
										file.value = $event.target.files?.[0] ?? null;
										uploadError.value = "";
									}
								}, null, 40, ["id", "onChange"])]),
								_: 1
							}, 8, [
								"label",
								"error",
								"help"
							]), createVNode(unref(KButton), {
								type: "submit",
								variant: "primary",
								icon: "upload",
								loading: busy.value === "upload",
								disabled: !file.value
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Read the file")), 1)]),
								_: 1
							}, 8, ["loading", "disabled"])], 32),
							createVNode("p", { class: "k-muted k-small k-import__cli" }, [createTextVNode(toDisplayString(unref(__)("A larger export, or a site whose old server is gone: copy the file (and wp-content/uploads) to this server and run")) + " ", 1), createVNode("code", null, "bin/cms import:wordpress export.xml --uploads=path/to/uploads")])
						];
					}),
					_: 1
				}, _parent));
				if (list.value.imports.length) _push(ssrRenderComponent(unref(KCard), {
					title: unref(__)("Earlier imports"),
					flush: ""
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(ssrRenderComponent(unref(KTable), {
							columns: [
								{
									key: "site",
									label: unref(__)("Site")
								},
								{
									key: "status",
									label: unref(__)("Status")
								},
								{
									key: "when",
									label: unref(__)("Started")
								},
								{
									key: "actions",
									label: "",
									align: "end",
									width: "1%"
								}
							],
							rows: list.value.imports
						}, {
							"cell-site": withCtx(({ row }, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(row.site.title || row.site.url)} <div class="k-muted k-small" data-v-e1b30992${_scopeId}>${ssrInterpolate(row.site.url)}</div>`);
								else return [createTextVNode(toDisplayString(row.site.title || row.site.url) + " ", 1), createVNode("div", { class: "k-muted k-small" }, toDisplayString(row.site.url), 1)];
							}),
							"cell-status": withCtx(({ row }, _push, _parent, _scopeId) => {
								if (_push) _push(ssrRenderComponent(unref(KBadge), { tone: STATUS[row.status]?.tone ?? "neutral" }, {
									default: withCtx((_, _push, _parent, _scopeId) => {
										if (_push) _push(`${ssrInterpolate(STATUS[row.status]?.label ?? row.status)}`);
										else return [createTextVNode(toDisplayString(STATUS[row.status]?.label ?? row.status), 1)];
									}),
									_: 2
								}, _parent, _scopeId));
								else return [createVNode(unref(KBadge), { tone: STATUS[row.status]?.tone ?? "neutral" }, {
									default: withCtx(() => [createTextVNode(toDisplayString(STATUS[row.status]?.label ?? row.status), 1)]),
									_: 2
								}, 1032, ["tone"])];
							}),
							"cell-when": withCtx(({ row }, _push, _parent, _scopeId) => {
								if (_push) _push(ssrRenderComponent(unref(KTime), { value: row.started_at ?? row.created_at }, null, _parent, _scopeId));
								else return [createVNode(unref(KTime), { value: row.started_at ?? row.created_at }, null, 8, ["value"])];
							}),
							"cell-actions": withCtx(({ row }, _push, _parent, _scopeId) => {
								if (_push) {
									_push(`<div class="k-import__actions" data-v-e1b30992${_scopeId}>`);
									_push(ssrRenderComponent(unref(KButton), {
										size: "sm",
										onClick: ($event) => show(row.id)
									}, {
										default: withCtx((_, _push, _parent, _scopeId) => {
											if (_push) _push(`${ssrInterpolate(unref(__)("Report"))}`);
											else return [createTextVNode(toDisplayString(unref(__)("Report")), 1)];
										}),
										_: 2
									}, _parent, _scopeId));
									_push(ssrRenderComponent(unref(KButton), {
										size: "sm",
										variant: "ghost",
										icon: "trash",
										"aria-label": unref(__)("Forget"),
										loading: busy.value === `discard:${row.id}`,
										onClick: ($event) => discard(row)
									}, null, _parent, _scopeId));
									_push(`</div>`);
								} else return [createVNode("div", { class: "k-import__actions" }, [createVNode(unref(KButton), {
									size: "sm",
									onClick: ($event) => show(row.id)
								}, {
									default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Report")), 1)]),
									_: 1
								}, 8, ["onClick"]), createVNode(unref(KButton), {
									size: "sm",
									variant: "ghost",
									icon: "trash",
									"aria-label": unref(__)("Forget"),
									loading: busy.value === `discard:${row.id}`,
									onClick: ($event) => discard(row)
								}, null, 8, [
									"aria-label",
									"loading",
									"onClick"
								])])];
							}),
							_: 1
						}, _parent, _scopeId));
						else return [createVNode(unref(KTable), {
							columns: [
								{
									key: "site",
									label: unref(__)("Site")
								},
								{
									key: "status",
									label: unref(__)("Status")
								},
								{
									key: "when",
									label: unref(__)("Started")
								},
								{
									key: "actions",
									label: "",
									align: "end",
									width: "1%"
								}
							],
							rows: list.value.imports
						}, {
							"cell-site": withCtx(({ row }) => [createTextVNode(toDisplayString(row.site.title || row.site.url) + " ", 1), createVNode("div", { class: "k-muted k-small" }, toDisplayString(row.site.url), 1)]),
							"cell-status": withCtx(({ row }) => [createVNode(unref(KBadge), { tone: STATUS[row.status]?.tone ?? "neutral" }, {
								default: withCtx(() => [createTextVNode(toDisplayString(STATUS[row.status]?.label ?? row.status), 1)]),
								_: 2
							}, 1032, ["tone"])]),
							"cell-when": withCtx(({ row }) => [createVNode(unref(KTime), { value: row.started_at ?? row.created_at }, null, 8, ["value"])]),
							"cell-actions": withCtx(({ row }) => [createVNode("div", { class: "k-import__actions" }, [createVNode(unref(KButton), {
								size: "sm",
								onClick: ($event) => show(row.id)
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Report")), 1)]),
								_: 1
							}, 8, ["onClick"]), createVNode(unref(KButton), {
								size: "sm",
								variant: "ghost",
								icon: "trash",
								"aria-label": unref(__)("Forget"),
								loading: busy.value === `discard:${row.id}`,
								onClick: ($event) => discard(row)
							}, null, 8, [
								"aria-label",
								"loading",
								"onClick"
							])])]),
							_: 1
						}, 8, ["columns", "rows"])];
					}),
					_: 1
				}, _parent));
				else _push(`<!---->`);
				_push(`<!--]-->`);
			} else if (current.value?.status === "ready") {
				_push(`<!--[--><!-- review -->`);
				_push(ssrRenderComponent(unref(KCard), {
					title: current.value.site.title || current.value.site.url,
					description: current.value.site.url
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) {
							_push(ssrRenderComponent(unref(KTable), {
								columns: [
									{
										key: "label",
										label: unref(__)("What")
									},
									{
										key: "in",
										label: unref(__)("To import"),
										align: "end"
									},
									{
										key: "skipped",
										label: unref(__)("Skipped"),
										align: "end"
									}
								],
								rows: toImport.value,
								"row-key": "kind"
							}, {
								"cell-in": withCtx(({ row }, _push, _parent, _scopeId) => {
									if (_push) _push(`<span class="k-num" data-v-e1b30992${_scopeId}>${ssrInterpolate((row.total - row.skipped).toLocaleString())}</span>`);
									else return [createVNode("span", { class: "k-num" }, toDisplayString((row.total - row.skipped).toLocaleString()), 1)];
								}),
								"cell-skipped": withCtx(({ row }, _push, _parent, _scopeId) => {
									if (_push) _push(`<span class="${ssrRenderClass([{ "k-muted": !row.skipped }, "k-num"])}" data-v-e1b30992${_scopeId}>${ssrInterpolate(row.skipped.toLocaleString())}</span>`);
									else return [createVNode("span", { class: ["k-num", { "k-muted": !row.skipped }] }, toDisplayString(row.skipped.toLocaleString()), 3)];
								}),
								_: 1
							}, _parent, _scopeId));
							if (current.value.skipped.length || current.value.summary.comments) {
								_push(`<ul class="k-import__skipped" data-v-e1b30992${_scopeId}><!--[-->`);
								ssrRenderList(current.value.skipped, (g) => {
									_push(`<li data-v-e1b30992${_scopeId}><strong data-v-e1b30992${_scopeId}>${ssrInterpolate(g.reason)}</strong><span class="k-muted k-import__eg" data-v-e1b30992${_scopeId}>${ssrInterpolate(g.examples.join(", "))}`);
									if (g.count > g.examples.length) _push(`<!--[-->, …<!--]-->`);
									else _push(`<!---->`);
									_push(`</span></li>`);
								});
								_push(`<!--]-->`);
								if (current.value.summary.comments) _push(`<li class="k-muted" data-v-e1b30992${_scopeId}>${ssrInterpolate(commentsText(current.value.summary.comments))}</li>`);
								else _push(`<!---->`);
								_push(`</ul>`);
							} else _push(`<!---->`);
						} else return [createVNode(unref(KTable), {
							columns: [
								{
									key: "label",
									label: unref(__)("What")
								},
								{
									key: "in",
									label: unref(__)("To import"),
									align: "end"
								},
								{
									key: "skipped",
									label: unref(__)("Skipped"),
									align: "end"
								}
							],
							rows: toImport.value,
							"row-key": "kind"
						}, {
							"cell-in": withCtx(({ row }) => [createVNode("span", { class: "k-num" }, toDisplayString((row.total - row.skipped).toLocaleString()), 1)]),
							"cell-skipped": withCtx(({ row }) => [createVNode("span", { class: ["k-num", { "k-muted": !row.skipped }] }, toDisplayString(row.skipped.toLocaleString()), 3)]),
							_: 2
						}, 1032, ["columns", "rows"]), current.value.skipped.length || current.value.summary.comments ? (openBlock(), createBlock("ul", {
							key: 0,
							class: "k-import__skipped"
						}, [(openBlock(true), createBlock(Fragment, null, renderList(current.value.skipped, (g) => {
							return openBlock(), createBlock("li", { key: g.kind + g.reason }, [createVNode("strong", null, toDisplayString(g.reason), 1), createVNode("span", { class: "k-muted k-import__eg" }, [createTextVNode(toDisplayString(g.examples.join(", ")), 1), g.count > g.examples.length ? (openBlock(), createBlock(Fragment, { key: 0 }, [createTextVNode(", …")], 64)) : createCommentVNode("v-if", true)])]);
						}), 128)), current.value.summary.comments ? (openBlock(), createBlock("li", {
							key: 0,
							class: "k-muted"
						}, toDisplayString(commentsText(current.value.summary.comments)), 1)) : createCommentVNode("v-if", true)])) : createCommentVNode("v-if", true)];
					}),
					_: 1
				}, _parent));
				if (current.value.summary.authors.length) _push(ssrRenderComponent(unref(KCard), {
					title: unref(__)("Authors"),
					description: unref(__)("Whose name each post carries here. People are matched by email address, then by username."),
					flush: ""
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(ssrRenderComponent(unref(KTable), {
							columns: [{
								key: "author",
								label: unref(__)("In WordPress")
							}, {
								key: "to",
								label: unref(__)("Here")
							}],
							rows: current.value.summary.authors,
							"row-key": "login"
						}, {
							"cell-author": withCtx(({ row }, _push, _parent, _scopeId) => {
								if (_push) {
									_push(`${ssrInterpolate(row.name || row.login)} <div class="k-muted k-small" data-v-e1b30992${_scopeId}>${ssrInterpolate(row.login)}`);
									if (row.email) _push(`<!--[--> · ${ssrInterpolate(row.email)}<!--]-->`);
									else _push(`<!---->`);
									_push(` · ${ssrInterpolate(postsText(row.posts))}</div>`);
								} else return [createTextVNode(toDisplayString(row.name || row.login) + " ", 1), createVNode("div", { class: "k-muted k-small" }, [
									createTextVNode(toDisplayString(row.login), 1),
									row.email ? (openBlock(), createBlock(Fragment, { key: 0 }, [createTextVNode(" · " + toDisplayString(row.email), 1)], 64)) : createCommentVNode("v-if", true),
									createTextVNode(" · " + toDisplayString(postsText(row.posts)), 1)
								])];
							}),
							"cell-to": withCtx(({ row }, _push, _parent, _scopeId) => {
								if (_push) _push(ssrRenderComponent(unref(KSelect), {
									modelValue: options.authors[row.login],
									"onUpdate:modelValue": ($event) => options.authors[row.login] = $event,
									options: people.value,
									"aria-label": unref(__)("Credit posts by {name} to", { name: row.login })
								}, null, _parent, _scopeId));
								else return [createVNode(unref(KSelect), {
									modelValue: options.authors[row.login],
									"onUpdate:modelValue": ($event) => options.authors[row.login] = $event,
									options: people.value,
									"aria-label": unref(__)("Credit posts by {name} to", { name: row.login })
								}, null, 8, [
									"modelValue",
									"onUpdate:modelValue",
									"options",
									"aria-label"
								])];
							}),
							_: 1
						}, _parent, _scopeId));
						else return [createVNode(unref(KTable), {
							columns: [{
								key: "author",
								label: unref(__)("In WordPress")
							}, {
								key: "to",
								label: unref(__)("Here")
							}],
							rows: current.value.summary.authors,
							"row-key": "login"
						}, {
							"cell-author": withCtx(({ row }) => [createTextVNode(toDisplayString(row.name || row.login) + " ", 1), createVNode("div", { class: "k-muted k-small" }, [
								createTextVNode(toDisplayString(row.login), 1),
								row.email ? (openBlock(), createBlock(Fragment, { key: 0 }, [createTextVNode(" · " + toDisplayString(row.email), 1)], 64)) : createCommentVNode("v-if", true),
								createTextVNode(" · " + toDisplayString(postsText(row.posts)), 1)
							])]),
							"cell-to": withCtx(({ row }) => [createVNode(unref(KSelect), {
								modelValue: options.authors[row.login],
								"onUpdate:modelValue": ($event) => options.authors[row.login] = $event,
								options: people.value,
								"aria-label": unref(__)("Credit posts by {name} to", { name: row.login })
							}, null, 8, [
								"modelValue",
								"onUpdate:modelValue",
								"options",
								"aria-label"
							])]),
							_: 1
						}, 8, ["columns", "rows"])];
					}),
					_: 1
				}, _parent));
				else _push(`<!---->`);
				_push(ssrRenderComponent(unref(KCard), { title: unref(__)("Options") }, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) {
							_push(`<div class="k-import__options" data-v-e1b30992${_scopeId}><div data-v-e1b30992${_scopeId}>`);
							_push(ssrRenderComponent(unref(KSwitch), {
								modelValue: options.media,
								"onUpdate:modelValue": ($event) => options.media = $event,
								label: unref(__)("Copy files from the old site")
							}, null, _parent, _scopeId));
							_push(`<p class="k-muted k-small" data-v-e1b30992${_scopeId}>${ssrInterpolate(unref(__)("Images and documents are downloaded from {url} into the media library, with their alt text and captions. Leave this off when the old site is offline; images then keep pointing at it.", { url: current.value.site.url }))}</p></div><div data-v-e1b30992${_scopeId}>`);
							_push(ssrRenderComponent(unref(KSwitch), {
								modelValue: options.redirects,
								"onUpdate:modelValue": ($event) => options.redirects = $event,
								label: unref(__)("Redirect old addresses")
							}, null, _parent, _scopeId));
							_push(`<p class="k-muted k-small" data-v-e1b30992${_scopeId}>${ssrInterpolate(unref(__)("Visitors and search engines following an old link land on the same page here. A redirect only applies where nothing else lives at that address."))}</p></div></div>`);
						} else return [createVNode("div", { class: "k-import__options" }, [createVNode("div", null, [createVNode(unref(KSwitch), {
							modelValue: options.media,
							"onUpdate:modelValue": ($event) => options.media = $event,
							label: unref(__)("Copy files from the old site")
						}, null, 8, [
							"modelValue",
							"onUpdate:modelValue",
							"label"
						]), createVNode("p", { class: "k-muted k-small" }, toDisplayString(unref(__)("Images and documents are downloaded from {url} into the media library, with their alt text and captions. Leave this off when the old site is offline; images then keep pointing at it.", { url: current.value.site.url })), 1)]), createVNode("div", null, [createVNode(unref(KSwitch), {
							modelValue: options.redirects,
							"onUpdate:modelValue": ($event) => options.redirects = $event,
							label: unref(__)("Redirect old addresses")
						}, null, 8, [
							"modelValue",
							"onUpdate:modelValue",
							"label"
						]), createVNode("p", { class: "k-muted k-small" }, toDisplayString(unref(__)("Visitors and search engines following an old link land on the same page here. A redirect only applies where nothing else lives at that address.")), 1)])])];
					}),
					_: 1
				}, _parent));
				_push(`<div class="k-import__go" data-v-e1b30992>`);
				_push(ssrRenderComponent(unref(KButton), {
					variant: "primary",
					icon: "arrow-square-in",
					loading: busy.value === "start",
					onClick: start
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`${ssrInterpolate(unref(__)("Import"))}`);
						else return [createTextVNode(toDisplayString(unref(__)("Import")), 1)];
					}),
					_: 1
				}, _parent));
				_push(ssrRenderComponent(unref(KButton), {
					loading: busy.value === `discard:${current.value.id}`,
					onClick: ($event) => discard()
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`${ssrInterpolate(unref(__)("Cancel"))}`);
						else return [createTextVNode(toDisplayString(unref(__)("Cancel")), 1)];
					}),
					_: 1
				}, _parent));
				_push(`</div><!--]-->`);
			} else if (running.value) {
				_push(`<!--[--><!-- running -->`);
				_push(ssrRenderComponent(unref(KCard), { title: unref(__)("Importing {site}", { site: current.value.site.title || current.value.site.url }) }, {
					actions: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(ssrRenderComponent(unref(KButton), {
							size: "sm",
							icon: "stop-circle",
							loading: busy.value === "cancel",
							onClick: ($event) => act("cancel")
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Stop"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Stop")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						else return [createVNode(unref(KButton), {
							size: "sm",
							icon: "stop-circle",
							loading: busy.value === "cancel",
							onClick: ($event) => act("cancel")
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Stop")), 1)]),
							_: 1
						}, 8, ["loading", "onClick"])];
					}),
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) {
							_push(`<div class="k-import__bar" role="progressbar"${ssrRenderAttr("aria-valuenow", progress.value)} aria-valuemin="0" aria-valuemax="100"${ssrRenderAttr("aria-label", unref(__)("Imported"))} data-v-e1b30992${_scopeId}><span style="${ssrRenderStyle({ width: `${progress.value}%` })}" data-v-e1b30992${_scopeId}></span></div><p class="k-import__count" data-v-e1b30992${_scopeId}><strong class="k-num" data-v-e1b30992${_scopeId}>${ssrInterpolate(current.value.progress.done.toLocaleString())}</strong> ${ssrInterpolate(unref(__)("of {total}", { total: current.value.progress.total.toLocaleString() }))} `);
							if (PHASES[current.value.phase]) _push(`<!--[--> · ${ssrInterpolate(PHASES[current.value.phase])}<!--]-->`);
							else _push(`<!---->`);
							_push(`</p><p class="k-muted k-small" data-v-e1b30992${_scopeId}>${ssrInterpolate(current.value.worker ? unref(__)("The background worker is importing. You can leave this screen.") : unref(__)("No background worker runs on this site, so this screen imports while it stays open. Closing it pauses the import; opening it again carries on."))}</p>`);
						} else return [
							createVNode("div", {
								class: "k-import__bar",
								role: "progressbar",
								"aria-valuenow": progress.value,
								"aria-valuemin": "0",
								"aria-valuemax": "100",
								"aria-label": unref(__)("Imported")
							}, [createVNode("span", { style: { width: `${progress.value}%` } }, null, 4)], 8, ["aria-valuenow", "aria-label"]),
							createVNode("p", { class: "k-import__count" }, [
								createVNode("strong", { class: "k-num" }, toDisplayString(current.value.progress.done.toLocaleString()), 1),
								createTextVNode(" " + toDisplayString(unref(__)("of {total}", { total: current.value.progress.total.toLocaleString() })) + " ", 1),
								PHASES[current.value.phase] ? (openBlock(), createBlock(Fragment, { key: 0 }, [createTextVNode(" · " + toDisplayString(PHASES[current.value.phase]), 1)], 64)) : createCommentVNode("v-if", true)
							]),
							createVNode("p", { class: "k-muted k-small" }, toDisplayString(current.value.worker ? unref(__)("The background worker is importing. You can leave this screen.") : unref(__)("No background worker runs on this site, so this screen imports while it stays open. Closing it pauses the import; opening it again carries on.")), 1)
						];
					}),
					_: 1
				}, _parent));
				_push(`<!--]-->`);
			} else if (finished.value) {
				_push(`<!--[--><!-- report -->`);
				if (current.value.status === "cancelled" && current.value.error) _push(ssrRenderComponent(unref(KAlert), {
					tone: "danger",
					title: unref(__)("The import stopped by itself")
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`${ssrInterpolate(current.value.error)} ${ssrInterpolate(unref(__)("What was imported stays. Try again carries on with the rest."))}`);
						else return [createTextVNode(toDisplayString(current.value.error) + " " + toDisplayString(unref(__)("What was imported stays. Try again carries on with the rest.")), 1)];
					}),
					_: 1
				}, _parent));
				else if (current.value.status === "cancelled") _push(ssrRenderComponent(unref(KAlert), {
					tone: "warning",
					title: unref(__)("The import was stopped")
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`${ssrInterpolate(unref(__)("What was imported stays. Try again carries on with the rest."))}`);
						else return [createTextVNode(toDisplayString(unref(__)("What was imported stays. Try again carries on with the rest.")), 1)];
					}),
					_: 1
				}, _parent));
				else if (failed.value.length) _push(ssrRenderComponent(unref(KAlert), {
					tone: "warning",
					title: unref(__)("Imported, with some things left behind")
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`${ssrInterpolate(failedText(total("failed")))}`);
						else return [createTextVNode(toDisplayString(failedText(total("failed"))), 1)];
					}),
					_: 1
				}, _parent));
				else _push(ssrRenderComponent(unref(KAlert), {
					tone: "success",
					title: unref(__)("{site} is here", { site: current.value.site.title || current.value.site.url })
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`${ssrInterpolate(unref(__)("Everything that could come over did. Below is what changed on the way."))}`);
						else return [createTextVNode(toDisplayString(unref(__)("Everything that could come over did. Below is what changed on the way.")), 1)];
					}),
					_: 1
				}, _parent));
				_push(ssrRenderComponent(unref(KCard), { flush: "" }, createSlots({
					title: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) {
							_push(`${ssrInterpolate(current.value.site.title || current.value.site.url)} `);
							if (current.value.finished_at) {
								_push(`<span class="k-muted k-small k-import__when" data-v-e1b30992${_scopeId}>`);
								_push(ssrRenderComponent(unref(KTime), { value: current.value.finished_at }, null, _parent, _scopeId));
								_push(`</span>`);
							} else _push(`<!---->`);
						} else return [createTextVNode(toDisplayString(current.value.site.title || current.value.site.url) + " ", 1), current.value.finished_at ? (openBlock(), createBlock("span", {
							key: 0,
							class: "k-muted k-small k-import__when"
						}, [createVNode(unref(KTime), { value: current.value.finished_at }, null, 8, ["value"])])) : createCommentVNode("v-if", true)];
					}),
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(ssrRenderComponent(unref(KTable), {
							columns: [
								{
									key: "label",
									label: ""
								},
								{
									key: "created",
									label: unref(__)("New"),
									align: "end"
								},
								{
									key: "updated",
									label: unref(__)("Updated"),
									align: "end"
								},
								{
									key: "same",
									label: unref(__)("Unchanged"),
									align: "end"
								},
								{
									key: "kept",
									label: unref(__)("Kept as edited"),
									align: "end"
								},
								{
									key: "skipped",
									label: unref(__)("Skipped"),
									align: "end"
								},
								{
									key: "failed",
									label: unref(__)("Failed"),
									align: "end"
								}
							],
							rows: rows(current.value.counts),
							"row-key": "kind"
						}, createSlots({
							"cell-same": withCtx(({ row }, _push, _parent, _scopeId) => {
								if (_push) _push(`<span class="${ssrRenderClass([{ "k-muted": !(row.unchanged + row.matched) }, "k-num"])}" data-v-e1b30992${_scopeId}>${ssrInterpolate((row.unchanged + row.matched).toLocaleString())}</span>`);
								else return [createVNode("span", { class: ["k-num", { "k-muted": !(row.unchanged + row.matched) }] }, toDisplayString((row.unchanged + row.matched).toLocaleString()), 3)];
							}),
							_: 2
						}, [renderList([
							"created",
							"updated",
							"kept",
							"skipped",
							"failed"
						], (key) => {
							return {
								name: `cell-${key}`,
								fn: withCtx(({ row }, _push, _parent, _scopeId) => {
									if (_push) _push(`<span class="${ssrRenderClass([{
										"k-muted": !row[key],
										"k-import__bad": key === "failed" && row[key]
									}, "k-num"])}" data-v-e1b30992${_scopeId}>${ssrInterpolate(row[key].toLocaleString())}</span>`);
									else return [createVNode("span", { class: ["k-num", {
										"k-muted": !row[key],
										"k-import__bad": key === "failed" && row[key]
									}] }, toDisplayString(row[key].toLocaleString()), 3)];
								})
							};
						})]), _parent, _scopeId));
						else return [createVNode(unref(KTable), {
							columns: [
								{
									key: "label",
									label: ""
								},
								{
									key: "created",
									label: unref(__)("New"),
									align: "end"
								},
								{
									key: "updated",
									label: unref(__)("Updated"),
									align: "end"
								},
								{
									key: "same",
									label: unref(__)("Unchanged"),
									align: "end"
								},
								{
									key: "kept",
									label: unref(__)("Kept as edited"),
									align: "end"
								},
								{
									key: "skipped",
									label: unref(__)("Skipped"),
									align: "end"
								},
								{
									key: "failed",
									label: unref(__)("Failed"),
									align: "end"
								}
							],
							rows: rows(current.value.counts),
							"row-key": "kind"
						}, createSlots({
							"cell-same": withCtx(({ row }) => [createVNode("span", { class: ["k-num", { "k-muted": !(row.unchanged + row.matched) }] }, toDisplayString((row.unchanged + row.matched).toLocaleString()), 3)]),
							_: 2
						}, [renderList([
							"created",
							"updated",
							"kept",
							"skipped",
							"failed"
						], (key) => {
							return {
								name: `cell-${key}`,
								fn: withCtx(({ row }) => [createVNode("span", { class: ["k-num", {
									"k-muted": !row[key],
									"k-import__bad": key === "failed" && row[key]
								}] }, toDisplayString(row[key].toLocaleString()), 3)])
							};
						})]), 1032, ["columns", "rows"])];
					}),
					_: 2
				}, [failed.value.length || current.value.status === "cancelled" ? {
					name: "actions",
					fn: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(ssrRenderComponent(unref(KButton), {
							size: "sm",
							variant: "primary",
							icon: "arrow-clockwise",
							loading: busy.value === "retry",
							onClick: ($event) => act("retry")
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Try again"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Try again")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						else return [createVNode(unref(KButton), {
							size: "sm",
							variant: "primary",
							icon: "arrow-clockwise",
							loading: busy.value === "retry",
							onClick: ($event) => act("retry")
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Try again")), 1)]),
							_: 1
						}, 8, ["loading", "onClick"])];
					}),
					key: "0"
				} : void 0]), _parent));
				if (failed.value.length) _push(ssrRenderComponent(unref(KCard), {
					title: unref(__)("Not imported"),
					flush: ""
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(ssrRenderComponent(unref(KTable), {
							columns: [{
								key: "label",
								label: unref(__)("Item")
							}, {
								key: "message",
								label: unref(__)("Why")
							}],
							rows: failed.value,
							"row-key": "label"
						}, {
							"cell-label": withCtx(({ row }, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(row.label)} <div class="k-muted k-small" data-v-e1b30992${_scopeId}>${ssrInterpolate(KINDS[row.kind] ?? row.kind)}</div>`);
								else return [createTextVNode(toDisplayString(row.label) + " ", 1), createVNode("div", { class: "k-muted k-small" }, toDisplayString(KINDS[row.kind] ?? row.kind), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						else return [createVNode(unref(KTable), {
							columns: [{
								key: "label",
								label: unref(__)("Item")
							}, {
								key: "message",
								label: unref(__)("Why")
							}],
							rows: failed.value,
							"row-key": "label"
						}, {
							"cell-label": withCtx(({ row }) => [createTextVNode(toDisplayString(row.label) + " ", 1), createVNode("div", { class: "k-muted k-small" }, toDisplayString(KINDS[row.kind] ?? row.kind), 1)]),
							_: 1
						}, 8, ["columns", "rows"])];
					}),
					_: 1
				}, _parent));
				else _push(`<!---->`);
				if (kept.value.length) _push(ssrRenderComponent(unref(KCard), {
					title: unref(__)("Kept as edited here"),
					description: unref(__)("These changed on this site after the last import, so the WordPress version did not replace them."),
					flush: ""
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(ssrRenderComponent(unref(KTable), {
							columns: [{
								key: "label",
								label: unref(__)("Item")
							}],
							rows: kept.value,
							"row-key": "label"
						}, {
							"cell-label": withCtx(({ row }, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(row.label)} <div class="k-muted k-small" data-v-e1b30992${_scopeId}>${ssrInterpolate(KINDS[row.kind] ?? row.kind)}</div>`);
								else return [createTextVNode(toDisplayString(row.label) + " ", 1), createVNode("div", { class: "k-muted k-small" }, toDisplayString(KINDS[row.kind] ?? row.kind), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						else return [createVNode(unref(KTable), {
							columns: [{
								key: "label",
								label: unref(__)("Item")
							}],
							rows: kept.value,
							"row-key": "label"
						}, {
							"cell-label": withCtx(({ row }) => [createTextVNode(toDisplayString(row.label) + " ", 1), createVNode("div", { class: "k-muted k-small" }, toDisplayString(KINDS[row.kind] ?? row.kind), 1)]),
							_: 1
						}, 8, ["columns", "rows"])];
					}),
					_: 1
				}, _parent));
				else _push(`<!---->`);
				if (current.value.notices.length || current.value.conversion.length) _push(ssrRenderComponent(unref(KCard), { title: unref(__)("Changed on the way") }, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) {
							_push(`<ul class="k-import__list" data-v-e1b30992${_scopeId}><!--[-->`);
							ssrRenderList(current.value.notices, (n) => {
								_push(`<li data-v-e1b30992${_scopeId}><strong data-v-e1b30992${_scopeId}>${ssrInterpolate(n.label)}</strong> <span class="k-muted k-import__eg" data-v-e1b30992${_scopeId}>${ssrInterpolate(n.message)}</span></li>`);
							});
							_push(`<!--]--><!--[-->`);
							ssrRenderList(current.value.conversion, (c) => {
								_push(`<li data-v-e1b30992${_scopeId}><span class="k-num k-import__times" data-v-e1b30992${_scopeId}>${ssrInterpolate(c.count)} ×</span> ${ssrInterpolate(c.text)}</li>`);
							});
							_push(`<!--]--></ul>`);
						} else return [createVNode("ul", { class: "k-import__list" }, [(openBlock(true), createBlock(Fragment, null, renderList(current.value.notices, (n) => {
							return openBlock(), createBlock("li", { key: n.kind + n.label }, [
								createVNode("strong", null, toDisplayString(n.label), 1),
								createTextVNode(),
								createVNode("span", { class: "k-muted k-import__eg" }, toDisplayString(n.message), 1)
							]);
						}), 128)), (openBlock(true), createBlock(Fragment, null, renderList(current.value.conversion, (c) => {
							return openBlock(), createBlock("li", { key: c.key }, [createVNode("span", { class: "k-num k-import__times" }, toDisplayString(c.count) + " ×", 1), createTextVNode(" " + toDisplayString(c.text), 1)]);
						}), 128))])];
					}),
					_: 1
				}, _parent));
				else _push(`<!---->`);
				if (current.value.skipped.length) _push(ssrRenderComponent(unref(KCard), { title: unref(__)("Skipped") }, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) {
							_push(`<ul class="k-import__list" data-v-e1b30992${_scopeId}><!--[-->`);
							ssrRenderList(current.value.skipped, (g) => {
								_push(`<li data-v-e1b30992${_scopeId}><span class="k-num k-import__times" data-v-e1b30992${_scopeId}>${ssrInterpolate(g.count)} ×</span> ${ssrInterpolate(g.reason)} <span class="k-muted k-import__eg" data-v-e1b30992${_scopeId}>${ssrInterpolate(g.examples.join(", "))}`);
								if (g.count > g.examples.length) _push(`<!--[-->, …<!--]-->`);
								else _push(`<!---->`);
								_push(`</span></li>`);
							});
							_push(`<!--]--></ul>`);
						} else return [createVNode("ul", { class: "k-import__list" }, [(openBlock(true), createBlock(Fragment, null, renderList(current.value.skipped, (g) => {
							return openBlock(), createBlock("li", { key: g.kind + g.reason }, [
								createVNode("span", { class: "k-num k-import__times" }, toDisplayString(g.count) + " ×", 1),
								createTextVNode(" " + toDisplayString(g.reason) + " ", 1),
								createVNode("span", { class: "k-muted k-import__eg" }, [createTextVNode(toDisplayString(g.examples.join(", ")), 1), g.count > g.examples.length ? (openBlock(), createBlock(Fragment, { key: 0 }, [createTextVNode(", …")], 64)) : createCommentVNode("v-if", true)])
							]);
						}), 128))])];
					}),
					_: 1
				}, _parent));
				else _push(`<!---->`);
				_push(`<!--]-->`);
			} else _push(`<!---->`);
			_push(`<!--]-->`);
		};
	}
});
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/admin/system/Import.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
var Import_default = /*#__PURE__*/ _plugin_vue_export_helper_default(_sfc_main, [["__scopeId", "data-v-e1b30992"]]);
export { Import_default as default };
