import { n as switchBackOutcome, r as takeSwitchBack, t as rememberSwitchBack } from "../../../chunks/switch-back-DFscO2EH.mjs";
import { t as _plugin_vue_export_helper_default } from "../../../chunks/_plugin-vue_export-helper-DjkACiAr.mjs";
import { Fragment, computed, createBlock, createCommentVNode, createSlots, createTextVNode, createVNode, isRef, openBlock, reactive, ref, toDisplayString, unref, useSSRContext, watch, withCtx, withModifiers } from "vue";
import { ssrInterpolate, ssrRenderAttr, ssrRenderClass, ssrRenderComponent, ssrRenderList } from "vue/server-renderer";
import { ApiError, __, confirm, formatDateTime, notify, useApi, useCan } from "@kestello/sdk";
import { KAlert, KBadge, KButton, KField, KIcon, KInput, KModal, KPageHeader, KSelect, KSwitch } from "@kestello/ui";
// core/resources/js/pages/admin/appearance/Themes.vue
var _sfc_main = /*@__PURE__*/ Object.assign({ layout: "Admin" }, {
	__name: "Themes",
	__ssrInlineRender: true,
	setup(__props) {
		/**
		* Appearance → Themes: switch themes, make a new one from the current
		* look, and move themes between sites as a zip. Themes made here hold no
		* code (tokens, CSS, templates, parts, patterns and fonts), so importing
		* one can't run anything on the server. After a developer theme is
		* updated, Switch back goes to the version before it, with the database
		* from before it (people who manage plugins only: it is code).
		*/
		const api = useApi();
		const data = ref(null);
		const loadError = ref(null);
		const busy = ref("");
		const can = useCan();
		async function load() {
			try {
				data.value = (await api.get("/themes")).data;
			} catch (e) {
				loadError.value = e;
				return;
			}
			const note = takeSwitchBack();
			if (note?.owner.startsWith("theme:")) {
				const running = data.value.themes.find((t) => `theme:${t.slug}` === note.owner)?.version ?? null;
				if (switchBackOutcome(note, running, null) === "done") notify.success(__("{theme} {v} is back, with the database from before the update.", {
					theme: note.name,
					v: note.version
				}));
				else notify.error(__("Switching {theme} back to {v} did not finish. Site → Updates and the audit log say why.", {
					theme: note.name,
					v: note.version
				}));
			}
		}
		load();
		async function switchBack(theme) {
			const option = theme.switch_back;
			if (!await confirm({
				title: __("Switch {theme} back to {v}?", {
					theme: theme.name,
					v: option.to
				}),
				message: __("{theme} {v} is still on the server. Switching back also puts the database back as it was before the update, on {date}: anything changed on the site since then is lost from the site. A backup of the site as it is now is made first, under Site → Backups. Visitors see a short maintenance page.", {
					theme: theme.name,
					v: option.to,
					date: formatDateTime(option.backup_at || option.updated_at)
				}),
				confirmLabel: __("Switch back to {v}", { v: option.to }),
				danger: true
			})) return;
			busy.value = `switch-back:${theme.slug}`;
			try {
				await api.post("/plugins/switch-back", { owner: `theme:${theme.slug}` });
				rememberSwitchBack(`theme:${theme.slug}`, theme.name, option.to);
				window.location.reload();
			} catch (e) {
				notify.error(e);
				busy.value = "";
			}
		}
		const names = computed(() => Object.fromEntries((data.value?.themes ?? []).map((t) => [t.slug, t.name])));
		const pendingSwitch = computed(() => data.value && data.value.running && data.value.active !== data.value.running);
		async function activate(theme) {
			busy.value = `activate:${theme.slug}`;
			try {
				data.value = (await api.post(`/themes/${theme.slug}/activate`)).data;
				notify.success(__("The site now uses {theme}.", { theme: theme.name }));
			} catch (e) {
				notify.error(e);
			} finally {
				busy.value = "";
			}
		}
		async function remove(theme) {
			if (!await confirm({
				title: __("Delete {theme}?", { theme: theme.name }),
				message: __("Its files and this site's changes to it are deleted. Export it first to keep a copy."),
				confirmLabel: __("Delete theme"),
				danger: true
			})) return;
			busy.value = `delete:${theme.slug}`;
			try {
				data.value = (await api.delete(`/themes/${theme.slug}`)).data;
				notify.success(__("{theme} was deleted.", { theme: theme.name }));
			} catch (e) {
				notify.error(e);
			} finally {
				busy.value = "";
			}
		}
		async function exportTheme(theme) {
			busy.value = `export:${theme.slug}`;
			try {
				const response = await fetch(`/api/v1/themes/${theme.slug}/export`, {
					credentials: "same-origin",
					headers: { "X-Requested-With": "XMLHttpRequest" }
				});
				if (!response.ok) {
					const problem = await response.json().catch(() => ({ title: response.statusText }));
					throw new ApiError(response.status, problem);
				}
				const href = URL.createObjectURL(await response.blob());
				Object.assign(document.createElement("a"), {
					href,
					download: `${theme.slug}-${(/* @__PURE__ */ new Date()).toISOString().slice(0, 10)}.zip`
				}).click();
				setTimeout(() => URL.revokeObjectURL(href), 1e3);
			} catch (e) {
				notify.error(e);
			} finally {
				busy.value = "";
			}
		}
		const creating = ref(false);
		const form = reactive({
			name: "",
			slug: "",
			description: "",
			parent: "",
			variation: "",
			from_site: true,
			activate: false
		});
		const formErrors = ref({});
		const saving = ref(false);
		const slugify = (s) => s.toLowerCase().normalize("NFKD").replace(/[̀-ͯ]/g, "").replace(/[^a-z0-9]+/g, "-").replace(/^-+|-+$/g, "").slice(0, 64);
		let slugTouched = false;
		watch(() => form.name, (n) => {
			if (!slugTouched) form.slug = slugify(n);
		});
		const parentOptions = computed(() => (data.value?.themes ?? []).map((t) => ({
			value: t.slug,
			label: t.name + (t.slug === data.value.running ? ` (${__("running")})` : "")
		})));
		const fromRunning = computed(() => form.parent === data.value?.running);
		const variationOptions = computed(() => [{
			value: "",
			label: __("As designed")
		}, ...(data.value?.variations ?? []).map((v) => ({
			value: v.name,
			label: v.label
		}))]);
		function openNew() {
			Object.assign(form, {
				name: "",
				slug: "",
				description: "",
				parent: data.value.running ?? data.value.themes[0]?.slug ?? "",
				variation: "",
				from_site: data.value.site_changes > 0,
				activate: false
			});
			slugTouched = false;
			formErrors.value = {};
			creating.value = true;
		}
		async function create() {
			saving.value = true;
			formErrors.value = {};
			const body = {
				name: form.name,
				slug: form.slug,
				description: form.description,
				parent: form.parent,
				activate: form.activate
			};
			if (fromRunning.value) Object.assign(body, {
				from_site: form.from_site,
				variation: form.variation || null
			});
			try {
				const result = (await api.post("/themes", body)).data;
				data.value = result;
				creating.value = false;
				notify.success(form.activate ? __("{theme} was created and the site now uses it.", { theme: form.name }) : __("{theme} was created.", { theme: form.name }));
			} catch (e) {
				if (e instanceof ApiError && e.status === 422) formErrors.value = e.fieldErrors();
				else notify.error(e);
			} finally {
				saving.value = false;
			}
		}
		const importing = ref(false);
		const importFile = ref(null);
		const importReplace = ref(false);
		const importErrors = ref({});
		const uploading = ref(false);
		function openImport() {
			importFile.value = null;
			importReplace.value = false;
			importErrors.value = {};
			importing.value = true;
		}
		async function upload() {
			if (!importFile.value) return;
			uploading.value = true;
			importErrors.value = {};
			const body = new FormData();
			body.append("file", importFile.value);
			if (importReplace.value) body.append("replace", "1");
			try {
				const result = (await api.upload("/themes/import", body)).data;
				data.value = result;
				importing.value = false;
				notify.success(result.replaced ? __("{theme} was updated.", { theme: names.value[result.imported] ?? result.imported }) : __("{theme} was installed. Switch to it when you are ready.", { theme: names.value[result.imported] ?? result.imported }));
			} catch (e) {
				if (e instanceof ApiError && e.status === 422) importErrors.value = e.fieldErrors();
				else notify.error(e);
			} finally {
				uploading.value = false;
			}
		}
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<!--[-->`);
			_push(ssrRenderComponent(unref(KPageHeader), {
				title: unref(__)("Themes"),
				description: unref(__)("A theme sets how the whole site looks. Make your own from the current look, or bring one from another Kestello site.")
			}, createSlots({ _: 2 }, [data.value ? {
				name: "actions",
				fn: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(ssrRenderComponent(unref(KButton), {
							icon: "upload",
							onClick: openImport
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
							onClick: openNew
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("New theme"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("New theme")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
					} else return [createVNode(unref(KButton), {
						icon: "upload",
						onClick: openImport
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Import")), 1)]),
						_: 1
					}), createVNode(unref(KButton), {
						variant: "primary",
						icon: "plus",
						onClick: openNew
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("New theme")), 1)]),
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
			if (pendingSwitch.value) _push(ssrRenderComponent(unref(KAlert), { tone: "info" }, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(`${ssrInterpolate(unref(__)("The site switches to {theme} with the next page load.", { theme: names.value[data.value.active] ?? data.value.active }))}`);
					else return [createTextVNode(toDisplayString(unref(__)("The site switches to {theme} with the next page load.", { theme: names.value[data.value.active] ?? data.value.active })), 1)];
				}),
				_: 1
			}, _parent));
			else _push(`<!---->`);
			if (data.value) {
				_push(`<ul class="k-themes" data-v-133c2b5e><!--[-->`);
				ssrRenderList(data.value.themes, (t) => {
					_push(`<li class="${ssrRenderClass([{ "is-active": t.slug === data.value.active }, "k-theme"])}" data-v-133c2b5e><div class="k-theme__shot" data-v-133c2b5e>`);
					if (t.screenshot) _push(`<img${ssrRenderAttr("src", `/system/themes/${t.slug}/screenshot`)}${ssrRenderAttr("alt", "")} loading="lazy" data-v-133c2b5e>`);
					else {
						_push(`<span class="k-theme__noshot" aria-hidden="true" data-v-133c2b5e>`);
						_push(ssrRenderComponent(unref(KIcon), { name: "columns" }, null, _parent));
						_push(`</span>`);
					}
					_push(`</div><div class="k-theme__body" data-v-133c2b5e><div class="k-theme__title" data-v-133c2b5e><h2 data-v-133c2b5e>${ssrInterpolate(t.name)}</h2>`);
					if (t.slug === data.value.active) _push(ssrRenderComponent(unref(KBadge), { tone: "success" }, {
						default: withCtx((_, _push, _parent, _scopeId) => {
							if (_push) _push(`${ssrInterpolate(unref(__)("Active"))}`);
							else return [createTextVNode(toDisplayString(unref(__)("Active")), 1)];
						}),
						_: 2
					}, _parent));
					else _push(`<!---->`);
					_push(`</div><p class="k-muted k-small k-theme__meta" data-v-133c2b5e><span class="k-num" data-v-133c2b5e>${ssrInterpolate(t.version)}</span>`);
					if (t.parent) _push(`<!--[--> · ${ssrInterpolate(unref(__)("Builds on {theme}", { theme: names.value[t.parent] ?? t.parent }))}<!--]-->`);
					else _push(`<!---->`);
					if (t.author) _push(`<!--[--> · ${ssrInterpolate(t.author)}<!--]-->`);
					else _push(`<!---->`);
					_push(`</p>`);
					if (t.description) _push(`<p class="k-theme__desc" data-v-133c2b5e>${ssrInterpolate(t.description)}</p>`);
					else _push(`<!---->`);
					_push(`<p class="k-muted k-small k-theme__kind" data-v-133c2b5e>`);
					_push(ssrRenderComponent(unref(KIcon), { name: t.data_only ? "sliders" : "code" }, null, _parent));
					_push(` ${ssrInterpolate(t.data_only ? unref(__)("Made in the admin, no code") : unref(__)("Developer theme with code"))}</p>`);
					if (t.switch_back) _push(`<p class="k-muted k-small k-theme__back" data-v-133c2b5e>${ssrInterpolate(unref(__)("Updated from {v} on {date}.", {
						v: t.switch_back.to,
						date: unref(formatDateTime)(t.switch_back.updated_at)
					}))}</p>`);
					else _push(`<!---->`);
					_push(`</div><div class="k-theme__actions" data-v-133c2b5e>`);
					if (t.slug !== data.value.active) _push(ssrRenderComponent(unref(KButton), {
						size: "sm",
						variant: "primary",
						loading: busy.value === `activate:${t.slug}`,
						onClick: ($event) => activate(t)
					}, {
						default: withCtx((_, _push, _parent, _scopeId) => {
							if (_push) _push(`${ssrInterpolate(unref(__)("Use this theme"))}`);
							else return [createTextVNode(toDisplayString(unref(__)("Use this theme")), 1)];
						}),
						_: 2
					}, _parent));
					else _push(ssrRenderComponent(unref(KButton), {
						size: "sm",
						href: "/admin/appearance/styles",
						icon: "palette"
					}, {
						default: withCtx((_, _push, _parent, _scopeId) => {
							if (_push) _push(`${ssrInterpolate(unref(__)("Change its styles"))}`);
							else return [createTextVNode(toDisplayString(unref(__)("Change its styles")), 1)];
						}),
						_: 2
					}, _parent));
					if (t.data_only) _push(ssrRenderComponent(unref(KButton), {
						size: "sm",
						variant: "ghost",
						icon: "download",
						loading: busy.value === `export:${t.slug}`,
						onClick: ($event) => exportTheme(t)
					}, {
						default: withCtx((_, _push, _parent, _scopeId) => {
							if (_push) _push(`${ssrInterpolate(unref(__)("Export"))}`);
							else return [createTextVNode(toDisplayString(unref(__)("Export")), 1)];
						}),
						_: 2
					}, _parent));
					else _push(`<!---->`);
					if (t.switch_back && unref(can)("plugins.manage")) _push(ssrRenderComponent(unref(KButton), {
						size: "sm",
						variant: "ghost",
						icon: "clock-counter-clockwise",
						loading: busy.value === `switch-back:${t.slug}`,
						disabled: !!busy.value,
						onClick: ($event) => switchBack(t)
					}, {
						default: withCtx((_, _push, _parent, _scopeId) => {
							if (_push) _push(`${ssrInterpolate(unref(__)("Switch back to {v}", { v: t.switch_back.to }))}`);
							else return [createTextVNode(toDisplayString(unref(__)("Switch back to {v}", { v: t.switch_back.to })), 1)];
						}),
						_: 2
					}, _parent));
					else _push(`<!---->`);
					if (t.data_only && t.slug !== data.value.active && t.slug !== data.value.running) _push(ssrRenderComponent(unref(KButton), {
						size: "sm",
						variant: "ghost",
						icon: "trash",
						loading: busy.value === `delete:${t.slug}`,
						"aria-label": unref(__)("Delete {theme}", { theme: t.name }),
						onClick: ($event) => remove(t)
					}, null, _parent));
					else _push(`<!---->`);
					_push(`</div></li>`);
				});
				_push(`<!--]--></ul>`);
			} else _push(`<!---->`);
			_push(ssrRenderComponent(unref(KModal), {
				open: creating.value,
				"onUpdate:open": ($event) => creating.value = $event,
				title: unref(__)("New theme")
			}, {
				footer: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(ssrRenderComponent(unref(KButton), { onClick: ($event) => creating.value = false }, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Cancel"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Cancel")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						_push(ssrRenderComponent(unref(KButton), {
							type: "submit",
							form: "k-theme-new",
							variant: "primary",
							icon: "check",
							loading: saving.value
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Create theme"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Create theme")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
					} else return [createVNode(unref(KButton), { onClick: ($event) => creating.value = false }, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Cancel")), 1)]),
						_: 1
					}, 8, ["onClick"]), createVNode(unref(KButton), {
						type: "submit",
						form: "k-theme-new",
						variant: "primary",
						icon: "check",
						loading: saving.value
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Create theme")), 1)]),
						_: 1
					}, 8, ["loading"])];
				}),
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(`<form id="k-theme-new" class="k-form" data-v-133c2b5e${_scopeId}>`);
						_push(ssrRenderComponent(unref(KField), {
							label: unref(__)("Name"),
							error: formErrors.value.name ?? ""
						}, {
							default: withCtx(({ id }, _push, _parent, _scopeId) => {
								if (_push) _push(ssrRenderComponent(unref(KInput), {
									id,
									modelValue: form.name,
									"onUpdate:modelValue": ($event) => form.name = $event,
									required: "",
									maxlength: "60"
								}, null, _parent, _scopeId));
								else return [createVNode(unref(KInput), {
									id,
									modelValue: form.name,
									"onUpdate:modelValue": ($event) => form.name = $event,
									required: "",
									maxlength: "60"
								}, null, 8, [
									"id",
									"modelValue",
									"onUpdate:modelValue"
								])];
							}),
							_: 1
						}, _parent, _scopeId));
						_push(ssrRenderComponent(unref(KField), {
							label: unref(__)("Folder name"),
							help: unref(__)("Lowercase letters, digits and hyphens. Other sites see the theme under this name."),
							error: formErrors.value.slug ?? ""
						}, {
							default: withCtx(({ id }, _push, _parent, _scopeId) => {
								if (_push) _push(ssrRenderComponent(unref(KInput), {
									id,
									modelValue: form.slug,
									"onUpdate:modelValue": ($event) => form.slug = $event,
									required: "",
									maxlength: "64",
									pattern: "[a-z0-9]+(-[a-z0-9]+)*",
									class: "k-mono",
									onInput: ($event) => isRef(slugTouched) ? slugTouched.value = true : slugTouched = true
								}, null, _parent, _scopeId));
								else return [createVNode(unref(KInput), {
									id,
									modelValue: form.slug,
									"onUpdate:modelValue": ($event) => form.slug = $event,
									required: "",
									maxlength: "64",
									pattern: "[a-z0-9]+(-[a-z0-9]+)*",
									class: "k-mono",
									onInput: ($event) => isRef(slugTouched) ? slugTouched.value = true : slugTouched = true
								}, null, 8, [
									"id",
									"modelValue",
									"onUpdate:modelValue",
									"onInput"
								])];
							}),
							_: 1
						}, _parent, _scopeId));
						_push(ssrRenderComponent(unref(KField), {
							label: unref(__)("Start from"),
							help: unref(__)("The new theme builds on this one: its pages, blocks and styles, with your changes on top."),
							error: formErrors.value.parent ?? ""
						}, {
							default: withCtx(({ id }, _push, _parent, _scopeId) => {
								if (_push) _push(ssrRenderComponent(unref(KSelect), {
									id,
									modelValue: form.parent,
									"onUpdate:modelValue": ($event) => form.parent = $event,
									options: parentOptions.value
								}, null, _parent, _scopeId));
								else return [createVNode(unref(KSelect), {
									id,
									modelValue: form.parent,
									"onUpdate:modelValue": ($event) => form.parent = $event,
									options: parentOptions.value
								}, null, 8, [
									"id",
									"modelValue",
									"onUpdate:modelValue",
									"options"
								])];
							}),
							_: 1
						}, _parent, _scopeId));
						if (fromRunning.value) {
							_push(`<!--[-->`);
							if (data.value.variations.length) _push(ssrRenderComponent(unref(KField), {
								label: unref(__)("Starting look"),
								error: formErrors.value.variation ?? ""
							}, {
								default: withCtx(({ id }, _push, _parent, _scopeId) => {
									if (_push) _push(ssrRenderComponent(unref(KSelect), {
										id,
										modelValue: form.variation,
										"onUpdate:modelValue": ($event) => form.variation = $event,
										options: variationOptions.value
									}, null, _parent, _scopeId));
									else return [createVNode(unref(KSelect), {
										id,
										modelValue: form.variation,
										"onUpdate:modelValue": ($event) => form.variation = $event,
										options: variationOptions.value
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
							_push(ssrRenderComponent(unref(KSwitch), {
								modelValue: form.from_site,
								"onUpdate:modelValue": ($event) => form.from_site = $event,
								label: unref(__)("Include this site's changes"),
								disabled: !data.value.site_changes
							}, null, _parent, _scopeId));
							_push(`<p class="k-muted k-small k-theme-note" data-v-133c2b5e${_scopeId}>${ssrInterpolate(data.value.site_changes ? unref(__)("Styles, templates, parts and patterns changed on this site become part of the theme ({n} changed).", { n: data.value.site_changes }) : unref(__)("Nothing has been changed on this site yet."))}</p><!--]-->`);
						} else _push(`<!---->`);
						_push(ssrRenderComponent(unref(KField), {
							label: unref(__)("Description"),
							error: formErrors.value.description ?? ""
						}, {
							default: withCtx(({ id }, _push, _parent, _scopeId) => {
								if (_push) _push(ssrRenderComponent(unref(KInput), {
									id,
									modelValue: form.description,
									"onUpdate:modelValue": ($event) => form.description = $event,
									maxlength: "300"
								}, null, _parent, _scopeId));
								else return [createVNode(unref(KInput), {
									id,
									modelValue: form.description,
									"onUpdate:modelValue": ($event) => form.description = $event,
									maxlength: "300"
								}, null, 8, [
									"id",
									"modelValue",
									"onUpdate:modelValue"
								])];
							}),
							_: 1
						}, _parent, _scopeId));
						_push(ssrRenderComponent(unref(KSwitch), {
							modelValue: form.activate,
							"onUpdate:modelValue": ($event) => form.activate = $event,
							label: unref(__)("Switch the site to it now")
						}, null, _parent, _scopeId));
						_push(`</form>`);
					} else return [createVNode("form", {
						id: "k-theme-new",
						class: "k-form",
						onSubmit: withModifiers(create, ["prevent"])
					}, [
						createVNode(unref(KField), {
							label: unref(__)("Name"),
							error: formErrors.value.name ?? ""
						}, {
							default: withCtx(({ id }) => [createVNode(unref(KInput), {
								id,
								modelValue: form.name,
								"onUpdate:modelValue": ($event) => form.name = $event,
								required: "",
								maxlength: "60"
							}, null, 8, [
								"id",
								"modelValue",
								"onUpdate:modelValue"
							])]),
							_: 1
						}, 8, ["label", "error"]),
						createVNode(unref(KField), {
							label: unref(__)("Folder name"),
							help: unref(__)("Lowercase letters, digits and hyphens. Other sites see the theme under this name."),
							error: formErrors.value.slug ?? ""
						}, {
							default: withCtx(({ id }) => [createVNode(unref(KInput), {
								id,
								modelValue: form.slug,
								"onUpdate:modelValue": ($event) => form.slug = $event,
								required: "",
								maxlength: "64",
								pattern: "[a-z0-9]+(-[a-z0-9]+)*",
								class: "k-mono",
								onInput: ($event) => isRef(slugTouched) ? slugTouched.value = true : slugTouched = true
							}, null, 8, [
								"id",
								"modelValue",
								"onUpdate:modelValue",
								"onInput"
							])]),
							_: 1
						}, 8, [
							"label",
							"help",
							"error"
						]),
						createVNode(unref(KField), {
							label: unref(__)("Start from"),
							help: unref(__)("The new theme builds on this one: its pages, blocks and styles, with your changes on top."),
							error: formErrors.value.parent ?? ""
						}, {
							default: withCtx(({ id }) => [createVNode(unref(KSelect), {
								id,
								modelValue: form.parent,
								"onUpdate:modelValue": ($event) => form.parent = $event,
								options: parentOptions.value
							}, null, 8, [
								"id",
								"modelValue",
								"onUpdate:modelValue",
								"options"
							])]),
							_: 1
						}, 8, [
							"label",
							"help",
							"error"
						]),
						fromRunning.value ? (openBlock(), createBlock(Fragment, { key: 0 }, [
							data.value.variations.length ? (openBlock(), createBlock(unref(KField), {
								key: 0,
								label: unref(__)("Starting look"),
								error: formErrors.value.variation ?? ""
							}, {
								default: withCtx(({ id }) => [createVNode(unref(KSelect), {
									id,
									modelValue: form.variation,
									"onUpdate:modelValue": ($event) => form.variation = $event,
									options: variationOptions.value
								}, null, 8, [
									"id",
									"modelValue",
									"onUpdate:modelValue",
									"options"
								])]),
								_: 1
							}, 8, ["label", "error"])) : createCommentVNode("v-if", true),
							createVNode(unref(KSwitch), {
								modelValue: form.from_site,
								"onUpdate:modelValue": ($event) => form.from_site = $event,
								label: unref(__)("Include this site's changes"),
								disabled: !data.value.site_changes
							}, null, 8, [
								"modelValue",
								"onUpdate:modelValue",
								"label",
								"disabled"
							]),
							createVNode("p", { class: "k-muted k-small k-theme-note" }, toDisplayString(data.value.site_changes ? unref(__)("Styles, templates, parts and patterns changed on this site become part of the theme ({n} changed).", { n: data.value.site_changes }) : unref(__)("Nothing has been changed on this site yet.")), 1)
						], 64)) : createCommentVNode("v-if", true),
						createVNode(unref(KField), {
							label: unref(__)("Description"),
							error: formErrors.value.description ?? ""
						}, {
							default: withCtx(({ id }) => [createVNode(unref(KInput), {
								id,
								modelValue: form.description,
								"onUpdate:modelValue": ($event) => form.description = $event,
								maxlength: "300"
							}, null, 8, [
								"id",
								"modelValue",
								"onUpdate:modelValue"
							])]),
							_: 1
						}, 8, ["label", "error"]),
						createVNode(unref(KSwitch), {
							modelValue: form.activate,
							"onUpdate:modelValue": ($event) => form.activate = $event,
							label: unref(__)("Switch the site to it now")
						}, null, 8, [
							"modelValue",
							"onUpdate:modelValue",
							"label"
						])
					], 32)];
				}),
				_: 1
			}, _parent));
			_push(ssrRenderComponent(unref(KModal), {
				open: importing.value,
				"onUpdate:open": ($event) => importing.value = $event,
				title: unref(__)("Import a theme")
			}, {
				footer: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(ssrRenderComponent(unref(KButton), { onClick: ($event) => importing.value = false }, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Cancel"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Cancel")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						_push(ssrRenderComponent(unref(KButton), {
							type: "submit",
							form: "k-theme-import",
							variant: "primary",
							icon: "upload",
							loading: uploading.value,
							disabled: !importFile.value
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Import"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Import")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
					} else return [createVNode(unref(KButton), { onClick: ($event) => importing.value = false }, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Cancel")), 1)]),
						_: 1
					}, 8, ["onClick"]), createVNode(unref(KButton), {
						type: "submit",
						form: "k-theme-import",
						variant: "primary",
						icon: "upload",
						loading: uploading.value,
						disabled: !importFile.value
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Import")), 1)]),
						_: 1
					}, 8, ["loading", "disabled"])];
				}),
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(`<form id="k-theme-import" class="k-form" data-v-133c2b5e${_scopeId}><p class="k-muted k-small k-theme-note" data-v-133c2b5e${_scopeId}>${ssrInterpolate(unref(__)("Choose a zip exported from Appearance → Themes on any Kestello site. Only themes without code can be imported here; the theme it builds on must be installed."))}</p>`);
						_push(ssrRenderComponent(unref(KField), {
							label: unref(__)("Theme zip"),
							error: importErrors.value.file ?? ""
						}, {
							default: withCtx(({ id }, _push, _parent, _scopeId) => {
								if (_push) _push(`<input${ssrRenderAttr("id", id)} type="file" accept=".zip,application/zip" class="k-input" required data-v-133c2b5e${_scopeId}>`);
								else return [createVNode("input", {
									id,
									type: "file",
									accept: ".zip,application/zip",
									class: "k-input",
									required: "",
									onChange: ($event) => importFile.value = $event.target.files?.[0] ?? null
								}, null, 40, ["id", "onChange"])];
							}),
							_: 1
						}, _parent, _scopeId));
						_push(ssrRenderComponent(unref(KSwitch), {
							modelValue: importReplace.value,
							"onUpdate:modelValue": ($event) => importReplace.value = $event,
							label: unref(__)("Replace an installed theme with the same name")
						}, null, _parent, _scopeId));
						if (importErrors.value.replace) _push(ssrRenderComponent(unref(KAlert), { tone: "warning" }, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(importErrors.value.replace)}`);
								else return [createTextVNode(toDisplayString(importErrors.value.replace), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						else _push(`<!---->`);
						_push(`</form>`);
					} else return [createVNode("form", {
						id: "k-theme-import",
						class: "k-form",
						onSubmit: withModifiers(upload, ["prevent"])
					}, [
						createVNode("p", { class: "k-muted k-small k-theme-note" }, toDisplayString(unref(__)("Choose a zip exported from Appearance → Themes on any Kestello site. Only themes without code can be imported here; the theme it builds on must be installed.")), 1),
						createVNode(unref(KField), {
							label: unref(__)("Theme zip"),
							error: importErrors.value.file ?? ""
						}, {
							default: withCtx(({ id }) => [createVNode("input", {
								id,
								type: "file",
								accept: ".zip,application/zip",
								class: "k-input",
								required: "",
								onChange: ($event) => importFile.value = $event.target.files?.[0] ?? null
							}, null, 40, ["id", "onChange"])]),
							_: 1
						}, 8, ["label", "error"]),
						createVNode(unref(KSwitch), {
							modelValue: importReplace.value,
							"onUpdate:modelValue": ($event) => importReplace.value = $event,
							label: unref(__)("Replace an installed theme with the same name")
						}, null, 8, [
							"modelValue",
							"onUpdate:modelValue",
							"label"
						]),
						importErrors.value.replace ? (openBlock(), createBlock(unref(KAlert), {
							key: 0,
							tone: "warning"
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(importErrors.value.replace), 1)]),
							_: 1
						})) : createCommentVNode("v-if", true)
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
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/admin/appearance/Themes.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
var Themes_default = /*#__PURE__*/ _plugin_vue_export_helper_default(_sfc_main, [["__scopeId", "data-v-133c2b5e"]]);
export { Themes_default as default };
