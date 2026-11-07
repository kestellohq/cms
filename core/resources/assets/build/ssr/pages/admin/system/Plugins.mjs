import { t as _plugin_vue_export_helper_default } from "../../../chunks/_plugin-vue_export-helper-DjkACiAr.mjs";
import { Fragment, computed, createBlock, createCommentVNode, createSlots, createTextVNode, createVNode, openBlock, ref, renderList, toDisplayString, unref, useSSRContext, withCtx, withModifiers } from "vue";
import { ssrInterpolate, ssrRenderAttr, ssrRenderClass, ssrRenderComponent, ssrRenderList } from "vue/server-renderer";
import { ApiError, __, confirm, notify, useApi } from "@kestello/sdk";
import { KAlert, KBadge, KButton, KEmpty, KField, KIcon, KModal, KPageHeader, KSwitch } from "@kestello/ui";
// core/resources/js/pages/admin/system/Plugins.vue
var _sfc_main = /*@__PURE__*/ Object.assign({ layout: "Admin" }, {
	__name: "Plugins",
	__ssrInlineRender: true,
	setup(__props) {
		/**
		* Plugins: activate, deactivate, delete, and install from a zip. An upload
		* is reviewed first (who signed it, what it declares it will do); only
		* packages signed by a publisher the server trusts install from here.
		*/
		const api = useApi();
		const data = ref(null);
		const loadError = ref(null);
		const busy = ref("");
		const open = ref({});
		async function load() {
			try {
				data.value = (await api.get("/plugins")).data;
			} catch (e) {
				loadError.value = e;
			}
		}
		load();
		const statusTone = {
			active: "success",
			inactive: "neutral",
			suspended: "danger",
			failed: "danger"
		};
		const statusLabel = {
			active: __("Active"),
			inactive: __("Inactive"),
			suspended: __("Suspended"),
			failed: __("Failed to start")
		};
		const names = computed(() => Object.fromEntries((data.value?.plugins ?? []).map((p) => [p.slug, p.name])));
		const permissionLabels = {
			http: __("Connects to"),
			database: __("Database"),
			filesystem: __("Files"),
			mail: __("Sends email"),
			events: __("Listens to"),
			capabilities: __("Adds permissions")
		};
		function permissionRows(permissions, replaces = []) {
			const rows = Object.entries(permissions ?? {}).map(([area, value]) => ({
				label: permissionLabels[area] ?? area,
				value: Array.isArray(value) ? value.join(", ") : typeof value === "object" && value !== null ? Object.entries(value).map(([k, v]) => `${k}: ${v}`).join(", ") : String(value)
			}));
			if (replaces.length) rows.push({
				label: __("Replaces core services"),
				value: replaces.join(", ")
			});
			return rows;
		}
		async function act(plugin, action) {
			busy.value = `${action}:${plugin.slug}`;
			try {
				data.value = (await api.post(`/plugins/${plugin.slug}/${action}`)).data;
				notify.success(action === "activate" ? __("{plugin} is active.", { plugin: plugin.name }) : __("{plugin} was deactivated. Its data is kept.", { plugin: plugin.name }));
			} catch (e) {
				notify.error(e);
			} finally {
				busy.value = "";
			}
		}
		async function remove(plugin) {
			if (!await confirm({
				title: __("Delete {plugin}?", { plugin: plugin.name }),
				message: __("Its files, its tables and its settings are deleted. Make a backup first if you may want them back."),
				confirmLabel: __("Delete plugin and data"),
				danger: true
			})) return;
			busy.value = `delete:${plugin.slug}`;
			try {
				data.value = (await api.delete(`/plugins/${plugin.slug}`)).data;
				notify.success(__("{plugin} was deleted.", { plugin: plugin.name }));
			} catch (e) {
				notify.error(e);
			} finally {
				busy.value = "";
			}
		}
		const adding = ref(false);
		const file = ref(null);
		const review = ref(null);
		const uploadErrors = ref({});
		const replace = ref(false);
		const activate = ref(true);
		const failed = ref(null);
		function openAdd() {
			adding.value = true;
			file.value = null;
			review.value = null;
			uploadErrors.value = {};
			replace.value = false;
			activate.value = true;
			failed.value = null;
		}
		async function upload() {
			if (!file.value) return;
			busy.value = "upload";
			uploadErrors.value = {};
			const form = new FormData();
			form.append("file", file.value);
			try {
				review.value = (await api.upload("/plugins/upload", form)).data;
				replace.value = !!review.value.package.installed && !review.value.package.installed.active;
			} catch (e) {
				if (e instanceof ApiError && e.status === 422) uploadErrors.value = e.fieldErrors();
				else notify.error(e);
			} finally {
				busy.value = "";
			}
		}
		const pkg = computed(() => review.value?.package ?? null);
		const warnings = computed(() => (pkg.value?.findings ?? []).filter((f) => f.level !== "error"));
		const errors = computed(() => (pkg.value?.findings ?? []).filter((f) => f.level === "error"));
		const signature = computed(() => {
			const s = pkg.value?.signature;
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
					text: __("Changed after it was signed. Do not install it.")
				}
			}[s.status];
		});
		const blockedReason = computed(() => {
			if (!review.value || review.value.allowed) return "";
			if (pkg.value.signature.status === "tampered") return __("This package was changed after it was signed, so it is refused.");
			return data.value?.policy.trusted.length ? __("Only packages signed by {publishers} install from here. A developer can install this one on the server: bin/cms plugin:install <zip>.", { publishers: data.value.policy.trusted.join(", ") }) : __("No publisher is trusted on this site yet, so plugins install on the server: bin/cms plugin:install <zip>. Trusted publishers are set in config/app.php (packages.trusted_keys).");
		});
		const replaceBlocked = computed(() => pkg.value?.installed?.active);
		const update = computed(() => review.value?.update ?? null);
		async function runUpdate() {
			busy.value = "update";
			failed.value = null;
			try {
				const response = (await api.post("/plugins/update", { token: review.value.token })).data;
				data.value = response;
				const r = response.result;
				if (r.ok) {
					adding.value = false;
					notify.success(__("{plugin} was updated to {v}.", {
						plugin: pkg.value.name,
						v: r.to
					}));
				} else failed.value = r;
			} catch (e) {
				notify.error(e);
			} finally {
				busy.value = "";
			}
		}
		async function install() {
			busy.value = "install";
			try {
				const result = (await api.post("/plugins/install", {
					token: review.value.token,
					replace: replace.value,
					activate: activate.value
				})).data;
				data.value = result;
				adding.value = false;
				if (result.activation_problem) notify.error(__("{plugin} was installed but could not be activated: {problem}", {
					plugin: pkg.value.name,
					problem: result.activation_problem
				}));
				else notify.success(result.activated ? __("{plugin} was installed and is active.", { plugin: pkg.value.name }) : __("{plugin} was installed.", { plugin: pkg.value.name }));
			} catch (e) {
				notify.error(e);
			} finally {
				busy.value = "";
			}
		}
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<!--[-->`);
			_push(ssrRenderComponent(unref(KPageHeader), {
				title: unref(__)("Plugins"),
				description: unref(__)("Plugins add features to the site. Each one declares what it may do, and one that breaks is switched off on its own instead of taking the site down.")
			}, createSlots({ _: 2 }, [data.value ? {
				name: "actions",
				fn: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(ssrRenderComponent(unref(KButton), {
						variant: "primary",
						icon: "upload",
						onClick: openAdd
					}, {
						default: withCtx((_, _push, _parent, _scopeId) => {
							if (_push) _push(`${ssrInterpolate(unref(__)("Upload a plugin"))}`);
							else return [createTextVNode(toDisplayString(unref(__)("Upload a plugin")), 1)];
						}),
						_: 1
					}, _parent, _scopeId));
					else return [createVNode(unref(KButton), {
						variant: "primary",
						icon: "upload",
						onClick: openAdd
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Upload a plugin")), 1)]),
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
			if (data.value?.recovery) _push(ssrRenderComponent(unref(KAlert), {
				tone: "warning",
				title: unref(__)("Recovery mode")
			}, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(`${ssrInterpolate(unref(__)("No plugins are running in this session, so you can deactivate or delete the one causing trouble."))}`);
					else return [createTextVNode(toDisplayString(unref(__)("No plugins are running in this session, so you can deactivate or delete the one causing trouble.")), 1)];
				}),
				_: 1
			}, _parent));
			else _push(`<!---->`);
			_push(`<!--[-->`);
			ssrRenderList(data.value?.invalid ?? [], (msg) => {
				_push(ssrRenderComponent(unref(KAlert), {
					key: msg,
					tone: "warning"
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`${ssrInterpolate(msg)}`);
						else return [createTextVNode(toDisplayString(msg), 1)];
					}),
					_: 2
				}, _parent));
			});
			_push(`<!--]-->`);
			if (data.value) {
				_push(`<!--[-->`);
				if (!data.value.plugins.length) _push(ssrRenderComponent(unref(KEmpty), {
					icon: "plug",
					title: unref(__)("No plugins installed")
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`${ssrInterpolate(unref(__)("Upload a plugin zip to add one."))}`);
						else return [createTextVNode(toDisplayString(unref(__)("Upload a plugin zip to add one.")), 1)];
					}),
					_: 1
				}, _parent));
				else {
					_push(`<ul class="k-plugins" data-v-32699f29><!--[-->`);
					ssrRenderList(data.value.plugins, (p) => {
						_push(`<li class="${ssrRenderClass([`is-${p.status}`, "k-plugin"])}" data-v-32699f29><div class="k-plugin__main" data-v-32699f29><div class="k-plugin__title" data-v-32699f29><h2 data-v-32699f29>${ssrInterpolate(p.name)}</h2><span class="k-num k-muted k-small" data-v-32699f29>${ssrInterpolate(p.version)}</span>`);
						_push(ssrRenderComponent(unref(KBadge), { tone: statusTone[p.status] }, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(statusLabel[p.status])}`);
								else return [createTextVNode(toDisplayString(statusLabel[p.status]), 1)];
							}),
							_: 2
						}, _parent));
						_push(`</div>`);
						if (p.description) _push(`<p class="k-plugin__desc" data-v-32699f29>${ssrInterpolate(p.description)}</p>`);
						else _push(`<!---->`);
						_push(`<p class="k-muted k-small k-plugin__meta" data-v-32699f29>`);
						if (p.author) _push(`<!--[-->${ssrInterpolate(unref(__)("By {author}", { author: p.author }))}<!--]-->`);
						else _push(`<!---->`);
						if (Object.keys(p.requires.plugins).length) _push(`<!--[--> · ${ssrInterpolate(unref(__)("Needs {plugins}", { plugins: Object.keys(p.requires.plugins).map((s) => names.value[s] ?? s).join(", ") }))}<!--]-->`);
						else _push(`<!---->`);
						if (p.required_by.length) _push(`<!--[--> · ${ssrInterpolate(unref(__)("Needed by {plugins}", { plugins: p.required_by.map((s) => names.value[s] ?? s).join(", ") }))}<!--]-->`);
						else _push(`<!---->`);
						_push(`</p>`);
						if (p.problem) {
							_push(`<p class="k-plugin__problem" data-v-32699f29>`);
							_push(ssrRenderComponent(unref(KIcon), { name: "warning-octagon" }, null, _parent));
							_push(` ${ssrInterpolate(p.problem)}</p>`);
						} else _push(`<!---->`);
						if (permissionRows(p.permissions, p.replaces).length) _push(`<button type="button" class="k-link-btn k-small"${ssrRenderAttr("aria-expanded", !!open.value[p.slug])} data-v-32699f29>${ssrInterpolate(open.value[p.slug] ? unref(__)("Hide what it may do") : unref(__)("What it may do"))}</button>`);
						else _push(`<!---->`);
						if (open.value[p.slug]) {
							_push(`<dl class="k-plugin__perms" data-v-32699f29><!--[-->`);
							ssrRenderList(permissionRows(p.permissions, p.replaces), (row) => {
								_push(`<!--[--><dt data-v-32699f29>${ssrInterpolate(row.label)}</dt><dd data-v-32699f29>${ssrInterpolate(row.value)}</dd><!--]-->`);
							});
							_push(`<!--]--></dl>`);
						} else _push(`<!---->`);
						_push(`</div><div class="k-plugin__actions" data-v-32699f29>`);
						if (p.status === "inactive") _push(ssrRenderComponent(unref(KButton), {
							size: "sm",
							variant: "primary",
							loading: busy.value === `activate:${p.slug}`,
							onClick: ($event) => act(p, "activate")
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Activate"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Activate")), 1)];
							}),
							_: 2
						}, _parent));
						else _push(ssrRenderComponent(unref(KButton), {
							size: "sm",
							loading: busy.value === `deactivate:${p.slug}`,
							disabled: p.required_by.length > 0,
							onClick: ($event) => act(p, "deactivate")
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Deactivate"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Deactivate")), 1)];
							}),
							_: 2
						}, _parent));
						if (p.status === "inactive") _push(ssrRenderComponent(unref(KButton), {
							size: "sm",
							variant: "ghost",
							icon: "trash",
							loading: busy.value === `delete:${p.slug}`,
							"aria-label": unref(__)("Delete {plugin}", { plugin: p.name }),
							onClick: ($event) => remove(p)
						}, null, _parent));
						else _push(`<!---->`);
						_push(`</div></li>`);
					});
					_push(`<!--]--></ul>`);
				}
				_push(`<!--]-->`);
			} else _push(`<!---->`);
			_push(ssrRenderComponent(unref(KModal), {
				open: adding.value,
				"onUpdate:open": ($event) => adding.value = $event,
				title: review.value ? update.value ? unref(__)("Update {plugin}?", { plugin: pkg.value.name }) : unref(__)("Install {plugin}?", { plugin: pkg.value.name }) : unref(__)("Upload a plugin"),
				size: "lg",
				dismissible: busy.value !== "update"
			}, {
				footer: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(ssrRenderComponent(unref(KButton), {
							disabled: busy.value === "update",
							onClick: ($event) => adding.value = false
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(failed.value ? unref(__)("Close") : unref(__)("Cancel"))}`);
								else return [createTextVNode(toDisplayString(failed.value ? unref(__)("Close") : unref(__)("Cancel")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						if (!review.value) _push(ssrRenderComponent(unref(KButton), {
							type: "submit",
							form: "k-plugin-upload",
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
						else if (update.value) _push(ssrRenderComponent(unref(KButton), {
							variant: "primary",
							icon: "arrows-clockwise",
							loading: busy.value === "update",
							disabled: !review.value.allowed || errors.value.length > 0 || update.value.problems.length > 0 || !!failed.value,
							onClick: runUpdate
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Update to {v}", { v: pkg.value.version }))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Update to {v}", { v: pkg.value.version })), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						else _push(ssrRenderComponent(unref(KButton), {
							variant: "primary",
							icon: "check",
							loading: busy.value === "install",
							disabled: !review.value.allowed || errors.value.length > 0 || pkg.value.installed && (!replace.value || replaceBlocked.value),
							onClick: install
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Install"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Install")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
					} else return [createVNode(unref(KButton), {
						disabled: busy.value === "update",
						onClick: ($event) => adding.value = false
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(failed.value ? unref(__)("Close") : unref(__)("Cancel")), 1)]),
						_: 1
					}, 8, ["disabled", "onClick"]), !review.value ? (openBlock(), createBlock(unref(KButton), {
						key: 0,
						type: "submit",
						form: "k-plugin-upload",
						variant: "primary",
						icon: "upload",
						loading: busy.value === "upload",
						disabled: !file.value
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Upload and review")), 1)]),
						_: 1
					}, 8, ["loading", "disabled"])) : update.value ? (openBlock(), createBlock(unref(KButton), {
						key: 1,
						variant: "primary",
						icon: "arrows-clockwise",
						loading: busy.value === "update",
						disabled: !review.value.allowed || errors.value.length > 0 || update.value.problems.length > 0 || !!failed.value,
						onClick: runUpdate
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Update to {v}", { v: pkg.value.version })), 1)]),
						_: 1
					}, 8, ["loading", "disabled"])) : (openBlock(), createBlock(unref(KButton), {
						key: 2,
						variant: "primary",
						icon: "check",
						loading: busy.value === "install",
						disabled: !review.value.allowed || errors.value.length > 0 || pkg.value.installed && (!replace.value || replaceBlocked.value),
						onClick: install
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Install")), 1)]),
						_: 1
					}, 8, ["loading", "disabled"]))];
				}),
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						if (!review.value) {
							_push(`<form id="k-plugin-upload" class="k-form" data-v-32699f29${_scopeId}><p class="k-muted k-small k-plugins__note" data-v-32699f29${_scopeId}>${ssrInterpolate(unref(__)("Choose a plugin zip. Nothing is installed yet: you see who signed it and what it declares first."))}</p>`);
							_push(ssrRenderComponent(unref(KField), {
								label: unref(__)("Plugin zip"),
								error: uploadErrors.value.file ?? ""
							}, {
								default: withCtx(({ id }, _push, _parent, _scopeId) => {
									if (_push) _push(`<input${ssrRenderAttr("id", id)} type="file" accept=".zip,application/zip" class="k-input" required data-v-32699f29${_scopeId}>`);
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
							_push(`<div class="k-review" data-v-32699f29${_scopeId}><p class="k-review__head" data-v-32699f29${_scopeId}><strong data-v-32699f29${_scopeId}>${ssrInterpolate(pkg.value.name)}</strong> <span class="k-num k-muted" data-v-32699f29${_scopeId}>${ssrInterpolate(pkg.value.version)}</span>`);
							if (pkg.value.author) _push(`<span class="k-muted" data-v-32699f29${_scopeId}> · ${ssrInterpolate(unref(__)("By {author}", { author: pkg.value.author }))}</span>`);
							else _push(`<!---->`);
							_push(`</p>`);
							if (pkg.value.description) _push(`<p class="k-review__desc" data-v-32699f29${_scopeId}>${ssrInterpolate(pkg.value.description)}</p>`);
							else _push(`<!---->`);
							_push(`<p class="${ssrRenderClass([`is-${signature.value.tone}`, "k-review__sig"])}" data-v-32699f29${_scopeId}>`);
							_push(ssrRenderComponent(unref(KIcon), { name: signature.value.icon }, null, _parent, _scopeId));
							_push(` ${ssrInterpolate(signature.value.text)}</p>`);
							if (update.value) _push(`<p class="k-muted k-small" data-v-32699f29${_scopeId}>${ssrInterpolate(unref(__)("Updates version {from}. The database is backed up and the site is checked after the new files are in place; if anything is wrong, version {from} and the database are put back.", { from: update.value.from }))}</p>`);
							else if (pkg.value.installed) _push(`<p class="k-muted k-small" data-v-32699f29${_scopeId}>${ssrInterpolate(unref(__)("Version {v} is installed (inactive).", { v: pkg.value.installed.version }))}</p>`);
							else _push(`<!---->`);
							_push(`<h3 class="k-review__h" data-v-32699f29${_scopeId}>${ssrInterpolate(unref(__)("What it may do"))}</h3>`);
							if (permissionRows(pkg.value.permissions, pkg.value.replaces).length) {
								_push(`<dl class="k-plugin__perms" data-v-32699f29${_scopeId}><!--[-->`);
								ssrRenderList(permissionRows(pkg.value.permissions, pkg.value.replaces), (row) => {
									_push(`<!--[--><dt data-v-32699f29${_scopeId}>${ssrInterpolate(row.label)}</dt><dd data-v-32699f29${_scopeId}>${ssrInterpolate(row.value)}</dd><!--]-->`);
								});
								_push(`<!--]--></dl>`);
							} else _push(`<p class="k-muted k-small" data-v-32699f29${_scopeId}>${ssrInterpolate(unref(__)("It declares nothing beyond its own pages, tables and settings."))}</p>`);
							if (errors.value.length || warnings.value.length) {
								_push(`<!--[--><h3 class="k-review__h" data-v-32699f29${_scopeId}>${ssrInterpolate(unref(__)("Code check"))}</h3><ul class="k-review__findings" data-v-32699f29${_scopeId}><!--[-->`);
								ssrRenderList([...errors.value, ...warnings.value], (f) => {
									_push(`<li class="${ssrRenderClass(`is-${f.level}`)}" data-v-32699f29${_scopeId}>`);
									_push(ssrRenderComponent(unref(KIcon), { name: f.level === "error" ? "x-circle" : "warning" }, null, _parent, _scopeId));
									_push(` <code data-v-32699f29${_scopeId}>${ssrInterpolate(f.file)}${ssrInterpolate(f.line ? `:${f.line}` : "")}</code> ${ssrInterpolate(f.message)}</li>`);
								});
								_push(`<!--]--></ul><!--]-->`);
							} else _push(`<!---->`);
							if (failed.value) _push(ssrRenderComponent(unref(KAlert), {
								tone: "danger",
								title: unref(__)("The update was not applied")
							}, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) {
										_push(`<!--[-->`);
										ssrRenderList(failed.value.problems, (problem) => {
											_push(`<p class="k-review__problem" data-v-32699f29${_scopeId}>${ssrInterpolate(problem)}</p>`);
										});
										_push(`<!--]-->`);
										if (failed.value.rolled_back) _push(`<p data-v-32699f29${_scopeId}>${ssrInterpolate(unref(__)("Version {v} and the database were put back. The site runs as before.", { v: failed.value.from }))}</p>`);
										else {
											_push(`<!--[-->`);
											ssrRenderList(failed.value.rollback_problems, (problem) => {
												_push(`<p data-v-32699f29${_scopeId}>${ssrInterpolate(problem)}</p>`);
											});
											_push(`<!--]-->`);
										}
									} else return [(openBlock(true), createBlock(Fragment, null, renderList(failed.value.problems, (problem) => {
										return openBlock(), createBlock("p", {
											key: problem,
											class: "k-review__problem"
										}, toDisplayString(problem), 1);
									}), 128)), failed.value.rolled_back ? (openBlock(), createBlock("p", { key: 0 }, toDisplayString(unref(__)("Version {v} and the database were put back. The site runs as before.", { v: failed.value.from })), 1)) : (openBlock(true), createBlock(Fragment, { key: 1 }, renderList(failed.value.rollback_problems, (problem) => {
										return openBlock(), createBlock("p", { key: problem }, toDisplayString(problem), 1);
									}), 128))];
								}),
								_: 1
							}, _parent, _scopeId));
							else _push(`<!---->`);
							if (blockedReason.value) _push(ssrRenderComponent(unref(KAlert), { tone: "warning" }, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate(blockedReason.value)}`);
									else return [createTextVNode(toDisplayString(blockedReason.value), 1)];
								}),
								_: 1
							}, _parent, _scopeId));
							else if (update.value?.problems.length) _push(ssrRenderComponent(unref(KAlert), {
								tone: "warning",
								title: unref(__)("This update cannot be applied")
							}, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) {
										_push(`<!--[-->`);
										ssrRenderList(update.value.problems, (problem) => {
											_push(`<p class="k-review__problem" data-v-32699f29${_scopeId}>${ssrInterpolate(problem)}</p>`);
										});
										_push(`<!--]-->`);
									} else return [(openBlock(true), createBlock(Fragment, null, renderList(update.value.problems, (problem) => {
										return openBlock(), createBlock("p", {
											key: problem,
											class: "k-review__problem"
										}, toDisplayString(problem), 1);
									}), 128))];
								}),
								_: 1
							}, _parent, _scopeId));
							else if (!update.value) {
								_push(`<!--[-->`);
								if (pkg.value.installed) _push(ssrRenderComponent(unref(KSwitch), {
									modelValue: replace.value,
									"onUpdate:modelValue": ($event) => replace.value = $event,
									label: unref(__)("Replace the installed version"),
									disabled: replaceBlocked.value
								}, null, _parent, _scopeId));
								else _push(`<!---->`);
								_push(ssrRenderComponent(unref(KSwitch), {
									modelValue: activate.value,
									"onUpdate:modelValue": ($event) => activate.value = $event,
									label: unref(__)("Activate it after installing")
								}, null, _parent, _scopeId));
								_push(`<!--]-->`);
							} else _push(`<!---->`);
							_push(`</div>`);
						}
					} else return [!review.value ? (openBlock(), createBlock("form", {
						key: 0,
						id: "k-plugin-upload",
						class: "k-form",
						onSubmit: withModifiers(upload, ["prevent"])
					}, [createVNode("p", { class: "k-muted k-small k-plugins__note" }, toDisplayString(unref(__)("Choose a plugin zip. Nothing is installed yet: you see who signed it and what it declares first.")), 1), createVNode(unref(KField), {
						label: unref(__)("Plugin zip"),
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
						class: "k-review"
					}, [
						createVNode("p", { class: "k-review__head" }, [
							createVNode("strong", null, toDisplayString(pkg.value.name), 1),
							createTextVNode(),
							createVNode("span", { class: "k-num k-muted" }, toDisplayString(pkg.value.version), 1),
							pkg.value.author ? (openBlock(), createBlock("span", {
								key: 0,
								class: "k-muted"
							}, " · " + toDisplayString(unref(__)("By {author}", { author: pkg.value.author })), 1)) : createCommentVNode("v-if", true)
						]),
						pkg.value.description ? (openBlock(), createBlock("p", {
							key: 0,
							class: "k-review__desc"
						}, toDisplayString(pkg.value.description), 1)) : createCommentVNode("v-if", true),
						createVNode("p", { class: ["k-review__sig", `is-${signature.value.tone}`] }, [createVNode(unref(KIcon), { name: signature.value.icon }, null, 8, ["name"]), createTextVNode(" " + toDisplayString(signature.value.text), 1)], 2),
						update.value ? (openBlock(), createBlock("p", {
							key: 1,
							class: "k-muted k-small"
						}, toDisplayString(unref(__)("Updates version {from}. The database is backed up and the site is checked after the new files are in place; if anything is wrong, version {from} and the database are put back.", { from: update.value.from })), 1)) : pkg.value.installed ? (openBlock(), createBlock("p", {
							key: 2,
							class: "k-muted k-small"
						}, toDisplayString(unref(__)("Version {v} is installed (inactive).", { v: pkg.value.installed.version })), 1)) : createCommentVNode("v-if", true),
						createVNode("h3", { class: "k-review__h" }, toDisplayString(unref(__)("What it may do")), 1),
						permissionRows(pkg.value.permissions, pkg.value.replaces).length ? (openBlock(), createBlock("dl", {
							key: 3,
							class: "k-plugin__perms"
						}, [(openBlock(true), createBlock(Fragment, null, renderList(permissionRows(pkg.value.permissions, pkg.value.replaces), (row) => {
							return openBlock(), createBlock(Fragment, { key: row.label }, [createVNode("dt", null, toDisplayString(row.label), 1), createVNode("dd", null, toDisplayString(row.value), 1)], 64);
						}), 128))])) : (openBlock(), createBlock("p", {
							key: 4,
							class: "k-muted k-small"
						}, toDisplayString(unref(__)("It declares nothing beyond its own pages, tables and settings.")), 1)),
						errors.value.length || warnings.value.length ? (openBlock(), createBlock(Fragment, { key: 5 }, [createVNode("h3", { class: "k-review__h" }, toDisplayString(unref(__)("Code check")), 1), createVNode("ul", { class: "k-review__findings" }, [(openBlock(true), createBlock(Fragment, null, renderList([...errors.value, ...warnings.value], (f) => {
							return openBlock(), createBlock("li", {
								key: f.file + f.line + f.message,
								class: `is-${f.level}`
							}, [
								createVNode(unref(KIcon), { name: f.level === "error" ? "x-circle" : "warning" }, null, 8, ["name"]),
								createTextVNode(),
								createVNode("code", null, toDisplayString(f.file) + toDisplayString(f.line ? `:${f.line}` : ""), 1),
								createTextVNode(" " + toDisplayString(f.message), 1)
							], 2);
						}), 128))])], 64)) : createCommentVNode("v-if", true),
						failed.value ? (openBlock(), createBlock(unref(KAlert), {
							key: 6,
							tone: "danger",
							title: unref(__)("The update was not applied")
						}, {
							default: withCtx(() => [(openBlock(true), createBlock(Fragment, null, renderList(failed.value.problems, (problem) => {
								return openBlock(), createBlock("p", {
									key: problem,
									class: "k-review__problem"
								}, toDisplayString(problem), 1);
							}), 128)), failed.value.rolled_back ? (openBlock(), createBlock("p", { key: 0 }, toDisplayString(unref(__)("Version {v} and the database were put back. The site runs as before.", { v: failed.value.from })), 1)) : (openBlock(true), createBlock(Fragment, { key: 1 }, renderList(failed.value.rollback_problems, (problem) => {
								return openBlock(), createBlock("p", { key: problem }, toDisplayString(problem), 1);
							}), 128))]),
							_: 1
						}, 8, ["title"])) : createCommentVNode("v-if", true),
						blockedReason.value ? (openBlock(), createBlock(unref(KAlert), {
							key: 7,
							tone: "warning"
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(blockedReason.value), 1)]),
							_: 1
						})) : update.value?.problems.length ? (openBlock(), createBlock(unref(KAlert), {
							key: 8,
							tone: "warning",
							title: unref(__)("This update cannot be applied")
						}, {
							default: withCtx(() => [(openBlock(true), createBlock(Fragment, null, renderList(update.value.problems, (problem) => {
								return openBlock(), createBlock("p", {
									key: problem,
									class: "k-review__problem"
								}, toDisplayString(problem), 1);
							}), 128))]),
							_: 1
						}, 8, ["title"])) : !update.value ? (openBlock(), createBlock(Fragment, { key: 9 }, [pkg.value.installed ? (openBlock(), createBlock(unref(KSwitch), {
							key: 0,
							modelValue: replace.value,
							"onUpdate:modelValue": ($event) => replace.value = $event,
							label: unref(__)("Replace the installed version"),
							disabled: replaceBlocked.value
						}, null, 8, [
							"modelValue",
							"onUpdate:modelValue",
							"label",
							"disabled"
						])) : createCommentVNode("v-if", true), createVNode(unref(KSwitch), {
							modelValue: activate.value,
							"onUpdate:modelValue": ($event) => activate.value = $event,
							label: unref(__)("Activate it after installing")
						}, null, 8, [
							"modelValue",
							"onUpdate:modelValue",
							"label"
						])], 64)) : createCommentVNode("v-if", true)
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
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/admin/system/Plugins.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
var Plugins_default = /*#__PURE__*/ _plugin_vue_export_helper_default(_sfc_main, [["__scopeId", "data-v-32699f29"]]);
export { Plugins_default as default };
