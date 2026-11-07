import { t as _plugin_vue_export_helper_default } from "../../../chunks/_plugin-vue_export-helper-DjkACiAr.mjs";
import { Fragment, computed, createBlock, createCommentVNode, createSlots, createTextVNode, createVNode, openBlock, reactive, ref, toDisplayString, unref, useSSRContext, withCtx, withModifiers } from "vue";
import { ssrInterpolate, ssrRenderAttr, ssrRenderComponent, ssrRenderList } from "vue/server-renderer";
import { ApiError, __, confirm, formatBytes, notify, useApi } from "@kestello/sdk";
import { KAlert, KBadge, KButton, KCard, KField, KInput, KModal, KPageHeader, KSelect, KSwitch, KTable, KTime } from "@kestello/ui";
// core/resources/js/pages/admin/system/Backups.vue
var _sfc_main = /*@__PURE__*/ Object.assign({ layout: "Admin" }, {
	__name: "Backups",
	__ssrInlineRender: true,
	setup(__props) {
		/**
		* System → Backups: back up now, on a schedule, download, and restore here
		* or on another Kestello site (any database: rows travel as JSON, the
		* schema comes from migrations).
		*/
		const api = useApi();
		const data = ref(null);
		const loadError = ref(null);
		const busy = ref("");
		async function load() {
			try {
				data.value = (await api.get("/backups")).data;
				Object.assign(schedule, data.value.settings);
			} catch (e) {
				loadError.value = e;
			}
		}
		load();
		const kinds = {
			manual: __("Made by hand"),
			scheduled: __("Scheduled"),
			"before-restore": __("Before a restore"),
			"before-update": __("Before an update"),
			uploaded: __("From another site")
		};
		const latest = computed(() => data.value?.backups?.[0] ?? null);
		const schedule = reactive({
			schedule: "daily",
			files: false,
			keep: 7
		});
		const scheduleOptions = [
			{
				value: "daily",
				label: __("Every day")
			},
			{
				value: "weekly",
				label: __("Every week (Sundays)")
			},
			{
				value: "off",
				label: __("Off")
			}
		];
		const scheduleDirty = computed(() => data.value && (schedule.schedule !== data.value.settings.schedule || schedule.files !== data.value.settings.files || Number(schedule.keep) !== data.value.settings.keep));
		async function saveSchedule() {
			busy.value = "schedule";
			try {
				data.value = (await api.put("/backups/settings", {
					schedule: schedule.schedule,
					files: schedule.files,
					keep: Number(schedule.keep)
				})).data;
				notify.success(__("Backup schedule saved."));
			} catch (e) {
				notify.error(e);
			} finally {
				busy.value = "";
			}
		}
		const making = ref(false);
		const makeForm = reactive({
			files: true,
			note: ""
		});
		async function makeBackup() {
			busy.value = "create";
			try {
				data.value = (await api.post("/backups", {
					files: makeForm.files,
					note: makeForm.note
				})).data;
				making.value = false;
				notify.success(__("The backup is ready."));
			} catch (e) {
				notify.error(e);
			} finally {
				busy.value = "";
			}
		}
		async function download(b) {
			busy.value = `download:${b.name}`;
			try {
				await api.download(`/backups/${b.name}/download`, b.name);
			} catch (e) {
				notify.error(e);
			} finally {
				busy.value = "";
			}
		}
		async function remove(b) {
			if (!await confirm({
				title: __("Delete this backup?"),
				message: __("The backup from {date} is deleted for good.", { date: (/* @__PURE__ */ new Date(b.created_at * 1e3)).toLocaleString() }),
				confirmLabel: __("Delete backup"),
				danger: true
			})) return;
			busy.value = `delete:${b.name}`;
			try {
				data.value = (await api.delete(`/backups/${b.name}`)).data;
				notify.success(__("The backup was deleted."));
			} catch (e) {
				notify.error(e);
			} finally {
				busy.value = "";
			}
		}
		const uploading = ref(false);
		const uploadFile = ref(null);
		const uploadErrors = ref({});
		async function upload() {
			if (!uploadFile.value) return;
			busy.value = "upload";
			uploadErrors.value = {};
			const form = new FormData();
			form.append("file", uploadFile.value);
			try {
				data.value = (await api.upload("/backups/upload", form)).data;
				uploading.value = false;
				notify.success(__("The backup was added. Restore it when you are ready."));
			} catch (e) {
				if (e instanceof ApiError && e.status === 422) uploadErrors.value = e.fieldErrors();
				else notify.error(e);
			} finally {
				busy.value = "";
			}
		}
		const restoring = ref(null);
		const confirmName = ref("");
		const restoreErrors = ref({});
		const restored = ref(null);
		function openRestore(b) {
			restoring.value = b;
			confirmName.value = "";
			restoreErrors.value = {};
		}
		async function restore() {
			busy.value = "restore";
			restoreErrors.value = {};
			try {
				restored.value = (await api.post(`/backups/${restoring.value.name}/restore`, { confirm: confirmName.value })).data.report;
				restoring.value = null;
			} catch (e) {
				if (e instanceof ApiError && e.status === 422) restoreErrors.value = e.fieldErrors();
				else notify.error(e);
			} finally {
				busy.value = "";
			}
		}
		const rowsText = (n) => __("{n, plural, one {# row} other {# rows}}", { n });
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<!--[-->`);
			_push(ssrRenderComponent(unref(KPageHeader), {
				title: unref(__)("Backups"),
				description: unref(__)("A backup holds the whole site: content, settings, people and, when you choose, its files. Restore it here, or on another Kestello site to move there.")
			}, createSlots({ _: 2 }, [data.value && !restored.value ? {
				name: "actions",
				fn: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(ssrRenderComponent(unref(KButton), {
							icon: "upload",
							onClick: ($event) => {
								uploading.value = true;
								uploadFile.value = null;
								uploadErrors.value = {};
							}
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Upload a backup"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Upload a backup")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						_push(ssrRenderComponent(unref(KButton), {
							variant: "primary",
							icon: "archive",
							onClick: ($event) => {
								making.value = true;
								makeForm.files = true;
								makeForm.note = "";
							}
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Back up now"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Back up now")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
					} else return [createVNode(unref(KButton), {
						icon: "upload",
						onClick: ($event) => {
							uploading.value = true;
							uploadFile.value = null;
							uploadErrors.value = {};
						}
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Upload a backup")), 1)]),
						_: 1
					}, 8, ["onClick"]), createVNode(unref(KButton), {
						variant: "primary",
						icon: "archive",
						onClick: ($event) => {
							making.value = true;
							makeForm.files = true;
							makeForm.note = "";
						}
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Back up now")), 1)]),
						_: 1
					}, 8, ["onClick"])];
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
			if (restored.value) {
				_push(`<!--[-->`);
				_push(ssrRenderComponent(unref(KAlert), {
					tone: "success",
					title: unref(__)("The site was restored")
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`${ssrInterpolate(unref(__)("{rows} rows in {tables} tables came back. Everyone was signed out, you too: sign in with an account from the backup.", {
							rows: restored.value.rows,
							tables: restored.value.tables
						}))}`);
						else return [createTextVNode(toDisplayString(unref(__)("{rows} rows in {tables} tables came back. Everyone was signed out, you too: sign in with an account from the backup.", {
							rows: restored.value.rows,
							tables: restored.value.tables
						})), 1)];
					}),
					_: 1
				}, _parent));
				if (restored.value.skipped_code) _push(ssrRenderComponent(unref(KAlert), { tone: "warning" }, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`${ssrInterpolate(unref(__)("Plugin and theme folders were not restored: the backup was made on another site, and code from elsewhere is only restored from the command line (bin/cms backup:restore --with-code)."))}`);
						else return [createTextVNode(toDisplayString(unref(__)("Plugin and theme folders were not restored: the backup was made on another site, and code from elsewhere is only restored from the command line (bin/cms backup:restore --with-code).")), 1)];
					}),
					_: 1
				}, _parent));
				else _push(`<!---->`);
				if (restored.value.missing_tables.length) _push(ssrRenderComponent(unref(KAlert), { tone: "warning" }, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`${ssrInterpolate(unref(__)("Some rows were left out because their plugin is not installed here: {tables}.", { tables: restored.value.missing_tables.join(", ") }))}`);
						else return [createTextVNode(toDisplayString(unref(__)("Some rows were left out because their plugin is not installed here: {tables}.", { tables: restored.value.missing_tables.join(", ") })), 1)];
					}),
					_: 1
				}, _parent));
				else _push(`<!---->`);
				_push(`<!--[-->`);
				ssrRenderList(restored.value.warnings, (w) => {
					_push(ssrRenderComponent(unref(KAlert), {
						key: w,
						tone: "warning"
					}, {
						default: withCtx((_, _push, _parent, _scopeId) => {
							if (_push) _push(`${ssrInterpolate(w)}`);
							else return [createTextVNode(toDisplayString(w), 1)];
						}),
						_: 2
					}, _parent));
				});
				_push(`<!--]--><div class="k-backups__after" data-v-1474de09>`);
				_push(ssrRenderComponent(unref(KButton), {
					variant: "primary",
					href: "/login",
					icon: "sign-in"
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`${ssrInterpolate(unref(__)("Sign in again"))}`);
						else return [createTextVNode(toDisplayString(unref(__)("Sign in again")), 1)];
					}),
					_: 1
				}, _parent));
				_push(`</div><!--]-->`);
			} else if (data.value) {
				_push(`<!--[-->`);
				_push(ssrRenderComponent(unref(KCard), {
					title: unref(__)("Schedule"),
					class: "k-backups__schedule"
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) {
							_push(`<form class="k-backups__form" data-v-1474de09${_scopeId}>`);
							_push(ssrRenderComponent(unref(KField), { label: unref(__)("Back up automatically") }, {
								default: withCtx(({ id }, _push, _parent, _scopeId) => {
									if (_push) _push(ssrRenderComponent(unref(KSelect), {
										id,
										modelValue: schedule.schedule,
										"onUpdate:modelValue": ($event) => schedule.schedule = $event,
										options: scheduleOptions
									}, null, _parent, _scopeId));
									else return [createVNode(unref(KSelect), {
										id,
										modelValue: schedule.schedule,
										"onUpdate:modelValue": ($event) => schedule.schedule = $event,
										options: scheduleOptions
									}, null, 8, [
										"id",
										"modelValue",
										"onUpdate:modelValue"
									])];
								}),
								_: 1
							}, _parent, _scopeId));
							_push(ssrRenderComponent(unref(KField), { label: unref(__)("Keep") }, {
								default: withCtx(({ id }, _push, _parent, _scopeId) => {
									if (_push) _push(ssrRenderComponent(unref(KInput), {
										id,
										modelValue: schedule.keep,
										"onUpdate:modelValue": ($event) => schedule.keep = $event,
										type: "number",
										min: "1",
										max: "100",
										class: "k-backups__keep"
									}, null, _parent, _scopeId));
									else return [createVNode(unref(KInput), {
										id,
										modelValue: schedule.keep,
										"onUpdate:modelValue": ($event) => schedule.keep = $event,
										type: "number",
										min: "1",
										max: "100",
										class: "k-backups__keep"
									}, null, 8, [
										"id",
										"modelValue",
										"onUpdate:modelValue"
									])];
								}),
								_: 1
							}, _parent, _scopeId));
							_push(ssrRenderComponent(unref(KSwitch), {
								modelValue: schedule.files,
								"onUpdate:modelValue": ($event) => schedule.files = $event,
								label: unref(__)("Include files (uploads, plugins, themes)"),
								disabled: schedule.schedule === "off"
							}, null, _parent, _scopeId));
							_push(ssrRenderComponent(unref(KButton), {
								type: "submit",
								variant: "primary",
								disabled: !scheduleDirty.value,
								loading: busy.value === "schedule"
							}, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate(unref(__)("Save"))}`);
									else return [createTextVNode(toDisplayString(unref(__)("Save")), 1)];
								}),
								_: 1
							}, _parent, _scopeId));
							_push(`</form><p class="k-muted k-small k-backups__where" data-v-1474de09${_scopeId}>${ssrInterpolate(unref(__)("Keeps that many automatic backups; the oldest go first. Backups made by hand stay until you delete them."))} ${ssrInterpolate(data.value.disk.driver === "local" ? unref(__)("Backups are kept in storage/app/backups on this server. To keep copies elsewhere, point the backups disk at S3-compatible storage in config/storage.php.") : unref(__)("Backups are kept on the backups disk ({driver}).", { driver: data.value.disk.driver }))} `);
							if (latest.value) {
								_push(`<!--[-->${ssrInterpolate(unref(__)("Newest:"))} `);
								_push(ssrRenderComponent(unref(KTime), { value: latest.value.created_at }, null, _parent, _scopeId));
								_push(`.<!--]-->`);
							} else _push(`<!---->`);
							_push(`</p>`);
						} else return [createVNode("form", {
							class: "k-backups__form",
							onSubmit: withModifiers(saveSchedule, ["prevent"])
						}, [
							createVNode(unref(KField), { label: unref(__)("Back up automatically") }, {
								default: withCtx(({ id }) => [createVNode(unref(KSelect), {
									id,
									modelValue: schedule.schedule,
									"onUpdate:modelValue": ($event) => schedule.schedule = $event,
									options: scheduleOptions
								}, null, 8, [
									"id",
									"modelValue",
									"onUpdate:modelValue"
								])]),
								_: 1
							}, 8, ["label"]),
							createVNode(unref(KField), { label: unref(__)("Keep") }, {
								default: withCtx(({ id }) => [createVNode(unref(KInput), {
									id,
									modelValue: schedule.keep,
									"onUpdate:modelValue": ($event) => schedule.keep = $event,
									type: "number",
									min: "1",
									max: "100",
									class: "k-backups__keep"
								}, null, 8, [
									"id",
									"modelValue",
									"onUpdate:modelValue"
								])]),
								_: 1
							}, 8, ["label"]),
							createVNode(unref(KSwitch), {
								modelValue: schedule.files,
								"onUpdate:modelValue": ($event) => schedule.files = $event,
								label: unref(__)("Include files (uploads, plugins, themes)"),
								disabled: schedule.schedule === "off"
							}, null, 8, [
								"modelValue",
								"onUpdate:modelValue",
								"label",
								"disabled"
							]),
							createVNode(unref(KButton), {
								type: "submit",
								variant: "primary",
								disabled: !scheduleDirty.value,
								loading: busy.value === "schedule"
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Save")), 1)]),
								_: 1
							}, 8, ["disabled", "loading"])
						], 32), createVNode("p", { class: "k-muted k-small k-backups__where" }, [createTextVNode(toDisplayString(unref(__)("Keeps that many automatic backups; the oldest go first. Backups made by hand stay until you delete them.")) + " " + toDisplayString(data.value.disk.driver === "local" ? unref(__)("Backups are kept in storage/app/backups on this server. To keep copies elsewhere, point the backups disk at S3-compatible storage in config/storage.php.") : unref(__)("Backups are kept on the backups disk ({driver}).", { driver: data.value.disk.driver })) + " ", 1), latest.value ? (openBlock(), createBlock(Fragment, { key: 0 }, [
							createTextVNode(toDisplayString(unref(__)("Newest:")) + " ", 1),
							createVNode(unref(KTime), { value: latest.value.created_at }, null, 8, ["value"]),
							createTextVNode(".")
						], 64)) : createCommentVNode("v-if", true)])];
					}),
					_: 1
				}, _parent));
				_push(ssrRenderComponent(unref(KCard), { flush: "" }, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(ssrRenderComponent(unref(KTable), {
							columns: [
								{
									key: "made",
									label: unref(__)("Made")
								},
								{
									key: "contents",
									label: unref(__)("Contents")
								},
								{
									key: "size",
									label: unref(__)("Size"),
									align: "end"
								},
								{
									key: "actions",
									label: "",
									align: "end",
									width: "1%"
								}
							],
							rows: data.value.backups,
							"row-key": "name",
							empty: unref(__)("No backups yet"),
							"empty-text": unref(__)("Make the first one with Back up now.")
						}, {
							"cell-made": withCtx(({ row }, _push, _parent, _scopeId) => {
								if (_push) {
									_push(ssrRenderComponent(unref(KTime), { value: row.created_at }, null, _parent, _scopeId));
									_push(`<div class="k-small k-backups__kind" data-v-1474de09${_scopeId}>`);
									_push(ssrRenderComponent(unref(KBadge), { tone: row.kind === "uploaded" ? "accent" : "neutral" }, {
										default: withCtx((_, _push, _parent, _scopeId) => {
											if (_push) _push(`${ssrInterpolate(kinds[row.kind] ?? row.kind)}`);
											else return [createTextVNode(toDisplayString(kinds[row.kind] ?? row.kind), 1)];
										}),
										_: 2
									}, _parent, _scopeId));
									if (row.note) _push(`<span class="k-muted" data-v-1474de09${_scopeId}>${ssrInterpolate(row.note)}</span>`);
									else _push(`<!---->`);
									_push(`</div>`);
								} else return [createVNode(unref(KTime), { value: row.created_at }, null, 8, ["value"]), createVNode("div", { class: "k-small k-backups__kind" }, [createVNode(unref(KBadge), { tone: row.kind === "uploaded" ? "accent" : "neutral" }, {
									default: withCtx(() => [createTextVNode(toDisplayString(kinds[row.kind] ?? row.kind), 1)]),
									_: 2
								}, 1032, ["tone"]), row.note ? (openBlock(), createBlock("span", {
									key: 0,
									class: "k-muted"
								}, toDisplayString(row.note), 1)) : createCommentVNode("v-if", true)])];
							}),
							"cell-contents": withCtx(({ row }, _push, _parent, _scopeId) => {
								if (_push) {
									_push(`${ssrInterpolate(row.files ? unref(__)("Database and files") : unref(__)("Database only"))} <div class="k-muted k-small" data-v-1474de09${_scopeId}><span class="k-num" data-v-1474de09${_scopeId}>${ssrInterpolate(rowsText(row.rows))}</span>`);
									if (row.driver) _push(`<!--[--> · ${ssrInterpolate(row.driver)}<!--]-->`);
									else _push(`<!---->`);
									if (row.core) _push(`<!--[--> · Kestello ${ssrInterpolate(row.core)}<!--]-->`);
									else _push(`<!---->`);
									_push(`</div>`);
								} else return [createTextVNode(toDisplayString(row.files ? unref(__)("Database and files") : unref(__)("Database only")) + " ", 1), createVNode("div", { class: "k-muted k-small" }, [
									createVNode("span", { class: "k-num" }, toDisplayString(rowsText(row.rows)), 1),
									row.driver ? (openBlock(), createBlock(Fragment, { key: 0 }, [createTextVNode(" · " + toDisplayString(row.driver), 1)], 64)) : createCommentVNode("v-if", true),
									row.core ? (openBlock(), createBlock(Fragment, { key: 1 }, [createTextVNode(" · Kestello " + toDisplayString(row.core), 1)], 64)) : createCommentVNode("v-if", true)
								])];
							}),
							"cell-size": withCtx(({ row }, _push, _parent, _scopeId) => {
								if (_push) _push(`<span class="k-num" data-v-1474de09${_scopeId}>${ssrInterpolate(unref(formatBytes)(row.size))}</span>`);
								else return [createVNode("span", { class: "k-num" }, toDisplayString(unref(formatBytes)(row.size)), 1)];
							}),
							"cell-actions": withCtx(({ row }, _push, _parent, _scopeId) => {
								if (_push) {
									_push(`<div class="k-backups__actions" data-v-1474de09${_scopeId}>`);
									_push(ssrRenderComponent(unref(KButton), {
										size: "sm",
										icon: "arrow-counter-clockwise",
										onClick: ($event) => openRestore(row)
									}, {
										default: withCtx((_, _push, _parent, _scopeId) => {
											if (_push) _push(`${ssrInterpolate(unref(__)("Restore"))}`);
											else return [createTextVNode(toDisplayString(unref(__)("Restore")), 1)];
										}),
										_: 2
									}, _parent, _scopeId));
									_push(ssrRenderComponent(unref(KButton), {
										size: "sm",
										variant: "ghost",
										icon: "download",
										loading: busy.value === `download:${row.name}`,
										"aria-label": unref(__)("Download"),
										onClick: ($event) => download(row)
									}, null, _parent, _scopeId));
									_push(ssrRenderComponent(unref(KButton), {
										size: "sm",
										variant: "ghost",
										icon: "trash",
										loading: busy.value === `delete:${row.name}`,
										"aria-label": unref(__)("Delete"),
										onClick: ($event) => remove(row)
									}, null, _parent, _scopeId));
									_push(`</div>`);
								} else return [createVNode("div", { class: "k-backups__actions" }, [
									createVNode(unref(KButton), {
										size: "sm",
										icon: "arrow-counter-clockwise",
										onClick: ($event) => openRestore(row)
									}, {
										default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Restore")), 1)]),
										_: 1
									}, 8, ["onClick"]),
									createVNode(unref(KButton), {
										size: "sm",
										variant: "ghost",
										icon: "download",
										loading: busy.value === `download:${row.name}`,
										"aria-label": unref(__)("Download"),
										onClick: ($event) => download(row)
									}, null, 8, [
										"loading",
										"aria-label",
										"onClick"
									]),
									createVNode(unref(KButton), {
										size: "sm",
										variant: "ghost",
										icon: "trash",
										loading: busy.value === `delete:${row.name}`,
										"aria-label": unref(__)("Delete"),
										onClick: ($event) => remove(row)
									}, null, 8, [
										"loading",
										"aria-label",
										"onClick"
									])
								])];
							}),
							_: 1
						}, _parent, _scopeId));
						else return [createVNode(unref(KTable), {
							columns: [
								{
									key: "made",
									label: unref(__)("Made")
								},
								{
									key: "contents",
									label: unref(__)("Contents")
								},
								{
									key: "size",
									label: unref(__)("Size"),
									align: "end"
								},
								{
									key: "actions",
									label: "",
									align: "end",
									width: "1%"
								}
							],
							rows: data.value.backups,
							"row-key": "name",
							empty: unref(__)("No backups yet"),
							"empty-text": unref(__)("Make the first one with Back up now.")
						}, {
							"cell-made": withCtx(({ row }) => [createVNode(unref(KTime), { value: row.created_at }, null, 8, ["value"]), createVNode("div", { class: "k-small k-backups__kind" }, [createVNode(unref(KBadge), { tone: row.kind === "uploaded" ? "accent" : "neutral" }, {
								default: withCtx(() => [createTextVNode(toDisplayString(kinds[row.kind] ?? row.kind), 1)]),
								_: 2
							}, 1032, ["tone"]), row.note ? (openBlock(), createBlock("span", {
								key: 0,
								class: "k-muted"
							}, toDisplayString(row.note), 1)) : createCommentVNode("v-if", true)])]),
							"cell-contents": withCtx(({ row }) => [createTextVNode(toDisplayString(row.files ? unref(__)("Database and files") : unref(__)("Database only")) + " ", 1), createVNode("div", { class: "k-muted k-small" }, [
								createVNode("span", { class: "k-num" }, toDisplayString(rowsText(row.rows)), 1),
								row.driver ? (openBlock(), createBlock(Fragment, { key: 0 }, [createTextVNode(" · " + toDisplayString(row.driver), 1)], 64)) : createCommentVNode("v-if", true),
								row.core ? (openBlock(), createBlock(Fragment, { key: 1 }, [createTextVNode(" · Kestello " + toDisplayString(row.core), 1)], 64)) : createCommentVNode("v-if", true)
							])]),
							"cell-size": withCtx(({ row }) => [createVNode("span", { class: "k-num" }, toDisplayString(unref(formatBytes)(row.size)), 1)]),
							"cell-actions": withCtx(({ row }) => [createVNode("div", { class: "k-backups__actions" }, [
								createVNode(unref(KButton), {
									size: "sm",
									icon: "arrow-counter-clockwise",
									onClick: ($event) => openRestore(row)
								}, {
									default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Restore")), 1)]),
									_: 1
								}, 8, ["onClick"]),
								createVNode(unref(KButton), {
									size: "sm",
									variant: "ghost",
									icon: "download",
									loading: busy.value === `download:${row.name}`,
									"aria-label": unref(__)("Download"),
									onClick: ($event) => download(row)
								}, null, 8, [
									"loading",
									"aria-label",
									"onClick"
								]),
								createVNode(unref(KButton), {
									size: "sm",
									variant: "ghost",
									icon: "trash",
									loading: busy.value === `delete:${row.name}`,
									"aria-label": unref(__)("Delete"),
									onClick: ($event) => remove(row)
								}, null, 8, [
									"loading",
									"aria-label",
									"onClick"
								])
							])]),
							_: 1
						}, 8, [
							"columns",
							"rows",
							"empty",
							"empty-text"
						])];
					}),
					_: 1
				}, _parent));
				_push(`<!--]-->`);
			} else _push(`<!---->`);
			_push(ssrRenderComponent(unref(KModal), {
				open: making.value,
				"onUpdate:open": ($event) => making.value = $event,
				title: unref(__)("Back up now")
			}, {
				footer: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(ssrRenderComponent(unref(KButton), { onClick: ($event) => making.value = false }, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Cancel"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Cancel")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						_push(ssrRenderComponent(unref(KButton), {
							type: "submit",
							form: "k-backup-new",
							variant: "primary",
							icon: "archive",
							loading: busy.value === "create"
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Back up"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Back up")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
					} else return [createVNode(unref(KButton), { onClick: ($event) => making.value = false }, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Cancel")), 1)]),
						_: 1
					}, 8, ["onClick"]), createVNode(unref(KButton), {
						type: "submit",
						form: "k-backup-new",
						variant: "primary",
						icon: "archive",
						loading: busy.value === "create"
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Back up")), 1)]),
						_: 1
					}, 8, ["loading"])];
				}),
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(`<form id="k-backup-new" class="k-form" data-v-1474de09${_scopeId}>`);
						_push(ssrRenderComponent(unref(KSwitch), {
							modelValue: makeForm.files,
							"onUpdate:modelValue": ($event) => makeForm.files = $event,
							label: unref(__)("Include files (uploads, plugins, themes)")
						}, null, _parent, _scopeId));
						_push(`<p class="k-muted k-small k-backups__note" data-v-1474de09${_scopeId}>${ssrInterpolate(makeForm.files ? unref(__)("Everything needed to move the site. Files on remote storage (S3 and the like) are not copied.") : unref(__)("Content, settings and people. Smaller and quicker; files stay as they are when restoring."))}</p>`);
						_push(ssrRenderComponent(unref(KField), {
							label: unref(__)("Note"),
							help: unref(__)("To find it again later, such as: before the redesign.")
						}, {
							default: withCtx(({ id }, _push, _parent, _scopeId) => {
								if (_push) _push(ssrRenderComponent(unref(KInput), {
									id,
									modelValue: makeForm.note,
									"onUpdate:modelValue": ($event) => makeForm.note = $event,
									maxlength: "200"
								}, null, _parent, _scopeId));
								else return [createVNode(unref(KInput), {
									id,
									modelValue: makeForm.note,
									"onUpdate:modelValue": ($event) => makeForm.note = $event,
									maxlength: "200"
								}, null, 8, [
									"id",
									"modelValue",
									"onUpdate:modelValue"
								])];
							}),
							_: 1
						}, _parent, _scopeId));
						_push(`</form>`);
					} else return [createVNode("form", {
						id: "k-backup-new",
						class: "k-form",
						onSubmit: withModifiers(makeBackup, ["prevent"])
					}, [
						createVNode(unref(KSwitch), {
							modelValue: makeForm.files,
							"onUpdate:modelValue": ($event) => makeForm.files = $event,
							label: unref(__)("Include files (uploads, plugins, themes)")
						}, null, 8, [
							"modelValue",
							"onUpdate:modelValue",
							"label"
						]),
						createVNode("p", { class: "k-muted k-small k-backups__note" }, toDisplayString(makeForm.files ? unref(__)("Everything needed to move the site. Files on remote storage (S3 and the like) are not copied.") : unref(__)("Content, settings and people. Smaller and quicker; files stay as they are when restoring.")), 1),
						createVNode(unref(KField), {
							label: unref(__)("Note"),
							help: unref(__)("To find it again later, such as: before the redesign.")
						}, {
							default: withCtx(({ id }) => [createVNode(unref(KInput), {
								id,
								modelValue: makeForm.note,
								"onUpdate:modelValue": ($event) => makeForm.note = $event,
								maxlength: "200"
							}, null, 8, [
								"id",
								"modelValue",
								"onUpdate:modelValue"
							])]),
							_: 1
						}, 8, ["label", "help"])
					], 32)];
				}),
				_: 1
			}, _parent));
			_push(ssrRenderComponent(unref(KModal), {
				open: uploading.value,
				"onUpdate:open": ($event) => uploading.value = $event,
				title: unref(__)("Upload a backup")
			}, {
				footer: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(ssrRenderComponent(unref(KButton), { onClick: ($event) => uploading.value = false }, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Cancel"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Cancel")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						_push(ssrRenderComponent(unref(KButton), {
							type: "submit",
							form: "k-backup-upload",
							variant: "primary",
							icon: "upload",
							loading: busy.value === "upload",
							disabled: !uploadFile.value
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Upload"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Upload")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
					} else return [createVNode(unref(KButton), { onClick: ($event) => uploading.value = false }, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Cancel")), 1)]),
						_: 1
					}, 8, ["onClick"]), createVNode(unref(KButton), {
						type: "submit",
						form: "k-backup-upload",
						variant: "primary",
						icon: "upload",
						loading: busy.value === "upload",
						disabled: !uploadFile.value
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Upload")), 1)]),
						_: 1
					}, 8, ["loading", "disabled"])];
				}),
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(`<form id="k-backup-upload" class="k-form" data-v-1474de09${_scopeId}><p class="k-muted k-small k-backups__note" data-v-1474de09${_scopeId}>${ssrInterpolate(unref(__)("A backup zip from another Kestello site, on any database. It is checked, then listed here to restore."))}</p>`);
						_push(ssrRenderComponent(unref(KField), {
							label: unref(__)("Backup zip"),
							error: uploadErrors.value.file ?? ""
						}, {
							default: withCtx(({ id }, _push, _parent, _scopeId) => {
								if (_push) _push(`<input${ssrRenderAttr("id", id)} type="file" accept=".zip,application/zip" class="k-input" required data-v-1474de09${_scopeId}>`);
								else return [createVNode("input", {
									id,
									type: "file",
									accept: ".zip,application/zip",
									class: "k-input",
									required: "",
									onChange: ($event) => uploadFile.value = $event.target.files?.[0] ?? null
								}, null, 40, ["id", "onChange"])];
							}),
							_: 1
						}, _parent, _scopeId));
						_push(`</form>`);
					} else return [createVNode("form", {
						id: "k-backup-upload",
						class: "k-form",
						onSubmit: withModifiers(upload, ["prevent"])
					}, [createVNode("p", { class: "k-muted k-small k-backups__note" }, toDisplayString(unref(__)("A backup zip from another Kestello site, on any database. It is checked, then listed here to restore.")), 1), createVNode(unref(KField), {
						label: unref(__)("Backup zip"),
						error: uploadErrors.value.file ?? ""
					}, {
						default: withCtx(({ id }) => [createVNode("input", {
							id,
							type: "file",
							accept: ".zip,application/zip",
							class: "k-input",
							required: "",
							onChange: ($event) => uploadFile.value = $event.target.files?.[0] ?? null
						}, null, 40, ["id", "onChange"])]),
						_: 1
					}, 8, ["label", "error"])], 32)];
				}),
				_: 1
			}, _parent));
			_push(ssrRenderComponent(unref(KModal), {
				open: !!restoring.value,
				title: unref(__)("Restore this backup?"),
				onClose: ($event) => restoring.value = null
			}, {
				footer: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(ssrRenderComponent(unref(KButton), { onClick: ($event) => restoring.value = null }, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Cancel"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Cancel")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						_push(ssrRenderComponent(unref(KButton), {
							type: "submit",
							form: "k-backup-restore",
							variant: "danger",
							icon: "arrow-counter-clockwise",
							loading: busy.value === "restore",
							disabled: confirmName.value.trim() !== data.value?.site_name
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Restore"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Restore")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
					} else return [createVNode(unref(KButton), { onClick: ($event) => restoring.value = null }, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Cancel")), 1)]),
						_: 1
					}, 8, ["onClick"]), createVNode(unref(KButton), {
						type: "submit",
						form: "k-backup-restore",
						variant: "danger",
						icon: "arrow-counter-clockwise",
						loading: busy.value === "restore",
						disabled: confirmName.value.trim() !== data.value?.site_name
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Restore")), 1)]),
						_: 1
					}, 8, ["loading", "disabled"])];
				}),
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						if (restoring.value) {
							_push(`<form id="k-backup-restore" class="k-form" data-v-1474de09${_scopeId}>`);
							_push(ssrRenderComponent(unref(KAlert), { tone: "danger" }, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate(restoring.value.files ? unref(__)("Everything on the site is replaced with the backup from {date}: content, settings, people and files. Everyone is signed out, you too.", { date: (/* @__PURE__ */ new Date(restoring.value.created_at * 1e3)).toLocaleString() }) : unref(__)("Everything on the site is replaced with the backup from {date}: content, settings and people. Everyone is signed out, you too.", { date: (/* @__PURE__ */ new Date(restoring.value.created_at * 1e3)).toLocaleString() }))}`);
									else return [createTextVNode(toDisplayString(restoring.value.files ? unref(__)("Everything on the site is replaced with the backup from {date}: content, settings, people and files. Everyone is signed out, you too.", { date: (/* @__PURE__ */ new Date(restoring.value.created_at * 1e3)).toLocaleString() }) : unref(__)("Everything on the site is replaced with the backup from {date}: content, settings and people. Everyone is signed out, you too.", { date: (/* @__PURE__ */ new Date(restoring.value.created_at * 1e3)).toLocaleString() })), 1)];
								}),
								_: 1
							}, _parent, _scopeId));
							_push(`<p class="k-muted k-small k-backups__note" data-v-1474de09${_scopeId}>${ssrInterpolate(unref(__)("The site as it is now is backed up first, and put back by itself if the restore fails."))} `);
							if (restoring.value.kind === "uploaded") _push(`<!--[-->${ssrInterpolate(unref(__)("This backup comes from another site: you sign in with its accounts afterwards, and its plugin and theme folders are only restored from the command line."))}<!--]-->`);
							else _push(`<!---->`);
							_push(`</p>`);
							_push(ssrRenderComponent(unref(KField), {
								label: unref(__)("Type the site name, {name}, to confirm", { name: data.value.site_name }),
								error: restoreErrors.value.confirm ?? ""
							}, {
								default: withCtx(({ id }, _push, _parent, _scopeId) => {
									if (_push) _push(ssrRenderComponent(unref(KInput), {
										id,
										modelValue: confirmName.value,
										"onUpdate:modelValue": ($event) => confirmName.value = $event,
										autocomplete: "off",
										required: ""
									}, null, _parent, _scopeId));
									else return [createVNode(unref(KInput), {
										id,
										modelValue: confirmName.value,
										"onUpdate:modelValue": ($event) => confirmName.value = $event,
										autocomplete: "off",
										required: ""
									}, null, 8, [
										"id",
										"modelValue",
										"onUpdate:modelValue"
									])];
								}),
								_: 1
							}, _parent, _scopeId));
							_push(`</form>`);
						} else _push(`<!---->`);
					} else return [restoring.value ? (openBlock(), createBlock("form", {
						key: 0,
						id: "k-backup-restore",
						class: "k-form",
						onSubmit: withModifiers(restore, ["prevent"])
					}, [
						createVNode(unref(KAlert), { tone: "danger" }, {
							default: withCtx(() => [createTextVNode(toDisplayString(restoring.value.files ? unref(__)("Everything on the site is replaced with the backup from {date}: content, settings, people and files. Everyone is signed out, you too.", { date: (/* @__PURE__ */ new Date(restoring.value.created_at * 1e3)).toLocaleString() }) : unref(__)("Everything on the site is replaced with the backup from {date}: content, settings and people. Everyone is signed out, you too.", { date: (/* @__PURE__ */ new Date(restoring.value.created_at * 1e3)).toLocaleString() })), 1)]),
							_: 2
						}, 1024),
						createVNode("p", { class: "k-muted k-small k-backups__note" }, [createTextVNode(toDisplayString(unref(__)("The site as it is now is backed up first, and put back by itself if the restore fails.")) + " ", 1), restoring.value.kind === "uploaded" ? (openBlock(), createBlock(Fragment, { key: 0 }, [createTextVNode(toDisplayString(unref(__)("This backup comes from another site: you sign in with its accounts afterwards, and its plugin and theme folders are only restored from the command line.")), 1)], 64)) : createCommentVNode("v-if", true)]),
						createVNode(unref(KField), {
							label: unref(__)("Type the site name, {name}, to confirm", { name: data.value.site_name }),
							error: restoreErrors.value.confirm ?? ""
						}, {
							default: withCtx(({ id }) => [createVNode(unref(KInput), {
								id,
								modelValue: confirmName.value,
								"onUpdate:modelValue": ($event) => confirmName.value = $event,
								autocomplete: "off",
								required: ""
							}, null, 8, [
								"id",
								"modelValue",
								"onUpdate:modelValue"
							])]),
							_: 1
						}, 8, ["label", "error"])
					], 32)) : createCommentVNode("v-if", true)];
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
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/admin/system/Backups.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
var Backups_default = /*#__PURE__*/ _plugin_vue_export_helper_default(_sfc_main, [["__scopeId", "data-v-1474de09"]]);
export { Backups_default as default };
