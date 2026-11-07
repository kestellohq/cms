import { t as _plugin_vue_export_helper_default } from "../../../chunks/_plugin-vue_export-helper-DjkACiAr.mjs";
import { Fragment, computed, createBlock, createCommentVNode, createSlots, createTextVNode, createVNode, openBlock, ref, renderList, toDisplayString, unref, useSSRContext, withCtx, withModifiers } from "vue";
import { ssrInterpolate, ssrRenderAttr, ssrRenderClass, ssrRenderComponent, ssrRenderList } from "vue/server-renderer";
import { ApiError, __, formatDateTime, formatRelative, notify, useApi } from "@kestello/sdk";
import { KAlert, KBadge, KButton, KCard, KField, KIcon, KModal, KPageHeader, KTable } from "@kestello/ui";
// core/resources/js/pages/admin/system/Updates.vue
var _sfc_main = /*@__PURE__*/ Object.assign({ layout: "Admin" }, {
	__name: "Updates",
	__ssrInlineRender: true,
	setup(__props) {
		/**
		* Updates: what runs (Kestello, plugins, themes) and applying a Kestello
		* release, downloaded from GitHub or uploaded. A release is reviewed first; applying it backs up the database,
		* switches to the new release, checks the site, and switches back by itself
		* when anything is wrong.
		*/
		const api = useApi();
		const data = ref(null);
		const loadError = ref(null);
		async function load() {
			try {
				data.value = (await api.get("/updates")).data;
			} catch (e) {
				loadError.value = e;
			}
		}
		load();
		const packages = computed(() => [...(data.value?.plugins ?? []).map((p) => ({
			...p,
			key: `plugin:${p.slug}`,
			kind: __("Plugin")
		})), ...(data.value?.themes ?? []).map((t) => ({
			...t,
			key: `theme:${t.slug}`,
			kind: __("Theme")
		}))]);
		const available = computed(() => data.value?.available ?? null);
		const latest = computed(() => available.value?.latest ?? null);
		async function checkNow() {
			busy.value = "check";
			try {
				data.value = (await api.post("/updates/core/check")).data;
			} catch (e) {
				notify.error(e);
			} finally {
				busy.value = "";
			}
		}
		async function reviewLatest() {
			busy.value = "download";
			try {
				const result = (await api.post("/updates/core/download", { version: latest.value.version })).data;
				start();
				review.value = result;
			} catch (e) {
				notify.error(e);
			} finally {
				busy.value = "";
			}
		}
		const open = ref(false);
		const file = ref(null);
		const review = ref(null);
		const uploadErrors = ref({});
		const busy = ref("");
		const failed = ref(null);
		function start() {
			open.value = true;
			file.value = null;
			review.value = null;
			uploadErrors.value = {};
			failed.value = null;
		}
		async function upload() {
			if (!file.value) return;
			busy.value = "upload";
			uploadErrors.value = {};
			const form = new FormData();
			form.append("file", file.value);
			try {
				review.value = (await api.upload("/updates/core/upload", form)).data;
			} catch (e) {
				if (e instanceof ApiError && e.status === 422) uploadErrors.value = e.fieldErrors();
				else notify.error(e);
			} finally {
				busy.value = "";
			}
		}
		const release = computed(() => review.value?.release ?? null);
		const signature = computed(() => {
			const s = release.value?.signature;
			if (!s) return null;
			return {
				signed: {
					icon: "seal-check",
					tone: "success",
					text: __("Signed by {name}, a publisher this site trusts.", { name: s.name })
				},
				untrusted: {
					icon: "seal-question",
					tone: "warning",
					text: __("Signed with a key this site does not trust ({key}).", { key: s.key })
				},
				unsigned: {
					icon: "seal-question",
					tone: "warning",
					text: __("Not signed: nobody vouches for where it came from.")
				},
				tampered: {
					icon: "seal-warning",
					tone: "danger",
					text: __("Changed after it was signed. Do not apply it.")
				}
			}[s.status];
		});
		const blocked = computed(() => {
			if (!release.value || release.value.allowed) return "";
			if (release.value.signature.status === "tampered") return __("This release was changed after it was signed, so it is refused.");
			return __("Only releases signed by a publisher this site trusts are applied from here. A developer can apply this one on the server: bin/cms core:update <zip>.");
		});
		async function apply() {
			busy.value = "apply";
			failed.value = null;
			try {
				const response = (await api.post("/updates/core", { token: review.value.token })).data;
				data.value = response;
				if (response.result.ok) {
					open.value = false;
					notify.success(__("Kestello {v} is running.", { v: response.result.to }));
				} else failed.value = response.result;
			} catch (e) {
				notify.error(e);
			} finally {
				busy.value = "";
			}
		}
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<!--[-->`);
			_push(ssrRenderComponent(unref(KPageHeader), {
				title: unref(__)("Updates"),
				description: unref(__)("What this site runs. An update backs up the database first and checks the site before visitors see it; if anything is wrong, the previous version comes back by itself.")
			}, createSlots({ _: 2 }, [data.value ? {
				name: "actions",
				fn: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(ssrRenderComponent(unref(KButton), {
						variant: "primary",
						icon: "upload",
						onClick: start
					}, {
						default: withCtx((_, _push, _parent, _scopeId) => {
							if (_push) _push(`${ssrInterpolate(unref(__)("Upload a Kestello release"))}`);
							else return [createTextVNode(toDisplayString(unref(__)("Upload a Kestello release")), 1)];
						}),
						_: 1
					}, _parent, _scopeId));
					else return [createVNode(unref(KButton), {
						variant: "primary",
						icon: "upload",
						onClick: start
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Upload a Kestello release")), 1)]),
						_: 1
					})];
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
			if (data.value) {
				_push(`<!--[-->`);
				_push(ssrRenderComponent(unref(KCard), { title: unref(__)("Kestello") }, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) {
							_push(`<dl class="k-updates__facts" data-v-fe4cc94d${_scopeId}><dt data-v-fe4cc94d${_scopeId}>${ssrInterpolate(unref(__)("Version"))}</dt><dd class="k-num" data-v-fe4cc94d${_scopeId}>${ssrInterpolate(data.value.core.version)}</dd><dt data-v-fe4cc94d${_scopeId}>${ssrInterpolate(unref(__)("PHP"))}</dt><dd class="k-num" data-v-fe4cc94d${_scopeId}>${ssrInterpolate(data.value.php)}</dd><dt data-v-fe4cc94d${_scopeId}>${ssrInterpolate(unref(__)("On disk"))}</dt>`);
							if (data.value.core.mode === "development") _push(`<dd data-v-fe4cc94d${_scopeId}>${ssrInterpolate(unref(__)("A development checkout: core runs from its folder directly. The first update adds release folders next to it."))}</dd>`);
							else {
								_push(`<dd data-v-fe4cc94d${_scopeId}><!--[-->`);
								ssrRenderList(data.value.core.releases, (r, i) => {
									_push(`<span class="k-num" data-v-fe4cc94d${_scopeId}>${ssrInterpolate(i ? ", " : "")}${ssrInterpolate(r.version)}`);
									if (r.current) _push(`<!--[--> (${ssrInterpolate(unref(__)("running"))})<!--]-->`);
									else _push(`<!---->`);
									_push(`</span>`);
								});
								_push(`<!--]--></dd>`);
							}
							_push(`</dl><div class="k-updates__available" data-v-fe4cc94d${_scopeId}>`);
							if (latest.value) {
								_push(`<!--[--><p class="k-updates__headline" data-v-fe4cc94d${_scopeId}><strong data-v-fe4cc94d${_scopeId}>${ssrInterpolate(unref(__)("Kestello {v} is available.", { v: latest.value.version }))}</strong>`);
								if (latest.value.prerelease) _push(ssrRenderComponent(unref(KBadge), { tone: "warning" }, {
									default: withCtx((_, _push, _parent, _scopeId) => {
										if (_push) _push(`${ssrInterpolate(unref(__)("Pre-release"))}`);
										else return [createTextVNode(toDisplayString(unref(__)("Pre-release")), 1)];
									}),
									_: 1
								}, _parent, _scopeId));
								else _push(`<!---->`);
								_push(`</p><p class="k-muted k-small" data-v-fe4cc94d${_scopeId}>${ssrInterpolate(latest.value.published_at ? unref(__)("Published {date}.", { date: unref(formatDateTime)(latest.value.published_at) }) : "")} <a${ssrRenderAttr("href", latest.value.page)} target="_blank" rel="noopener noreferrer" data-v-fe4cc94d${_scopeId}>${ssrInterpolate(unref(__)("Release page on GitHub"))}</a></p><!-- notes_html is rendered on the server from Markdown, with raw HTML escaped -->`);
								if (latest.value.notes_html) _push(`<div class="k-updates__notes k-prose" data-v-fe4cc94d${_scopeId}>${latest.value.notes_html ?? ""}</div>`);
								else _push(`<!---->`);
								_push(`<!--]-->`);
							} else if (available.value && !available.value.error) _push(`<p class="k-muted k-small" data-v-fe4cc94d${_scopeId}>${ssrInterpolate(unref(__)("Kestello is up to date. Last checked {date}.", { date: unref(formatRelative)(available.value.checked_at) }))}</p>`);
							else if (!available.value) _push(`<p class="k-muted k-small" data-v-fe4cc94d${_scopeId}>${ssrInterpolate(unref(__)("Kestello has not checked for new versions yet."))}</p>`);
							else _push(`<!---->`);
							if (available.value?.error) _push(ssrRenderComponent(unref(KAlert), { tone: "warning" }, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate(available.value.error)}`);
									else return [createTextVNode(toDisplayString(available.value.error), 1)];
								}),
								_: 1
							}, _parent, _scopeId));
							else _push(`<!---->`);
							_push(`<div class="k-updates__actions" data-v-fe4cc94d${_scopeId}>`);
							if (latest.value) _push(ssrRenderComponent(unref(KButton), {
								variant: "primary",
								icon: "download-simple",
								loading: busy.value === "download",
								disabled: !!busy.value,
								onClick: reviewLatest
							}, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate(unref(__)("Review the update"))}`);
									else return [createTextVNode(toDisplayString(unref(__)("Review the update")), 1)];
								}),
								_: 1
							}, _parent, _scopeId));
							else _push(`<!---->`);
							_push(ssrRenderComponent(unref(KButton), {
								icon: "arrows-clockwise",
								loading: busy.value === "check",
								disabled: !!busy.value,
								onClick: checkNow
							}, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate(unref(__)("Check now"))}`);
									else return [createTextVNode(toDisplayString(unref(__)("Check now")), 1)];
								}),
								_: 1
							}, _parent, _scopeId));
							_push(`</div></div>`);
							if (!data.value.core.writable) _push(ssrRenderComponent(unref(KAlert), { tone: "warning" }, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate(unref(__)("PHP cannot write to the core folder, so Kestello can only be updated by uploading files to the server."))}`);
									else return [createTextVNode(toDisplayString(unref(__)("PHP cannot write to the core folder, so Kestello can only be updated by uploading files to the server.")), 1)];
								}),
								_: 1
							}, _parent, _scopeId));
							else _push(`<!---->`);
						} else return [
							createVNode("dl", { class: "k-updates__facts" }, [
								createVNode("dt", null, toDisplayString(unref(__)("Version")), 1),
								createVNode("dd", { class: "k-num" }, toDisplayString(data.value.core.version), 1),
								createVNode("dt", null, toDisplayString(unref(__)("PHP")), 1),
								createVNode("dd", { class: "k-num" }, toDisplayString(data.value.php), 1),
								createVNode("dt", null, toDisplayString(unref(__)("On disk")), 1),
								data.value.core.mode === "development" ? (openBlock(), createBlock("dd", { key: 0 }, toDisplayString(unref(__)("A development checkout: core runs from its folder directly. The first update adds release folders next to it.")), 1)) : (openBlock(), createBlock("dd", { key: 1 }, [(openBlock(true), createBlock(Fragment, null, renderList(data.value.core.releases, (r, i) => {
									return openBlock(), createBlock("span", {
										key: r.version,
										class: "k-num"
									}, [createTextVNode(toDisplayString(i ? ", " : "") + toDisplayString(r.version), 1), r.current ? (openBlock(), createBlock(Fragment, { key: 0 }, [createTextVNode(" (" + toDisplayString(unref(__)("running")) + ")", 1)], 64)) : createCommentVNode("v-if", true)]);
								}), 128))]))
							]),
							createVNode("div", { class: "k-updates__available" }, [
								latest.value ? (openBlock(), createBlock(Fragment, { key: 0 }, [
									createVNode("p", { class: "k-updates__headline" }, [createVNode("strong", null, toDisplayString(unref(__)("Kestello {v} is available.", { v: latest.value.version })), 1), latest.value.prerelease ? (openBlock(), createBlock(unref(KBadge), {
										key: 0,
										tone: "warning"
									}, {
										default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Pre-release")), 1)]),
										_: 1
									})) : createCommentVNode("v-if", true)]),
									createVNode("p", { class: "k-muted k-small" }, [createTextVNode(toDisplayString(latest.value.published_at ? unref(__)("Published {date}.", { date: unref(formatDateTime)(latest.value.published_at) }) : "") + " ", 1), createVNode("a", {
										href: latest.value.page,
										target: "_blank",
										rel: "noopener noreferrer"
									}, toDisplayString(unref(__)("Release page on GitHub")), 9, ["href"])]),
									createCommentVNode(" notes_html is rendered on the server from Markdown, with raw HTML escaped "),
									latest.value.notes_html ? (openBlock(), createBlock("div", {
										key: 0,
										class: "k-updates__notes k-prose",
										innerHTML: latest.value.notes_html
									}, null, 8, ["innerHTML"])) : createCommentVNode("v-if", true)
								], 64)) : available.value && !available.value.error ? (openBlock(), createBlock("p", {
									key: 1,
									class: "k-muted k-small"
								}, toDisplayString(unref(__)("Kestello is up to date. Last checked {date}.", { date: unref(formatRelative)(available.value.checked_at) })), 1)) : !available.value ? (openBlock(), createBlock("p", {
									key: 2,
									class: "k-muted k-small"
								}, toDisplayString(unref(__)("Kestello has not checked for new versions yet.")), 1)) : createCommentVNode("v-if", true),
								available.value?.error ? (openBlock(), createBlock(unref(KAlert), {
									key: 3,
									tone: "warning"
								}, {
									default: withCtx(() => [createTextVNode(toDisplayString(available.value.error), 1)]),
									_: 1
								})) : createCommentVNode("v-if", true),
								createVNode("div", { class: "k-updates__actions" }, [latest.value ? (openBlock(), createBlock(unref(KButton), {
									key: 0,
									variant: "primary",
									icon: "download-simple",
									loading: busy.value === "download",
									disabled: !!busy.value,
									onClick: reviewLatest
								}, {
									default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Review the update")), 1)]),
									_: 1
								}, 8, ["loading", "disabled"])) : createCommentVNode("v-if", true), createVNode(unref(KButton), {
									icon: "arrows-clockwise",
									loading: busy.value === "check",
									disabled: !!busy.value,
									onClick: checkNow
								}, {
									default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Check now")), 1)]),
									_: 1
								}, 8, ["loading", "disabled"])])
							]),
							!data.value.core.writable ? (openBlock(), createBlock(unref(KAlert), {
								key: 0,
								tone: "warning"
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("PHP cannot write to the core folder, so Kestello can only be updated by uploading files to the server.")), 1)]),
								_: 1
							})) : createCommentVNode("v-if", true)
						];
					}),
					_: 1
				}, _parent));
				_push(ssrRenderComponent(unref(KCard), {
					flush: "",
					class: "k-updates__packages"
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(ssrRenderComponent(unref(KTable), {
							columns: [
								{
									key: "name",
									label: unref(__)("Plugins and themes")
								},
								{
									key: "kind",
									label: unref(__)("Kind")
								},
								{
									key: "version",
									label: unref(__)("Version"),
									align: "end"
								}
							],
							rows: packages.value,
							"row-key": "key",
							empty: unref(__)("No plugins or themes installed.")
						}, {
							"cell-name": withCtx(({ row }, _push, _parent, _scopeId) => {
								if (_push) {
									_push(`${ssrInterpolate(row.name)} `);
									if (row.active) _push(ssrRenderComponent(unref(KBadge), { tone: "success" }, {
										default: withCtx((_, _push, _parent, _scopeId) => {
											if (_push) _push(`${ssrInterpolate(unref(__)("Active"))}`);
											else return [createTextVNode(toDisplayString(unref(__)("Active")), 1)];
										}),
										_: 2
									}, _parent, _scopeId));
									else _push(`<!---->`);
								} else return [createTextVNode(toDisplayString(row.name) + " ", 1), row.active ? (openBlock(), createBlock(unref(KBadge), {
									key: 0,
									tone: "success"
								}, {
									default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Active")), 1)]),
									_: 1
								})) : createCommentVNode("v-if", true)];
							}),
							"cell-version": withCtx(({ row }, _push, _parent, _scopeId) => {
								if (_push) _push(`<span class="k-num" data-v-fe4cc94d${_scopeId}>${ssrInterpolate(row.version)}</span>`);
								else return [createVNode("span", { class: "k-num" }, toDisplayString(row.version), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						else return [createVNode(unref(KTable), {
							columns: [
								{
									key: "name",
									label: unref(__)("Plugins and themes")
								},
								{
									key: "kind",
									label: unref(__)("Kind")
								},
								{
									key: "version",
									label: unref(__)("Version"),
									align: "end"
								}
							],
							rows: packages.value,
							"row-key": "key",
							empty: unref(__)("No plugins or themes installed.")
						}, {
							"cell-name": withCtx(({ row }) => [createTextVNode(toDisplayString(row.name) + " ", 1), row.active ? (openBlock(), createBlock(unref(KBadge), {
								key: 0,
								tone: "success"
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Active")), 1)]),
								_: 1
							})) : createCommentVNode("v-if", true)]),
							"cell-version": withCtx(({ row }) => [createVNode("span", { class: "k-num" }, toDisplayString(row.version), 1)]),
							_: 1
						}, 8, [
							"columns",
							"rows",
							"empty"
						])];
					}),
					_: 1
				}, _parent));
				_push(`<p class="k-muted k-small k-updates__note" data-v-fe4cc94d>${ssrInterpolate(unref(__)("Plugins are updated by uploading their new version under Plugins → Manage plugins."))}</p><!--]-->`);
			} else _push(`<!---->`);
			_push(ssrRenderComponent(unref(KModal), {
				open: open.value,
				"onUpdate:open": ($event) => open.value = $event,
				title: release.value ? unref(__)("Update to Kestello {v}?", { v: release.value.version }) : unref(__)("Upload a Kestello release"),
				size: "lg",
				dismissible: busy.value !== "apply"
			}, {
				footer: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(ssrRenderComponent(unref(KButton), {
							disabled: busy.value === "apply",
							onClick: ($event) => open.value = false
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(failed.value ? unref(__)("Close") : unref(__)("Cancel"))}`);
								else return [createTextVNode(toDisplayString(failed.value ? unref(__)("Close") : unref(__)("Cancel")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						if (!review.value) _push(ssrRenderComponent(unref(KButton), {
							type: "submit",
							form: "k-core-upload",
							variant: "primary",
							icon: "upload",
							loading: busy.value === "upload",
							disabled: !file.value
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Upload and review"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Upload and review")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						else _push(ssrRenderComponent(unref(KButton), {
							variant: "primary",
							icon: "arrows-clockwise",
							loading: busy.value === "apply",
							disabled: !release.value.allowed || release.value.problems.length > 0 || !!failed.value,
							onClick: apply
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Update to {v}", { v: release.value.version }))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Update to {v}", { v: release.value.version })), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
					} else return [createVNode(unref(KButton), {
						disabled: busy.value === "apply",
						onClick: ($event) => open.value = false
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(failed.value ? unref(__)("Close") : unref(__)("Cancel")), 1)]),
						_: 1
					}, 8, ["disabled", "onClick"]), !review.value ? (openBlock(), createBlock(unref(KButton), {
						key: 0,
						type: "submit",
						form: "k-core-upload",
						variant: "primary",
						icon: "upload",
						loading: busy.value === "upload",
						disabled: !file.value
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Upload and review")), 1)]),
						_: 1
					}, 8, ["loading", "disabled"])) : (openBlock(), createBlock(unref(KButton), {
						key: 1,
						variant: "primary",
						icon: "arrows-clockwise",
						loading: busy.value === "apply",
						disabled: !release.value.allowed || release.value.problems.length > 0 || !!failed.value,
						onClick: apply
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Update to {v}", { v: release.value.version })), 1)]),
						_: 1
					}, 8, ["loading", "disabled"]))];
				}),
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						if (!review.value) {
							_push(`<form id="k-core-upload" class="k-form" data-v-fe4cc94d${_scopeId}><p class="k-muted k-small" data-v-fe4cc94d${_scopeId}>${ssrInterpolate(unref(__)("Choose a kestello-core zip, for a site that cannot reach GitHub. Nothing changes yet: you see the version, who signed it and what it would affect first."))}</p>`);
							_push(ssrRenderComponent(unref(KField), {
								label: unref(__)("Release zip"),
								error: uploadErrors.value.file ?? ""
							}, {
								default: withCtx(({ id }, _push, _parent, _scopeId) => {
									if (_push) _push(`<input${ssrRenderAttr("id", id)} type="file" accept=".zip,application/zip" class="k-input" required data-v-fe4cc94d${_scopeId}>`);
									else return [createVNode("input", {
										id,
										type: "file",
										accept: ".zip,application/zip",
										class: "k-input",
										required: "",
										onChange: ($event) => file.value = $event.target.files?.[0] ?? null
									}, null, 40, ["id", "onChange"])];
								}),
								_: 1
							}, _parent, _scopeId));
							_push(`</form>`);
						} else {
							_push(`<div class="k-updates__review" data-v-fe4cc94d${_scopeId}><p data-v-fe4cc94d${_scopeId}><span class="k-num" data-v-fe4cc94d${_scopeId}>${ssrInterpolate(release.value.from)}</span> `);
							_push(ssrRenderComponent(unref(KIcon), { name: "arrow-right" }, null, _parent, _scopeId));
							_push(` <strong class="k-num" data-v-fe4cc94d${_scopeId}>${ssrInterpolate(release.value.version)}</strong></p><p class="${ssrRenderClass([`is-${signature.value.tone}`, "k-updates__sig"])}" data-v-fe4cc94d${_scopeId}>`);
							_push(ssrRenderComponent(unref(KIcon), { name: signature.value.icon }, null, _parent, _scopeId));
							_push(` ${ssrInterpolate(signature.value.text)}</p><p class="k-muted k-small" data-v-fe4cc94d${_scopeId}>${ssrInterpolate(unref(__)("The database is backed up, visitors see a short maintenance page, and the site is checked on the new release: database changes, every active plugin, the home page. If anything is wrong, {v} and the database come back.", { v: release.value.from }))}</p>`);
							if (failed.value) _push(ssrRenderComponent(unref(KAlert), {
								tone: "danger",
								title: unref(__)("The update was not applied")
							}, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) {
										_push(`<!--[-->`);
										ssrRenderList(failed.value.problems, (p) => {
											_push(`<p class="k-updates__problem" data-v-fe4cc94d${_scopeId}>${ssrInterpolate(p)}</p>`);
										});
										_push(`<!--]-->`);
										if (failed.value.rolled_back) _push(`<p data-v-fe4cc94d${_scopeId}>${ssrInterpolate(unref(__)("Kestello {v} and the database were put back. The site runs as before.", { v: failed.value.from }))}</p>`);
										else {
											_push(`<!--[-->`);
											ssrRenderList(failed.value.rollback_problems, (p) => {
												_push(`<p data-v-fe4cc94d${_scopeId}>${ssrInterpolate(p)}</p>`);
											});
											_push(`<!--]-->`);
										}
									} else return [(openBlock(true), createBlock(Fragment, null, renderList(failed.value.problems, (p) => {
										return openBlock(), createBlock("p", {
											key: p,
											class: "k-updates__problem"
										}, toDisplayString(p), 1);
									}), 128)), failed.value.rolled_back ? (openBlock(), createBlock("p", { key: 0 }, toDisplayString(unref(__)("Kestello {v} and the database were put back. The site runs as before.", { v: failed.value.from })), 1)) : (openBlock(true), createBlock(Fragment, { key: 1 }, renderList(failed.value.rollback_problems, (p) => {
										return openBlock(), createBlock("p", { key: p }, toDisplayString(p), 1);
									}), 128))];
								}),
								_: 1
							}, _parent, _scopeId));
							else _push(`<!---->`);
							if (blocked.value) _push(ssrRenderComponent(unref(KAlert), { tone: "warning" }, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate(blocked.value)}`);
									else return [createTextVNode(toDisplayString(blocked.value), 1)];
								}),
								_: 1
							}, _parent, _scopeId));
							else if (release.value.problems.length) _push(ssrRenderComponent(unref(KAlert), {
								tone: "warning",
								title: unref(__)("This release cannot be applied")
							}, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) {
										_push(`<!--[-->`);
										ssrRenderList(release.value.problems, (p) => {
											_push(`<p class="k-updates__problem" data-v-fe4cc94d${_scopeId}>${ssrInterpolate(p)}</p>`);
										});
										_push(`<!--]-->`);
									} else return [(openBlock(true), createBlock(Fragment, null, renderList(release.value.problems, (p) => {
										return openBlock(), createBlock("p", {
											key: p,
											class: "k-updates__problem"
										}, toDisplayString(p), 1);
									}), 128))];
								}),
								_: 1
							}, _parent, _scopeId));
							else _push(`<!---->`);
							_push(`</div>`);
						}
					} else return [!review.value ? (openBlock(), createBlock("form", {
						key: 0,
						id: "k-core-upload",
						class: "k-form",
						onSubmit: withModifiers(upload, ["prevent"])
					}, [createVNode("p", { class: "k-muted k-small" }, toDisplayString(unref(__)("Choose a kestello-core zip, for a site that cannot reach GitHub. Nothing changes yet: you see the version, who signed it and what it would affect first.")), 1), createVNode(unref(KField), {
						label: unref(__)("Release zip"),
						error: uploadErrors.value.file ?? ""
					}, {
						default: withCtx(({ id }) => [createVNode("input", {
							id,
							type: "file",
							accept: ".zip,application/zip",
							class: "k-input",
							required: "",
							onChange: ($event) => file.value = $event.target.files?.[0] ?? null
						}, null, 40, ["id", "onChange"])]),
						_: 1
					}, 8, ["label", "error"])], 32)) : (openBlock(), createBlock("div", {
						key: 1,
						class: "k-updates__review"
					}, [
						createVNode("p", null, [
							createVNode("span", { class: "k-num" }, toDisplayString(release.value.from), 1),
							createTextVNode(),
							createVNode(unref(KIcon), { name: "arrow-right" }),
							createTextVNode(),
							createVNode("strong", { class: "k-num" }, toDisplayString(release.value.version), 1)
						]),
						createVNode("p", { class: ["k-updates__sig", `is-${signature.value.tone}`] }, [createVNode(unref(KIcon), { name: signature.value.icon }, null, 8, ["name"]), createTextVNode(" " + toDisplayString(signature.value.text), 1)], 2),
						createVNode("p", { class: "k-muted k-small" }, toDisplayString(unref(__)("The database is backed up, visitors see a short maintenance page, and the site is checked on the new release: database changes, every active plugin, the home page. If anything is wrong, {v} and the database come back.", { v: release.value.from })), 1),
						failed.value ? (openBlock(), createBlock(unref(KAlert), {
							key: 0,
							tone: "danger",
							title: unref(__)("The update was not applied")
						}, {
							default: withCtx(() => [(openBlock(true), createBlock(Fragment, null, renderList(failed.value.problems, (p) => {
								return openBlock(), createBlock("p", {
									key: p,
									class: "k-updates__problem"
								}, toDisplayString(p), 1);
							}), 128)), failed.value.rolled_back ? (openBlock(), createBlock("p", { key: 0 }, toDisplayString(unref(__)("Kestello {v} and the database were put back. The site runs as before.", { v: failed.value.from })), 1)) : (openBlock(true), createBlock(Fragment, { key: 1 }, renderList(failed.value.rollback_problems, (p) => {
								return openBlock(), createBlock("p", { key: p }, toDisplayString(p), 1);
							}), 128))]),
							_: 1
						}, 8, ["title"])) : createCommentVNode("v-if", true),
						blocked.value ? (openBlock(), createBlock(unref(KAlert), {
							key: 1,
							tone: "warning"
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(blocked.value), 1)]),
							_: 1
						})) : release.value.problems.length ? (openBlock(), createBlock(unref(KAlert), {
							key: 2,
							tone: "warning",
							title: unref(__)("This release cannot be applied")
						}, {
							default: withCtx(() => [(openBlock(true), createBlock(Fragment, null, renderList(release.value.problems, (p) => {
								return openBlock(), createBlock("p", {
									key: p,
									class: "k-updates__problem"
								}, toDisplayString(p), 1);
							}), 128))]),
							_: 1
						}, 8, ["title"])) : createCommentVNode("v-if", true)
					]))];
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
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/admin/system/Updates.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
var Updates_default = /*#__PURE__*/ _plugin_vue_export_helper_default(_sfc_main, [["__scopeId", "data-v-fe4cc94d"]]);
export { Updates_default as default };
