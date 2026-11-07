import { Fragment, computed, createBlock, createCommentVNode, createSlots, createTextVNode, createVNode, openBlock, ref, toDisplayString, unref, useSSRContext, withCtx } from "vue";
import { ssrInterpolate, ssrRenderAttr, ssrRenderComponent, ssrRenderStyle } from "vue/server-renderer";
import { __, confirm, formatRelative, notify, useApi, useResource } from "@kestello/sdk";
import { KAlert, KBadge, KButton, KCard, KModal, KPageHeader, KStat, KTable, KTime } from "@kestello/ui";
// core/resources/js/pages/admin/system/Queue.vue
var _sfc_main = /*@__PURE__*/ Object.assign({ layout: "Admin" }, {
	__name: "Queue",
	__ssrInlineRender: true,
	setup(__props) {
		const api = useApi();
		const status = useResource("/system/queue", { poll: 10 });
		const failed = ref([]);
		const nextBefore = ref(null);
		const total = ref(0);
		const loadingFailed = ref(false);
		const shown = ref(null);
		const worker = computed(() => status.data.value?.worker ?? null);
		const workerFresh = computed(() => worker.value && Date.now() / 1e3 - worker.value.seen_at < 600);
		const pending = computed(() => (status.data.value?.queues ?? []).reduce((s, q) => s + q.pending, 0));
		async function loadFailed(more = false) {
			loadingFailed.value = true;
			try {
				const body = await api.get("/system/failed-jobs", {
					limit: 25,
					before: more ? nextBefore.value : void 0
				});
				failed.value = more ? [...failed.value, ...body.data] : body.data;
				nextBefore.value = body.meta.next_before;
				total.value = body.meta.total;
			} catch (e) {
				notify.error(e);
			} finally {
				loadingFailed.value = false;
			}
		}
		loadFailed();
		async function retry(id) {
			try {
				const { data } = await api.post(`/system/failed-jobs/${id}/retry`, {});
				notify.success(__("{count, plural, one {# job queued again.} other {# jobs queued again.}}", { count: data.retried }));
				await Promise.all([loadFailed(), status.reload()]);
			} catch (e) {
				notify.error(e);
			}
		}
		async function forget(id) {
			if (!await confirm({
				title: id === "all" ? __("Delete all failed jobs?") : __("Delete this failed job?"),
				message: __("The job and its error are removed. This cannot be undone."),
				confirmLabel: __("Delete"),
				danger: true
			})) return;
			try {
				const { data } = await api.delete(`/system/failed-jobs/${id}`);
				notify.success(__("{count, plural, one {# failed job deleted.} other {# failed jobs deleted.}}", { count: data.deleted }));
				await Promise.all([loadFailed(), status.reload()]);
			} catch (e) {
				notify.error(e);
			}
		}
		const batches = computed(() => status.data.value?.batches ?? []);
		const BATCH_STATUS = {
			running: ["info", __("Running")],
			cancelling: ["warning", __("Stopping")],
			finished: ["success", __("Finished")],
			finished_with_failures: ["warning", __("Finished with failures")],
			cancelled: ["neutral", __("Cancelled")]
		};
		async function cancelBatch(batch) {
			if (!await confirm({
				title: __("Stop \"{name}\"?", { name: batch.name }),
				message: __("Jobs that have not started are skipped. Jobs already running finish."),
				confirmLabel: __("Stop batch"),
				danger: true
			})) return;
			try {
				await api.post(`/system/queue/batches/${batch.id}/cancel`, {});
				notify.success(__("Batch stopped."));
				await status.reload({ silent: true });
			} catch (e) {
				notify.error(e);
			}
		}
		function shortClass(name) {
			return String(name).split("\\").pop();
		}
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<!--[-->`);
			_push(ssrRenderComponent(unref(KPageHeader), {
				title: unref(__)("Background jobs"),
				description: unref(__)("Work that runs outside page requests: emails, imports, image processing and plugin jobs.")
			}, null, _parent));
			if (unref(status).data.value?.sync) _push(ssrRenderComponent(unref(KAlert), {
				tone: "info",
				title: unref(__)("Jobs run immediately")
			}, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(`${ssrInterpolate(unref(__)("The queue driver is \"sync\", so jobs run during the request that creates them. Switch to the database or Redis driver under Infrastructure for background processing and retries."))}`);
					else return [createTextVNode(toDisplayString(unref(__)("The queue driver is \"sync\", so jobs run during the request that creates them. Switch to the database or Redis driver under Infrastructure for background processing and retries.")), 1)];
				}),
				_: 1
			}, _parent));
			else if (unref(status).data.value && !workerFresh.value && pending.value > 0) _push(ssrRenderComponent(unref(KAlert), {
				tone: "danger",
				title: unref(__)("No worker is running")
			}, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(`${ssrInterpolate(unref(__)("Jobs are waiting but no worker has been seen for 10 minutes. Start one with \"php bin/cms queue:work\", or let the scheduler process jobs."))}`);
					else return [createTextVNode(toDisplayString(unref(__)("Jobs are waiting but no worker has been seen for 10 minutes. Start one with \"php bin/cms queue:work\", or let the scheduler process jobs.")), 1)];
				}),
				_: 1
			}, _parent));
			else _push(`<!---->`);
			_push(`<div class="k-grid k-grid--3">`);
			_push(ssrRenderComponent(unref(KStat), {
				label: unref(__)("Waiting"),
				icon: "hourglass-medium",
				value: unref(status).data.value ? pending.value : "…"
			}, null, _parent));
			_push(ssrRenderComponent(unref(KStat), {
				label: unref(__)("Failed"),
				icon: "warning-octagon",
				value: unref(status).data.value ? unref(status).data.value.failed : "…",
				tone: unref(status).data.value?.failed ? "warning" : "neutral"
			}, null, _parent));
			_push(ssrRenderComponent(unref(KStat), {
				label: unref(__)("Worker"),
				icon: "cpu",
				value: worker.value ? workerFresh.value ? unref(__)("Active") : unref(__)("Idle") : unref(__)("Never seen"),
				tone: workerFresh.value ? "success" : "neutral"
			}, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						if (worker.value) _push(`<!--[-->${ssrInterpolate(unref(__)("Last seen {when} on {host}", {
							when: unref(formatRelative)(worker.value.seen_at),
							host: worker.value.host ?? "?"
						}))}<!--]-->`);
						else _push(`<!---->`);
					} else return [worker.value ? (openBlock(), createBlock(Fragment, { key: 0 }, [createTextVNode(toDisplayString(unref(__)("Last seen {when} on {host}", {
						when: unref(formatRelative)(worker.value.seen_at),
						host: worker.value.host ?? "?"
					})), 1)], 64)) : createCommentVNode("v-if", true)];
				}),
				_: 1
			}, _parent));
			_push(`</div>`);
			_push(ssrRenderComponent(unref(KCard), {
				title: unref(__)("Queues"),
				flush: ""
			}, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(ssrRenderComponent(unref(KTable), {
						columns: [
							{
								key: "name",
								label: unref(__)("Queue")
							},
							{
								key: "pending",
								label: unref(__)("Waiting"),
								align: "end"
							},
							{
								key: "delayed",
								label: unref(__)("Delayed"),
								align: "end"
							},
							{
								key: "reserved",
								label: unref(__)("Running"),
								align: "end"
							},
							{
								key: "oldest",
								label: unref(__)("Oldest waiting"),
								align: "end"
							}
						],
						rows: unref(status).data.value?.queues ?? [],
						"row-key": "name",
						loading: unref(status).loading.value,
						empty: unref(__)("No jobs are waiting.")
					}, {
						"cell-name": withCtx(({ row }, _push, _parent, _scopeId) => {
							if (_push) _push(`<code${_scopeId}>${ssrInterpolate(row.name)}</code>`);
							else return [createVNode("code", null, toDisplayString(row.name), 1)];
						}),
						"cell-oldest": withCtx(({ row }, _push, _parent, _scopeId) => {
							if (_push) _push(ssrRenderComponent(unref(KTime), { value: row.oldest }, null, _parent, _scopeId));
							else return [createVNode(unref(KTime), { value: row.oldest }, null, 8, ["value"])];
						}),
						_: 1
					}, _parent, _scopeId));
					else return [createVNode(unref(KTable), {
						columns: [
							{
								key: "name",
								label: unref(__)("Queue")
							},
							{
								key: "pending",
								label: unref(__)("Waiting"),
								align: "end"
							},
							{
								key: "delayed",
								label: unref(__)("Delayed"),
								align: "end"
							},
							{
								key: "reserved",
								label: unref(__)("Running"),
								align: "end"
							},
							{
								key: "oldest",
								label: unref(__)("Oldest waiting"),
								align: "end"
							}
						],
						rows: unref(status).data.value?.queues ?? [],
						"row-key": "name",
						loading: unref(status).loading.value,
						empty: unref(__)("No jobs are waiting.")
					}, {
						"cell-name": withCtx(({ row }) => [createVNode("code", null, toDisplayString(row.name), 1)]),
						"cell-oldest": withCtx(({ row }) => [createVNode(unref(KTime), { value: row.oldest }, null, 8, ["value"])]),
						_: 1
					}, 8, [
						"columns",
						"rows",
						"loading",
						"empty"
					])];
				}),
				_: 1
			}, _parent));
			if (batches.value.length) _push(ssrRenderComponent(unref(KCard), {
				title: unref(__)("Batches"),
				description: unref(__)("Groups of jobs started together, such as an import or regenerating images."),
				flush: ""
			}, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(ssrRenderComponent(unref(KTable), {
						columns: [
							{
								key: "name",
								label: unref(__)("Batch")
							},
							{
								key: "progress",
								label: unref(__)("Progress")
							},
							{
								key: "status",
								label: unref(__)("Status")
							},
							{
								key: "actions",
								label: "",
								align: "end",
								width: "1%"
							}
						],
						rows: batches.value,
						empty: unref(__)("No batches.")
					}, {
						"cell-name": withCtx(({ row }, _push, _parent, _scopeId) => {
							if (_push) {
								_push(`<strong${_scopeId}>${ssrInterpolate(row.name)}</strong><div class="k-muted k-small"${_scopeId}>${ssrInterpolate(row.owner)} · `);
								_push(ssrRenderComponent(unref(KTime), { value: row.created_at }, null, _parent, _scopeId));
								_push(`</div>`);
							} else return [createVNode("strong", null, toDisplayString(row.name), 1), createVNode("div", { class: "k-muted k-small" }, [createTextVNode(toDisplayString(row.owner) + " · ", 1), createVNode(unref(KTime), { value: row.created_at }, null, 8, ["value"])])];
						}),
						"cell-progress": withCtx(({ row }, _push, _parent, _scopeId) => {
							if (_push) {
								_push(`<div class="k-meter" role="progressbar"${ssrRenderAttr("aria-valuenow", row.progress)} aria-valuemin="0" aria-valuemax="100"${ssrRenderAttr("aria-label", unref(__)("{name} progress", { name: row.name }))}${_scopeId}><span class="k-meter__done" style="${ssrRenderStyle({ width: `${(row.processed - row.failed) / Math.max(1, row.total) * 100}%` })}"${_scopeId}></span><span class="k-meter__failed" style="${ssrRenderStyle({ width: `${row.failed / Math.max(1, row.total) * 100}%` })}"${_scopeId}></span></div><div class="k-muted k-small k-num"${_scopeId}>${ssrInterpolate(unref(__)("{done} of {total}", {
									done: row.processed,
									total: row.total
								}))}`);
								if (row.failed) _push(`<!--[--> · ${ssrInterpolate(unref(__)("{n} failed", { n: row.failed }))}<!--]-->`);
								else _push(`<!---->`);
								_push(`</div>`);
							} else return [createVNode("div", {
								class: "k-meter",
								role: "progressbar",
								"aria-valuenow": row.progress,
								"aria-valuemin": "0",
								"aria-valuemax": "100",
								"aria-label": unref(__)("{name} progress", { name: row.name })
							}, [createVNode("span", {
								class: "k-meter__done",
								style: { width: `${(row.processed - row.failed) / Math.max(1, row.total) * 100}%` }
							}, null, 4), createVNode("span", {
								class: "k-meter__failed",
								style: { width: `${row.failed / Math.max(1, row.total) * 100}%` }
							}, null, 4)], 8, ["aria-valuenow", "aria-label"]), createVNode("div", { class: "k-muted k-small k-num" }, [createTextVNode(toDisplayString(unref(__)("{done} of {total}", {
								done: row.processed,
								total: row.total
							})), 1), row.failed ? (openBlock(), createBlock(Fragment, { key: 0 }, [createTextVNode(" · " + toDisplayString(unref(__)("{n} failed", { n: row.failed })), 1)], 64)) : createCommentVNode("v-if", true)])];
						}),
						"cell-status": withCtx(({ row }, _push, _parent, _scopeId) => {
							if (_push) _push(ssrRenderComponent(unref(KBadge), { tone: (BATCH_STATUS[row.status] ?? ["neutral"])[0] }, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate((BATCH_STATUS[row.status] ?? [null, row.status])[1])}`);
									else return [createTextVNode(toDisplayString((BATCH_STATUS[row.status] ?? [null, row.status])[1]), 1)];
								}),
								_: 2
							}, _parent, _scopeId));
							else return [createVNode(unref(KBadge), { tone: (BATCH_STATUS[row.status] ?? ["neutral"])[0] }, {
								default: withCtx(() => [createTextVNode(toDisplayString((BATCH_STATUS[row.status] ?? [null, row.status])[1]), 1)]),
								_: 2
							}, 1032, ["tone"])];
						}),
						"cell-actions": withCtx(({ row }, _push, _parent, _scopeId) => {
							if (_push) {
								if (row.status === "running") _push(ssrRenderComponent(unref(KButton), {
									size: "sm",
									variant: "ghost",
									icon: "stop-circle",
									onClick: ($event) => cancelBatch(row)
								}, {
									default: withCtx((_, _push, _parent, _scopeId) => {
										if (_push) _push(`${ssrInterpolate(unref(__)("Stop"))}`);
										else return [createTextVNode(toDisplayString(unref(__)("Stop")), 1)];
									}),
									_: 2
								}, _parent, _scopeId));
								else _push(`<span class="k-sr-only"${_scopeId}>${ssrInterpolate(unref(__)("No actions"))}</span>`);
							} else return [row.status === "running" ? (openBlock(), createBlock(unref(KButton), {
								key: 0,
								size: "sm",
								variant: "ghost",
								icon: "stop-circle",
								onClick: ($event) => cancelBatch(row)
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Stop")), 1)]),
								_: 1
							}, 8, ["onClick"])) : (openBlock(), createBlock("span", {
								key: 1,
								class: "k-sr-only"
							}, toDisplayString(unref(__)("No actions")), 1))];
						}),
						_: 1
					}, _parent, _scopeId));
					else return [createVNode(unref(KTable), {
						columns: [
							{
								key: "name",
								label: unref(__)("Batch")
							},
							{
								key: "progress",
								label: unref(__)("Progress")
							},
							{
								key: "status",
								label: unref(__)("Status")
							},
							{
								key: "actions",
								label: "",
								align: "end",
								width: "1%"
							}
						],
						rows: batches.value,
						empty: unref(__)("No batches.")
					}, {
						"cell-name": withCtx(({ row }) => [createVNode("strong", null, toDisplayString(row.name), 1), createVNode("div", { class: "k-muted k-small" }, [createTextVNode(toDisplayString(row.owner) + " · ", 1), createVNode(unref(KTime), { value: row.created_at }, null, 8, ["value"])])]),
						"cell-progress": withCtx(({ row }) => [createVNode("div", {
							class: "k-meter",
							role: "progressbar",
							"aria-valuenow": row.progress,
							"aria-valuemin": "0",
							"aria-valuemax": "100",
							"aria-label": unref(__)("{name} progress", { name: row.name })
						}, [createVNode("span", {
							class: "k-meter__done",
							style: { width: `${(row.processed - row.failed) / Math.max(1, row.total) * 100}%` }
						}, null, 4), createVNode("span", {
							class: "k-meter__failed",
							style: { width: `${row.failed / Math.max(1, row.total) * 100}%` }
						}, null, 4)], 8, ["aria-valuenow", "aria-label"]), createVNode("div", { class: "k-muted k-small k-num" }, [createTextVNode(toDisplayString(unref(__)("{done} of {total}", {
							done: row.processed,
							total: row.total
						})), 1), row.failed ? (openBlock(), createBlock(Fragment, { key: 0 }, [createTextVNode(" · " + toDisplayString(unref(__)("{n} failed", { n: row.failed })), 1)], 64)) : createCommentVNode("v-if", true)])]),
						"cell-status": withCtx(({ row }) => [createVNode(unref(KBadge), { tone: (BATCH_STATUS[row.status] ?? ["neutral"])[0] }, {
							default: withCtx(() => [createTextVNode(toDisplayString((BATCH_STATUS[row.status] ?? [null, row.status])[1]), 1)]),
							_: 2
						}, 1032, ["tone"])]),
						"cell-actions": withCtx(({ row }) => [row.status === "running" ? (openBlock(), createBlock(unref(KButton), {
							key: 0,
							size: "sm",
							variant: "ghost",
							icon: "stop-circle",
							onClick: ($event) => cancelBatch(row)
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Stop")), 1)]),
							_: 1
						}, 8, ["onClick"])) : (openBlock(), createBlock("span", {
							key: 1,
							class: "k-sr-only"
						}, toDisplayString(unref(__)("No actions")), 1))]),
						_: 1
					}, 8, [
						"columns",
						"rows",
						"empty"
					])];
				}),
				_: 1
			}, _parent));
			else _push(`<!---->`);
			_push(ssrRenderComponent(unref(KCard), {
				title: unref(__)("Failed jobs"),
				description: unref(__)("Jobs that used up their retries. Fix the cause, then retry them."),
				flush: ""
			}, createSlots({
				actions: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						if (total.value) _push(ssrRenderComponent(unref(KButton), {
							size: "sm",
							icon: "arrows-clockwise",
							disabled: unref(status).data.value?.sync,
							onClick: ($event) => retry("all")
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Retry all"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Retry all")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						else _push(`<!---->`);
						if (total.value) _push(ssrRenderComponent(unref(KButton), {
							size: "sm",
							variant: "danger",
							icon: "trash",
							onClick: ($event) => forget("all")
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Delete all"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Delete all")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						else _push(`<!---->`);
					} else return [total.value ? (openBlock(), createBlock(unref(KButton), {
						key: 0,
						size: "sm",
						icon: "arrows-clockwise",
						disabled: unref(status).data.value?.sync,
						onClick: ($event) => retry("all")
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Retry all")), 1)]),
						_: 1
					}, 8, ["disabled", "onClick"])) : createCommentVNode("v-if", true), total.value ? (openBlock(), createBlock(unref(KButton), {
						key: 1,
						size: "sm",
						variant: "danger",
						icon: "trash",
						onClick: ($event) => forget("all")
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Delete all")), 1)]),
						_: 1
					}, 8, ["onClick"])) : createCommentVNode("v-if", true)];
				}),
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(ssrRenderComponent(unref(KTable), {
						columns: [
							{
								key: "job",
								label: unref(__)("Job")
							},
							{
								key: "error",
								label: unref(__)("Error")
							},
							{
								key: "failed_at",
								label: unref(__)("Failed")
							},
							{
								key: "actions",
								label: "",
								align: "end",
								width: "1%"
							}
						],
						rows: failed.value,
						loading: loadingFailed.value,
						empty: unref(__)("No failed jobs.")
					}, {
						"cell-job": withCtx(({ row }, _push, _parent, _scopeId) => {
							if (_push) _push(`<strong${_scopeId}>${ssrInterpolate(shortClass(row.job))}</strong><div class="k-muted k-small"${_scopeId}><code${_scopeId}>${ssrInterpolate(row.queue)}</code> · ${ssrInterpolate(row.owner)} · ${ssrInterpolate(unref(__)("{n} attempts", { n: row.attempts }))}</div>`);
							else return [createVNode("strong", null, toDisplayString(shortClass(row.job)), 1), createVNode("div", { class: "k-muted k-small" }, [createVNode("code", null, toDisplayString(row.queue), 1), createTextVNode(" · " + toDisplayString(row.owner) + " · " + toDisplayString(unref(__)("{n} attempts", { n: row.attempts })), 1)])];
						}),
						"cell-error": withCtx(({ row }, _push, _parent, _scopeId) => {
							if (_push) _push(`<button type="button" class="k-link-btn k-truncate"${_scopeId}>${ssrInterpolate(row.error.split("\n")[0])}</button>`);
							else return [createVNode("button", {
								type: "button",
								class: "k-link-btn k-truncate",
								onClick: ($event) => shown.value = row
							}, toDisplayString(row.error.split("\n")[0]), 9, ["onClick"])];
						}),
						"cell-failed_at": withCtx(({ row }, _push, _parent, _scopeId) => {
							if (_push) _push(ssrRenderComponent(unref(KTime), { value: row.failed_at }, null, _parent, _scopeId));
							else return [createVNode(unref(KTime), { value: row.failed_at }, null, 8, ["value"])];
						}),
						"cell-actions": withCtx(({ row }, _push, _parent, _scopeId) => {
							if (_push) {
								_push(`<div class="k-row-actions"${_scopeId}>`);
								_push(ssrRenderComponent(unref(KButton), {
									size: "sm",
									variant: "ghost",
									icon: "arrows-clockwise",
									disabled: unref(status).data.value?.sync,
									"aria-label": unref(__)("Retry"),
									onClick: ($event) => retry(row.id)
								}, null, _parent, _scopeId));
								_push(ssrRenderComponent(unref(KButton), {
									size: "sm",
									variant: "ghost",
									icon: "trash",
									"aria-label": unref(__)("Delete"),
									onClick: ($event) => forget(row.id)
								}, null, _parent, _scopeId));
								_push(`</div>`);
							} else return [createVNode("div", { class: "k-row-actions" }, [createVNode(unref(KButton), {
								size: "sm",
								variant: "ghost",
								icon: "arrows-clockwise",
								disabled: unref(status).data.value?.sync,
								"aria-label": unref(__)("Retry"),
								onClick: ($event) => retry(row.id)
							}, null, 8, [
								"disabled",
								"aria-label",
								"onClick"
							]), createVNode(unref(KButton), {
								size: "sm",
								variant: "ghost",
								icon: "trash",
								"aria-label": unref(__)("Delete"),
								onClick: ($event) => forget(row.id)
							}, null, 8, ["aria-label", "onClick"])])];
						}),
						_: 1
					}, _parent, _scopeId));
					else return [createVNode(unref(KTable), {
						columns: [
							{
								key: "job",
								label: unref(__)("Job")
							},
							{
								key: "error",
								label: unref(__)("Error")
							},
							{
								key: "failed_at",
								label: unref(__)("Failed")
							},
							{
								key: "actions",
								label: "",
								align: "end",
								width: "1%"
							}
						],
						rows: failed.value,
						loading: loadingFailed.value,
						empty: unref(__)("No failed jobs.")
					}, {
						"cell-job": withCtx(({ row }) => [createVNode("strong", null, toDisplayString(shortClass(row.job)), 1), createVNode("div", { class: "k-muted k-small" }, [createVNode("code", null, toDisplayString(row.queue), 1), createTextVNode(" · " + toDisplayString(row.owner) + " · " + toDisplayString(unref(__)("{n} attempts", { n: row.attempts })), 1)])]),
						"cell-error": withCtx(({ row }) => [createVNode("button", {
							type: "button",
							class: "k-link-btn k-truncate",
							onClick: ($event) => shown.value = row
						}, toDisplayString(row.error.split("\n")[0]), 9, ["onClick"])]),
						"cell-failed_at": withCtx(({ row }) => [createVNode(unref(KTime), { value: row.failed_at }, null, 8, ["value"])]),
						"cell-actions": withCtx(({ row }) => [createVNode("div", { class: "k-row-actions" }, [createVNode(unref(KButton), {
							size: "sm",
							variant: "ghost",
							icon: "arrows-clockwise",
							disabled: unref(status).data.value?.sync,
							"aria-label": unref(__)("Retry"),
							onClick: ($event) => retry(row.id)
						}, null, 8, [
							"disabled",
							"aria-label",
							"onClick"
						]), createVNode(unref(KButton), {
							size: "sm",
							variant: "ghost",
							icon: "trash",
							"aria-label": unref(__)("Delete"),
							onClick: ($event) => forget(row.id)
						}, null, 8, ["aria-label", "onClick"])])]),
						_: 1
					}, 8, [
						"columns",
						"rows",
						"loading",
						"empty"
					])];
				}),
				_: 2
			}, [nextBefore.value ? {
				name: "footer",
				fn: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(ssrRenderComponent(unref(KButton), {
						size: "sm",
						loading: loadingFailed.value,
						onClick: ($event) => loadFailed(true)
					}, {
						default: withCtx((_, _push, _parent, _scopeId) => {
							if (_push) _push(`${ssrInterpolate(unref(__)("Load more"))}`);
							else return [createTextVNode(toDisplayString(unref(__)("Load more")), 1)];
						}),
						_: 1
					}, _parent, _scopeId));
					else return [createVNode(unref(KButton), {
						size: "sm",
						loading: loadingFailed.value,
						onClick: ($event) => loadFailed(true)
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Load more")), 1)]),
						_: 1
					}, 8, ["loading", "onClick"])];
				}),
				key: "0"
			} : void 0]), _parent));
			_push(ssrRenderComponent(unref(KModal), {
				open: !!shown.value,
				title: shown.value ? shortClass(shown.value.job) : "",
				size: "lg",
				onClose: ($event) => shown.value = null
			}, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						if (shown.value) {
							_push(`<!--[--><p${_scopeId}>`);
							_push(ssrRenderComponent(unref(KBadge), null, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate(shown.value.queue)}`);
									else return [createTextVNode(toDisplayString(shown.value.queue), 1)];
								}),
								_: 1
							}, _parent, _scopeId));
							_push(` `);
							_push(ssrRenderComponent(unref(KBadge), null, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate(shown.value.owner)}`);
									else return [createTextVNode(toDisplayString(shown.value.owner), 1)];
								}),
								_: 1
							}, _parent, _scopeId));
							_push(` `);
							_push(ssrRenderComponent(unref(KTime), { value: shown.value.failed_at }, null, _parent, _scopeId));
							_push(`</p><h3 class="k-subheading"${_scopeId}>${ssrInterpolate(unref(__)("Arguments"))}</h3><pre class="k-pre"${_scopeId}>${ssrInterpolate(JSON.stringify(shown.value.args, null, 2))}</pre><h3 class="k-subheading"${_scopeId}>${ssrInterpolate(unref(__)("Error"))}</h3><pre class="k-pre"${_scopeId}>${ssrInterpolate(shown.value.error)}</pre><!--]-->`);
						} else _push(`<!---->`);
					} else return [shown.value ? (openBlock(), createBlock(Fragment, { key: 0 }, [
						createVNode("p", null, [
							createVNode(unref(KBadge), null, {
								default: withCtx(() => [createTextVNode(toDisplayString(shown.value.queue), 1)]),
								_: 1
							}),
							createTextVNode(),
							createVNode(unref(KBadge), null, {
								default: withCtx(() => [createTextVNode(toDisplayString(shown.value.owner), 1)]),
								_: 1
							}),
							createTextVNode(),
							createVNode(unref(KTime), { value: shown.value.failed_at }, null, 8, ["value"])
						]),
						createVNode("h3", { class: "k-subheading" }, toDisplayString(unref(__)("Arguments")), 1),
						createVNode("pre", { class: "k-pre" }, toDisplayString(JSON.stringify(shown.value.args, null, 2)), 1),
						createVNode("h3", { class: "k-subheading" }, toDisplayString(unref(__)("Error")), 1),
						createVNode("pre", { class: "k-pre" }, toDisplayString(shown.value.error), 1)
					], 64)) : createCommentVNode("v-if", true)];
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
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/admin/system/Queue.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export { _sfc_main as default };
