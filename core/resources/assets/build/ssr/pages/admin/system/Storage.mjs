import { t as _plugin_vue_export_helper_default } from "../../../chunks/_plugin-vue_export-helper-DjkACiAr.mjs";
import { Fragment, computed, createBlock, createCommentVNode, createSlots, createTextVNode, createVNode, onBeforeUnmount, openBlock, reactive, ref, renderList, toDisplayString, unref, useSSRContext, watch, withCtx, withModifiers } from "vue";
import { ssrInterpolate, ssrRenderAttr, ssrRenderComponent, ssrRenderList, ssrRenderStyle } from "vue/server-renderer";
import { Head } from "@kestello/app";
import { ApiError, __, confirm, formatBytes, notify, useApi } from "@kestello/sdk";
import { KAlert, KBadge, KButton, KCard, KField, KInput, KModal, KPageHeader, KSwitch, KTable } from "@kestello/ui";
// core/resources/js/pages/admin/system/Storage.vue
var _sfc_main = /*@__PURE__*/ Object.assign({ layout: "Admin" }, {
	__name: "Storage",
	__ssrInlineRender: true,
	setup(__props) {
		/** System → File storage: where media files live, moving them, upload quotas per role. */
		const api = useApi();
		const data = ref(null);
		const loadError = ref(null);
		async function load() {
			try {
				const body = await api.get("/system/storage");
				data.value = body.data;
				loadError.value = null;
			} catch (e) {
				loadError.value = e;
			}
		}
		load();
		const DRIVERS = {
			local: __("This server"),
			s3: __("S3-compatible bucket"),
			scoped: __("Folder of another disk"),
			mirror: __("Two disks at once"),
			cached: __("Remembered metadata"),
			missing: __("Not configured")
		};
		const columns = [
			{
				key: "name",
				label: __("Disk")
			},
			{
				key: "driver",
				label: __("Kind")
			},
			{
				key: "files",
				label: __("Files"),
				align: "end"
			},
			{
				key: "bytes",
				label: __("Size"),
				align: "end"
			},
			{
				key: "actions",
				label: "",
				align: "end"
			}
		];
		const disks = computed(() => data.value?.disks ?? []);
		const move = computed(() => data.value?.move ?? null);
		const running = computed(() => move.value?.status === "running");
		const progress = computed(() => move.value && move.value.total ? Math.round(move.value.done / move.value.total * 100) : 0);
		const failures = computed(() => Object.entries(move.value?.failed ?? {}));
		const target = ref(null);
		const keepSource = ref(false);
		const starting = ref(false);
		const moveError = ref("");
		function askMove(disk) {
			target.value = disk;
			keepSource.value = false;
			moveError.value = "";
		}
		async function startMove() {
			starting.value = true;
			moveError.value = "";
			try {
				const body = await api.post("/system/storage/move", {
					to: target.value.name,
					keep_source: keepSource.value
				});
				data.value = body.data;
				target.value = null;
				notify.success(__("Moving started. New uploads already go to {disk}.", { disk: body.data.current }));
			} catch (e) {
				if (e instanceof ApiError && e.status === 422) moveError.value = e.fieldErrors().to ?? e.message;
				else notify.error(e);
			} finally {
				starting.value = false;
			}
		}
		async function stopMove() {
			if (!await confirm({
				title: __("Stop moving files?"),
				message: __("Files already moved stay on the new disk and keep working. Starting the move again carries on where it stopped."),
				confirmLabel: __("Stop")
			})) return;
			try {
				data.value = (await api.delete("/system/storage/move")).data;
			} catch (e) {
				notify.error(e);
			}
		}
		let alive = true;
		let timer = null;
		let stepping = false;
		let failedSteps = 0;
		async function tick() {
			if (!alive || !running.value) return;
			try {
				if (data.value.worker) await load();
				else if (!stepping) {
					stepping = true;
					data.value = (await api.post("/system/storage/move/step")).data;
				}
				failedSteps = 0;
			} catch (e) {
				failedSteps++;
				if (failedSteps === 1) notify.error(e);
				stepping = false;
				if (alive && running.value) timer = setTimeout(tick, Math.min(3e4, 1e3 * 2 ** failedSteps));
				return;
			} finally {
				stepping = false;
			}
			if (alive && running.value) timer = setTimeout(tick, data.value.worker ? 3e3 : 50);
			else if (move.value?.status === "done") notify.success(failures.value.length ? __("Moving finished; some files could not be moved.") : __("Every file is on {disk} now.", { disk: move.value.to }));
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
		const quotas = reactive({});
		const quotaErrors = ref({});
		const savingQuotas = ref(false);
		watch(data, (d, before) => {
			if (!d) return;
			for (const r of d.roles) {
				const was = before?.quotas?.[r.name] ?? "";
				if (!before || !(r.name in quotas) || String(quotas[r.name] ?? "") === String(was)) quotas[r.name] = d.quotas[r.name] ?? "";
			}
		}, { immediate: true });
		async function saveQuotas() {
			savingQuotas.value = true;
			quotaErrors.value = {};
			const body = Object.fromEntries(Object.entries(quotas).map(([role, mb]) => [role, mb === "" || mb === null ? null : Number(mb)]));
			try {
				data.value = (await api.patch("/system/storage/quotas", { quotas: body })).data;
				notify.success(__("Quotas saved."));
			} catch (e) {
				if (e instanceof ApiError && e.status === 422) quotaErrors.value = Object.fromEntries(Object.entries(e.fieldErrors()).map(([k, v]) => [k.replace(/^quotas\./, ""), v]));
				else notify.error(e);
			} finally {
				savingQuotas.value = false;
			}
		}
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<!--[-->`);
			_push(ssrRenderComponent(unref(Head), { title: unref(__)("File storage") }, null, _parent));
			_push(ssrRenderComponent(unref(KPageHeader), {
				title: unref(__)("File storage"),
				description: unref(__)("Where the media library keeps its files. Add disks such as an S3 or R2 bucket in config/storage.php, then move the library there from this screen.")
			}, null, _parent));
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
				if (move.value && move.value.status !== "done" || move.value && failures.value.length) _push(ssrRenderComponent(unref(KCard), {
					coord: "S1",
					title: running.value ? unref(__)("Moving files to {disk}", { disk: move.value.to }) : move.value.status === "stopped" ? unref(__)("Moving to {disk} stopped", { disk: move.value.to }) : unref(__)("Moved to {disk}", { disk: move.value.to })
				}, createSlots({
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) {
							_push(`<div class="k-move" data-v-5e714736${_scopeId}><div class="k-move__bar" role="progressbar"${ssrRenderAttr("aria-valuenow", progress.value)} aria-valuemin="0" aria-valuemax="100"${ssrRenderAttr("aria-label", unref(__)("Files moved"))} data-v-5e714736${_scopeId}><span style="${ssrRenderStyle({ width: `${progress.value}%` })}" data-v-5e714736${_scopeId}></span></div><p class="k-move__count" data-v-5e714736${_scopeId}><strong class="k-num" data-v-5e714736${_scopeId}>${ssrInterpolate(move.value.done.toLocaleString())}</strong> ${ssrInterpolate(unref(__)("of {total} moved", { total: move.value.total.toLocaleString() }))} `);
							if (running.value && move.value.remaining) _push(`<!--[--> · ${ssrInterpolate(unref(__)("{n} left", { n: move.value.remaining.toLocaleString() }))}<!--]-->`);
							else _push(`<!---->`);
							_push(`</p>`);
							if (running.value) _push(`<p class="k-muted k-small" data-v-5e714736${_scopeId}>${ssrInterpolate(data.value.worker ? unref(__)("The background worker is moving them. You can leave this screen.") : unref(__)("No background worker runs on this site, so this screen moves the files while it stays open. Closing it pauses the move; opening it again carries on."))} ${ssrInterpolate(move.value.keep_source ? unref(__)("Copies stay on the old disk.") : unref(__)("Each file is removed from the old disk once its copy is checked."))}</p>`);
							else if (move.value.status === "stopped") _push(`<p class="k-muted k-small" data-v-5e714736${_scopeId}>${ssrInterpolate(unref(__)("Files already moved work from the new disk. Start the move again to carry on."))}</p>`);
							else _push(`<!---->`);
							if (failures.value.length) {
								_push(`<div class="k-move__failed" data-v-5e714736${_scopeId}><p class="k-small" data-v-5e714736${_scopeId}><strong data-v-5e714736${_scopeId}>${ssrInterpolate(failures.value.length === 1 ? unref(__)("1 file stayed where it was:") : unref(__)("{n} files stayed where they were:", { n: failures.value.length }))}</strong></p><ul class="k-small" data-v-5e714736${_scopeId}><!--[-->`);
								ssrRenderList(failures.value, ([id, why]) => {
									_push(`<li data-v-5e714736${_scopeId}>${ssrInterpolate(why)}</li>`);
								});
								_push(`<!--]--></ul><p class="k-muted k-small" data-v-5e714736${_scopeId}>${ssrInterpolate(unref(__)("They still work from the old disk. Moving to {disk} again retries them.", { disk: move.value.to }))}</p></div>`);
							} else _push(`<!---->`);
							_push(`</div>`);
						} else return [createVNode("div", { class: "k-move" }, [
							createVNode("div", {
								class: "k-move__bar",
								role: "progressbar",
								"aria-valuenow": progress.value,
								"aria-valuemin": "0",
								"aria-valuemax": "100",
								"aria-label": unref(__)("Files moved")
							}, [createVNode("span", { style: { width: `${progress.value}%` } }, null, 4)], 8, ["aria-valuenow", "aria-label"]),
							createVNode("p", { class: "k-move__count" }, [
								createVNode("strong", { class: "k-num" }, toDisplayString(move.value.done.toLocaleString()), 1),
								createTextVNode(" " + toDisplayString(unref(__)("of {total} moved", { total: move.value.total.toLocaleString() })) + " ", 1),
								running.value && move.value.remaining ? (openBlock(), createBlock(Fragment, { key: 0 }, [createTextVNode(" · " + toDisplayString(unref(__)("{n} left", { n: move.value.remaining.toLocaleString() })), 1)], 64)) : createCommentVNode("v-if", true)
							]),
							running.value ? (openBlock(), createBlock("p", {
								key: 0,
								class: "k-muted k-small"
							}, toDisplayString(data.value.worker ? unref(__)("The background worker is moving them. You can leave this screen.") : unref(__)("No background worker runs on this site, so this screen moves the files while it stays open. Closing it pauses the move; opening it again carries on.")) + " " + toDisplayString(move.value.keep_source ? unref(__)("Copies stay on the old disk.") : unref(__)("Each file is removed from the old disk once its copy is checked.")), 1)) : move.value.status === "stopped" ? (openBlock(), createBlock("p", {
								key: 1,
								class: "k-muted k-small"
							}, toDisplayString(unref(__)("Files already moved work from the new disk. Start the move again to carry on.")), 1)) : createCommentVNode("v-if", true),
							failures.value.length ? (openBlock(), createBlock("div", {
								key: 2,
								class: "k-move__failed"
							}, [
								createVNode("p", { class: "k-small" }, [createVNode("strong", null, toDisplayString(failures.value.length === 1 ? unref(__)("1 file stayed where it was:") : unref(__)("{n} files stayed where they were:", { n: failures.value.length })), 1)]),
								createVNode("ul", { class: "k-small" }, [(openBlock(true), createBlock(Fragment, null, renderList(failures.value, ([id, why]) => {
									return openBlock(), createBlock("li", { key: id }, toDisplayString(why), 1);
								}), 128))]),
								createVNode("p", { class: "k-muted k-small" }, toDisplayString(unref(__)("They still work from the old disk. Moving to {disk} again retries them.", { disk: move.value.to })), 1)
							])) : createCommentVNode("v-if", true)
						])];
					}),
					_: 2
				}, [running.value ? {
					name: "actions",
					fn: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(ssrRenderComponent(unref(KButton), {
							size: "sm",
							icon: "stop-circle",
							onClick: stopMove
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
							onClick: stopMove
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Stop")), 1)]),
							_: 1
						})];
					}),
					key: "0"
				} : void 0]), _parent));
				else _push(`<!---->`);
				_push(ssrRenderComponent(unref(KCard), {
					coord: "S2",
					title: unref(__)("Disks"),
					flush: ""
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) {
							_push(ssrRenderComponent(unref(KTable), {
								columns,
								rows: disks.value,
								empty: unref(__)("No disks can hold media.")
							}, {
								"cell-name": withCtx(({ row }, _push, _parent, _scopeId) => {
									if (_push) {
										_push(`<span class="k-mono" data-v-5e714736${_scopeId}>${ssrInterpolate(row.name)}</span>`);
										if (row.current) _push(ssrRenderComponent(unref(KBadge), {
											tone: "accent",
											class: "k-disk__badge"
										}, {
											default: withCtx((_, _push, _parent, _scopeId) => {
												if (_push) _push(`${ssrInterpolate(unref(__)("New uploads"))}`);
												else return [createTextVNode(toDisplayString(unref(__)("New uploads")), 1)];
											}),
											_: 2
										}, _parent, _scopeId));
										else _push(`<!---->`);
										if (row.problem) _push(`<div class="k-muted k-small k-disk__problem" data-v-5e714736${_scopeId}>${ssrInterpolate(row.problem)}</div>`);
										else _push(`<!---->`);
									} else return [
										createVNode("span", { class: "k-mono" }, toDisplayString(row.name), 1),
										row.current ? (openBlock(), createBlock(unref(KBadge), {
											key: 0,
											tone: "accent",
											class: "k-disk__badge"
										}, {
											default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("New uploads")), 1)]),
											_: 1
										})) : createCommentVNode("v-if", true),
										row.problem ? (openBlock(), createBlock("div", {
											key: 1,
											class: "k-muted k-small k-disk__problem"
										}, toDisplayString(row.problem), 1)) : createCommentVNode("v-if", true)
									];
								}),
								"cell-driver": withCtx(({ row }, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate(DRIVERS[row.driver] ?? row.driver)}`);
									else return [createTextVNode(toDisplayString(DRIVERS[row.driver] ?? row.driver), 1)];
								}),
								"cell-files": withCtx(({ row }, _push, _parent, _scopeId) => {
									if (_push) _push(`<span class="k-num" data-v-5e714736${_scopeId}>${ssrInterpolate(row.files.toLocaleString())}</span>`);
									else return [createVNode("span", { class: "k-num" }, toDisplayString(row.files.toLocaleString()), 1)];
								}),
								"cell-bytes": withCtx(({ row }, _push, _parent, _scopeId) => {
									if (_push) _push(`<span class="k-num" data-v-5e714736${_scopeId}>${ssrInterpolate(unref(formatBytes)(row.bytes))}</span>`);
									else return [createVNode("span", { class: "k-num" }, toDisplayString(unref(formatBytes)(row.bytes)), 1)];
								}),
								"cell-actions": withCtx(({ row }, _push, _parent, _scopeId) => {
									if (_push) {
										if (!row.problem && (!row.current || disks.value.some((d) => !d.current && d.files))) _push(ssrRenderComponent(unref(KButton), {
											size: "sm",
											variant: "ghost",
											icon: "download-simple",
											disabled: running.value,
											onClick: ($event) => askMove(row)
										}, {
											default: withCtx((_, _push, _parent, _scopeId) => {
												if (_push) _push(`${ssrInterpolate(unref(__)("Move everything here"))}`);
												else return [createTextVNode(toDisplayString(unref(__)("Move everything here")), 1)];
											}),
											_: 2
										}, _parent, _scopeId));
										else _push(`<!---->`);
									} else return [!row.problem && (!row.current || disks.value.some((d) => !d.current && d.files)) ? (openBlock(), createBlock(unref(KButton), {
										key: 0,
										size: "sm",
										variant: "ghost",
										icon: "download-simple",
										disabled: running.value,
										onClick: ($event) => askMove(row)
									}, {
										default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Move everything here")), 1)]),
										_: 1
									}, 8, ["disabled", "onClick"])) : createCommentVNode("v-if", true)];
								}),
								_: 1
							}, _parent, _scopeId));
							_push(`<p class="k-muted k-small k-disk__note" data-v-5e714736${_scopeId}>${ssrInterpolate(unref(__)("Pages build file addresses from the library, so moving changes no content. Each file is checked on the new disk before the library points at it."))}</p>`);
						} else return [createVNode(unref(KTable), {
							columns,
							rows: disks.value,
							empty: unref(__)("No disks can hold media.")
						}, {
							"cell-name": withCtx(({ row }) => [
								createVNode("span", { class: "k-mono" }, toDisplayString(row.name), 1),
								row.current ? (openBlock(), createBlock(unref(KBadge), {
									key: 0,
									tone: "accent",
									class: "k-disk__badge"
								}, {
									default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("New uploads")), 1)]),
									_: 1
								})) : createCommentVNode("v-if", true),
								row.problem ? (openBlock(), createBlock("div", {
									key: 1,
									class: "k-muted k-small k-disk__problem"
								}, toDisplayString(row.problem), 1)) : createCommentVNode("v-if", true)
							]),
							"cell-driver": withCtx(({ row }) => [createTextVNode(toDisplayString(DRIVERS[row.driver] ?? row.driver), 1)]),
							"cell-files": withCtx(({ row }) => [createVNode("span", { class: "k-num" }, toDisplayString(row.files.toLocaleString()), 1)]),
							"cell-bytes": withCtx(({ row }) => [createVNode("span", { class: "k-num" }, toDisplayString(unref(formatBytes)(row.bytes)), 1)]),
							"cell-actions": withCtx(({ row }) => [!row.problem && (!row.current || disks.value.some((d) => !d.current && d.files)) ? (openBlock(), createBlock(unref(KButton), {
								key: 0,
								size: "sm",
								variant: "ghost",
								icon: "download-simple",
								disabled: running.value,
								onClick: ($event) => askMove(row)
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Move everything here")), 1)]),
								_: 1
							}, 8, ["disabled", "onClick"])) : createCommentVNode("v-if", true)]),
							_: 2
						}, 1032, ["rows", "empty"]), createVNode("p", { class: "k-muted k-small k-disk__note" }, toDisplayString(unref(__)("Pages build file addresses from the library, so moving changes no content. Each file is checked on the new disk before the library points at it.")), 1)];
					}),
					_: 1
				}, _parent));
				_push(ssrRenderComponent(unref(KCard), {
					coord: "S3",
					title: unref(__)("Upload quotas"),
					description: unref(__)("How much each role may keep in the media library. Administrators are never limited. Leave a field empty for no limit.")
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) {
							_push(`<form class="k-quotas" data-v-5e714736${_scopeId}>`);
							if (!data.value.roles.length) _push(`<p class="k-muted k-small" data-v-5e714736${_scopeId}>${ssrInterpolate(unref(__)("No role other than administrators can upload."))}</p>`);
							else _push(`<!---->`);
							_push(`<!--[-->`);
							ssrRenderList(data.value.roles, (r) => {
								_push(ssrRenderComponent(unref(KField), {
									key: r.name,
									label: r.label,
									error: quotaErrors.value[r.name] ?? ""
								}, {
									default: withCtx(({ id, invalid }, _push, _parent, _scopeId) => {
										if (_push) {
											_push(`<span class="k-quotas__input" data-v-5e714736${_scopeId}>`);
											_push(ssrRenderComponent(unref(KInput), {
												id,
												modelValue: quotas[r.name],
												"onUpdate:modelValue": ($event) => quotas[r.name] = $event,
												type: "number",
												min: "1",
												max: "10000000",
												inputmode: "numeric",
												invalid
											}, null, _parent, _scopeId));
											_push(`<span class="k-muted k-small" data-v-5e714736${_scopeId}>MB</span></span>`);
										} else return [createVNode("span", { class: "k-quotas__input" }, [createVNode(unref(KInput), {
											id,
											modelValue: quotas[r.name],
											"onUpdate:modelValue": ($event) => quotas[r.name] = $event,
											type: "number",
											min: "1",
											max: "10000000",
											inputmode: "numeric",
											invalid
										}, null, 8, [
											"id",
											"modelValue",
											"onUpdate:modelValue",
											"invalid"
										]), createVNode("span", { class: "k-muted k-small" }, "MB")])];
									}),
									_: 2
								}, _parent, _scopeId));
							});
							_push(`<!--]-->`);
							if (data.value.roles.length) {
								_push(`<div data-v-5e714736${_scopeId}>`);
								_push(ssrRenderComponent(unref(KButton), {
									type: "submit",
									variant: "primary",
									icon: "check",
									loading: savingQuotas.value
								}, {
									default: withCtx((_, _push, _parent, _scopeId) => {
										if (_push) _push(`${ssrInterpolate(unref(__)("Save quotas"))}`);
										else return [createTextVNode(toDisplayString(unref(__)("Save quotas")), 1)];
									}),
									_: 1
								}, _parent, _scopeId));
								_push(`</div>`);
							} else _push(`<!---->`);
							_push(`</form>`);
						} else return [createVNode("form", {
							class: "k-quotas",
							onSubmit: withModifiers(saveQuotas, ["prevent"])
						}, [
							!data.value.roles.length ? (openBlock(), createBlock("p", {
								key: 0,
								class: "k-muted k-small"
							}, toDisplayString(unref(__)("No role other than administrators can upload.")), 1)) : createCommentVNode("v-if", true),
							(openBlock(true), createBlock(Fragment, null, renderList(data.value.roles, (r) => {
								return openBlock(), createBlock(unref(KField), {
									key: r.name,
									label: r.label,
									error: quotaErrors.value[r.name] ?? ""
								}, {
									default: withCtx(({ id, invalid }) => [createVNode("span", { class: "k-quotas__input" }, [createVNode(unref(KInput), {
										id,
										modelValue: quotas[r.name],
										"onUpdate:modelValue": ($event) => quotas[r.name] = $event,
										type: "number",
										min: "1",
										max: "10000000",
										inputmode: "numeric",
										invalid
									}, null, 8, [
										"id",
										"modelValue",
										"onUpdate:modelValue",
										"invalid"
									]), createVNode("span", { class: "k-muted k-small" }, "MB")])]),
									_: 2
								}, 1032, ["label", "error"]);
							}), 128)),
							data.value.roles.length ? (openBlock(), createBlock("div", { key: 1 }, [createVNode(unref(KButton), {
								type: "submit",
								variant: "primary",
								icon: "check",
								loading: savingQuotas.value
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Save quotas")), 1)]),
								_: 1
							}, 8, ["loading"])])) : createCommentVNode("v-if", true)
						], 32)];
					}),
					_: 1
				}, _parent));
				_push(`<!--]-->`);
			} else _push(`<!---->`);
			_push(ssrRenderComponent(unref(KModal), {
				open: !!target.value,
				title: target.value ? unref(__)("Move everything to {disk}?", { disk: target.value.name }) : "",
				onClose: ($event) => target.value = null
			}, {
				footer: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(ssrRenderComponent(unref(KButton), { onClick: ($event) => target.value = null }, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Cancel"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Cancel")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						_push(ssrRenderComponent(unref(KButton), {
							variant: "primary",
							icon: "download-simple",
							loading: starting.value,
							onClick: startMove
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Start moving"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Start moving")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
					} else return [createVNode(unref(KButton), { onClick: ($event) => target.value = null }, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Cancel")), 1)]),
						_: 1
					}, 8, ["onClick"]), createVNode(unref(KButton), {
						variant: "primary",
						icon: "download-simple",
						loading: starting.value,
						onClick: startMove
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Start moving")), 1)]),
						_: 1
					}, 8, ["loading"])];
				}),
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						if (target.value) {
							_push(`<div class="k-form" data-v-5e714736${_scopeId}><p data-v-5e714736${_scopeId}>${ssrInterpolate(unref(__)("New uploads go to {disk} straight away. Existing files follow one by one, with their sizes and thumbnails; each is checked there before pages use it.", { disk: target.value.name }))}</p>`);
							_push(ssrRenderComponent(unref(KSwitch), {
								modelValue: keepSource.value,
								"onUpdate:modelValue": ($event) => keepSource.value = $event,
								label: unref(__)("Keep the copies on the old disk")
							}, null, _parent, _scopeId));
							_push(`<p class="k-muted k-small" data-v-5e714736${_scopeId}>${ssrInterpolate(keepSource.value ? unref(__)("Useful as a backup until you have checked the new disk. They take space until you delete them.") : unref(__)("The old copy of each file is removed once its new copy is checked."))}</p>`);
							if (moveError.value) _push(ssrRenderComponent(unref(KAlert), { tone: "danger" }, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate(moveError.value)}`);
									else return [createTextVNode(toDisplayString(moveError.value), 1)];
								}),
								_: 1
							}, _parent, _scopeId));
							else _push(`<!---->`);
							_push(`</div>`);
						} else _push(`<!---->`);
					} else return [target.value ? (openBlock(), createBlock("div", {
						key: 0,
						class: "k-form"
					}, [
						createVNode("p", null, toDisplayString(unref(__)("New uploads go to {disk} straight away. Existing files follow one by one, with their sizes and thumbnails; each is checked there before pages use it.", { disk: target.value.name })), 1),
						createVNode(unref(KSwitch), {
							modelValue: keepSource.value,
							"onUpdate:modelValue": ($event) => keepSource.value = $event,
							label: unref(__)("Keep the copies on the old disk")
						}, null, 8, [
							"modelValue",
							"onUpdate:modelValue",
							"label"
						]),
						createVNode("p", { class: "k-muted k-small" }, toDisplayString(keepSource.value ? unref(__)("Useful as a backup until you have checked the new disk. They take space until you delete them.") : unref(__)("The old copy of each file is removed once its new copy is checked.")), 1),
						moveError.value ? (openBlock(), createBlock(unref(KAlert), {
							key: 0,
							tone: "danger"
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(moveError.value), 1)]),
							_: 1
						})) : createCommentVNode("v-if", true)
					])) : createCommentVNode("v-if", true)];
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
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/admin/system/Storage.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
var Storage_default = /*#__PURE__*/ _plugin_vue_export_helper_default(_sfc_main, [["__scopeId", "data-v-5e714736"]]);
export { Storage_default as default };
