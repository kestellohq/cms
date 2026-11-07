import { Fragment, computed, createBlock, createCommentVNode, createTextVNode, createVNode, openBlock, ref, toDisplayString, unref, useSSRContext, withCtx } from "vue";
import { ssrInterpolate, ssrRenderComponent } from "vue/server-renderer";
import { __, notify, useApi, useResource } from "@kestello/sdk";
import { KAlert, KBadge, KButton, KCard, KModal, KPageHeader, KStatusBadge, KSwitch, KTable, KTime } from "@kestello/ui";
// core/resources/js/pages/admin/system/Schedule.vue
var _sfc_main = /*@__PURE__*/ Object.assign({ layout: "Admin" }, {
	__name: "Schedule",
	__ssrInlineRender: true,
	setup(__props) {
		const api = useApi();
		const tasks = useResource("/system/schedule", { poll: 30 });
		const running = ref(null);
		const output = ref(null);
		const heartbeat = computed(() => tasks.meta.value?.heartbeat ?? null);
		const stale = computed(() => !heartbeat.value || Date.now() / 1e3 - heartbeat.value.at > 600);
		async function runNow(task) {
			running.value = task.name;
			try {
				const { data } = await api.post(`/system/schedule/${encodeURIComponent(task.name)}/run`, {});
				(data.status === "failed" ? notify.error : notify.success)(__("{name}: {status} in {ms} ms", {
					name: task.name,
					status: data.status,
					ms: data.duration_ms ?? 0
				}));
				await tasks.reload({ silent: true });
			} catch (e) {
				notify.error(e);
			} finally {
				running.value = null;
			}
		}
		async function toggle(task, enabled) {
			try {
				await api.patch(`/system/schedule/${encodeURIComponent(task.name)}`, { disabled: !enabled });
				notify.success(enabled ? __("{name} is enabled.", { name: task.name }) : __("{name} is paused.", { name: task.name }));
				await tasks.reload({ silent: true });
			} catch (e) {
				notify.error(e);
			}
		}
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<!--[-->`);
			_push(ssrRenderComponent(unref(KPageHeader), {
				title: unref(__)("Scheduled tasks"),
				description: unref(__)("Recurring work: cleanup, digests, backups and plugin tasks.")
			}, null, _parent));
			if (unref(tasks).data.value && stale.value) _push(ssrRenderComponent(unref(KAlert), {
				tone: "warning",
				title: heartbeat.value ? unref(__)("The scheduler is behind") : unref(__)("The scheduler has never run")
			}, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(`${ssrInterpolate(unref(__)("Add a cron job that runs every minute: * * * * * php bin/cms schedule:run. On hosting without cron, enable the web runner or call POST /system/cron from an external service."))} `);
						if (heartbeat.value) {
							_push(`<!--[-->${ssrInterpolate(unref(__)("Last run"))} `);
							_push(ssrRenderComponent(unref(KTime), { value: heartbeat.value.at }, null, _parent, _scopeId));
							_push(` (${ssrInterpolate(heartbeat.value.via)}).<!--]-->`);
						} else _push(`<!---->`);
					} else return [createTextVNode(toDisplayString(unref(__)("Add a cron job that runs every minute: * * * * * php bin/cms schedule:run. On hosting without cron, enable the web runner or call POST /system/cron from an external service.")) + " ", 1), heartbeat.value ? (openBlock(), createBlock(Fragment, { key: 0 }, [
						createTextVNode(toDisplayString(unref(__)("Last run")) + " ", 1),
						createVNode(unref(KTime), { value: heartbeat.value.at }, null, 8, ["value"]),
						createTextVNode(" (" + toDisplayString(heartbeat.value.via) + ").", 1)
					], 64)) : createCommentVNode("v-if", true)];
				}),
				_: 1
			}, _parent));
			else _push(`<!---->`);
			_push(ssrRenderComponent(unref(KCard), { flush: "" }, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(ssrRenderComponent(unref(KTable), {
						columns: [
							{
								key: "name",
								label: unref(__)("Task")
							},
							{
								key: "cron",
								label: unref(__)("Schedule")
							},
							{
								key: "next",
								label: unref(__)("Next run")
							},
							{
								key: "last",
								label: unref(__)("Last run")
							},
							{
								key: "enabled",
								label: unref(__)("Enabled")
							},
							{
								key: "actions",
								label: "",
								align: "end",
								width: "1%"
							}
						],
						rows: unref(tasks).data.value ?? [],
						"row-key": "name",
						loading: unref(tasks).loading.value,
						empty: unref(__)("No scheduled tasks.")
					}, {
						"cell-name": withCtx(({ row }, _push, _parent, _scopeId) => {
							if (_push) {
								_push(`<strong${_scopeId}>${ssrInterpolate(row.name)}</strong> `);
								if (row.owner !== "core") _push(ssrRenderComponent(unref(KBadge), { tone: "accent" }, {
									default: withCtx((_, _push, _parent, _scopeId) => {
										if (_push) _push(`${ssrInterpolate(row.owner)}`);
										else return [createTextVNode(toDisplayString(row.owner), 1)];
									}),
									_: 2
								}, _parent, _scopeId));
								else _push(`<!---->`);
								_push(`<div class="k-muted k-small"${_scopeId}>${ssrInterpolate(row.description)}</div>`);
							} else return [
								createVNode("strong", null, toDisplayString(row.name), 1),
								createTextVNode(),
								row.owner !== "core" ? (openBlock(), createBlock(unref(KBadge), {
									key: 0,
									tone: "accent"
								}, {
									default: withCtx(() => [createTextVNode(toDisplayString(row.owner), 1)]),
									_: 2
								}, 1024)) : createCommentVNode("v-if", true),
								createVNode("div", { class: "k-muted k-small" }, toDisplayString(row.description), 1)
							];
						}),
						"cell-cron": withCtx(({ row }, _push, _parent, _scopeId) => {
							if (_push) {
								_push(`<code${_scopeId}>${ssrInterpolate(row.cron)}</code>`);
								if (row.timezone) _push(`<div class="k-muted k-small"${_scopeId}>${ssrInterpolate(row.timezone)}</div>`);
								else _push(`<!---->`);
							} else return [createVNode("code", null, toDisplayString(row.cron), 1), row.timezone ? (openBlock(), createBlock("div", {
								key: 0,
								class: "k-muted k-small"
							}, toDisplayString(row.timezone), 1)) : createCommentVNode("v-if", true)];
						}),
						"cell-next": withCtx(({ row }, _push, _parent, _scopeId) => {
							if (_push) {
								if (!row.disabled) _push(ssrRenderComponent(unref(KTime), { value: row.next_run_at }, null, _parent, _scopeId));
								else _push(ssrRenderComponent(unref(KStatusBadge), { status: "disabled" }, null, _parent, _scopeId));
							} else return [!row.disabled ? (openBlock(), createBlock(unref(KTime), {
								key: 0,
								value: row.next_run_at
							}, null, 8, ["value"])) : (openBlock(), createBlock(unref(KStatusBadge), {
								key: 1,
								status: "disabled"
							}))];
						}),
						"cell-last": withCtx(({ row }, _push, _parent, _scopeId) => {
							if (_push) {
								if (row.last) {
									_push(`<!--[-->`);
									_push(ssrRenderComponent(unref(KStatusBadge), { status: row.last.status }, null, _parent, _scopeId));
									_push(` `);
									_push(ssrRenderComponent(unref(KTime), { value: row.last.started_at }, null, _parent, _scopeId));
									_push(`<div class="k-muted k-small"${_scopeId}>`);
									if (row.last.duration_ms !== null) _push(`<span${_scopeId}>${ssrInterpolate(row.last.duration_ms)} ms</span>`);
									else _push(`<!---->`);
									if (row.last.output) {
										_push(`<!--[-->`);
										if (row.last.duration_ms !== null) _push(`<span aria-hidden="true"${_scopeId}> · </span>`);
										else _push(`<!---->`);
										_push(`<button type="button" class="k-link-btn"${_scopeId}>${ssrInterpolate(unref(__)("Output"))}</button><!--]-->`);
									} else _push(`<!---->`);
									_push(`</div><!--]-->`);
								} else _push(`<span class="k-muted"${_scopeId}>${ssrInterpolate(unref(__)("Not yet"))}</span>`);
							} else return [row.last ? (openBlock(), createBlock(Fragment, { key: 0 }, [
								createVNode(unref(KStatusBadge), { status: row.last.status }, null, 8, ["status"]),
								createTextVNode(),
								createVNode(unref(KTime), { value: row.last.started_at }, null, 8, ["value"]),
								createVNode("div", { class: "k-muted k-small" }, [row.last.duration_ms !== null ? (openBlock(), createBlock("span", { key: 0 }, toDisplayString(row.last.duration_ms) + " ms", 1)) : createCommentVNode("v-if", true), row.last.output ? (openBlock(), createBlock(Fragment, { key: 1 }, [row.last.duration_ms !== null ? (openBlock(), createBlock("span", {
									key: 0,
									"aria-hidden": "true"
								}, " · ")) : createCommentVNode("v-if", true), createVNode("button", {
									type: "button",
									class: "k-link-btn",
									onClick: ($event) => output.value = row
								}, toDisplayString(unref(__)("Output")), 9, ["onClick"])], 64)) : createCommentVNode("v-if", true)])
							], 64)) : (openBlock(), createBlock("span", {
								key: 1,
								class: "k-muted"
							}, toDisplayString(unref(__)("Not yet")), 1))];
						}),
						"cell-enabled": withCtx(({ row }, _push, _parent, _scopeId) => {
							if (_push) _push(ssrRenderComponent(unref(KSwitch), {
								"model-value": !row.disabled,
								label: unref(__)("Enabled"),
								"hide-label": "",
								"onUpdate:modelValue": ($event) => toggle(row, $event)
							}, null, _parent, _scopeId));
							else return [createVNode(unref(KSwitch), {
								"model-value": !row.disabled,
								label: unref(__)("Enabled"),
								"hide-label": "",
								"onUpdate:modelValue": ($event) => toggle(row, $event)
							}, null, 8, [
								"model-value",
								"label",
								"onUpdate:modelValue"
							])];
						}),
						"cell-actions": withCtx(({ row }, _push, _parent, _scopeId) => {
							if (_push) _push(ssrRenderComponent(unref(KButton), {
								size: "sm",
								icon: "play",
								loading: running.value === row.name,
								disabled: running.value !== null,
								onClick: ($event) => runNow(row)
							}, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate(unref(__)("Run now"))}`);
									else return [createTextVNode(toDisplayString(unref(__)("Run now")), 1)];
								}),
								_: 2
							}, _parent, _scopeId));
							else return [createVNode(unref(KButton), {
								size: "sm",
								icon: "play",
								loading: running.value === row.name,
								disabled: running.value !== null,
								onClick: ($event) => runNow(row)
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Run now")), 1)]),
								_: 1
							}, 8, [
								"loading",
								"disabled",
								"onClick"
							])];
						}),
						_: 1
					}, _parent, _scopeId));
					else return [createVNode(unref(KTable), {
						columns: [
							{
								key: "name",
								label: unref(__)("Task")
							},
							{
								key: "cron",
								label: unref(__)("Schedule")
							},
							{
								key: "next",
								label: unref(__)("Next run")
							},
							{
								key: "last",
								label: unref(__)("Last run")
							},
							{
								key: "enabled",
								label: unref(__)("Enabled")
							},
							{
								key: "actions",
								label: "",
								align: "end",
								width: "1%"
							}
						],
						rows: unref(tasks).data.value ?? [],
						"row-key": "name",
						loading: unref(tasks).loading.value,
						empty: unref(__)("No scheduled tasks.")
					}, {
						"cell-name": withCtx(({ row }) => [
							createVNode("strong", null, toDisplayString(row.name), 1),
							createTextVNode(),
							row.owner !== "core" ? (openBlock(), createBlock(unref(KBadge), {
								key: 0,
								tone: "accent"
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(row.owner), 1)]),
								_: 2
							}, 1024)) : createCommentVNode("v-if", true),
							createVNode("div", { class: "k-muted k-small" }, toDisplayString(row.description), 1)
						]),
						"cell-cron": withCtx(({ row }) => [createVNode("code", null, toDisplayString(row.cron), 1), row.timezone ? (openBlock(), createBlock("div", {
							key: 0,
							class: "k-muted k-small"
						}, toDisplayString(row.timezone), 1)) : createCommentVNode("v-if", true)]),
						"cell-next": withCtx(({ row }) => [!row.disabled ? (openBlock(), createBlock(unref(KTime), {
							key: 0,
							value: row.next_run_at
						}, null, 8, ["value"])) : (openBlock(), createBlock(unref(KStatusBadge), {
							key: 1,
							status: "disabled"
						}))]),
						"cell-last": withCtx(({ row }) => [row.last ? (openBlock(), createBlock(Fragment, { key: 0 }, [
							createVNode(unref(KStatusBadge), { status: row.last.status }, null, 8, ["status"]),
							createTextVNode(),
							createVNode(unref(KTime), { value: row.last.started_at }, null, 8, ["value"]),
							createVNode("div", { class: "k-muted k-small" }, [row.last.duration_ms !== null ? (openBlock(), createBlock("span", { key: 0 }, toDisplayString(row.last.duration_ms) + " ms", 1)) : createCommentVNode("v-if", true), row.last.output ? (openBlock(), createBlock(Fragment, { key: 1 }, [row.last.duration_ms !== null ? (openBlock(), createBlock("span", {
								key: 0,
								"aria-hidden": "true"
							}, " · ")) : createCommentVNode("v-if", true), createVNode("button", {
								type: "button",
								class: "k-link-btn",
								onClick: ($event) => output.value = row
							}, toDisplayString(unref(__)("Output")), 9, ["onClick"])], 64)) : createCommentVNode("v-if", true)])
						], 64)) : (openBlock(), createBlock("span", {
							key: 1,
							class: "k-muted"
						}, toDisplayString(unref(__)("Not yet")), 1))]),
						"cell-enabled": withCtx(({ row }) => [createVNode(unref(KSwitch), {
							"model-value": !row.disabled,
							label: unref(__)("Enabled"),
							"hide-label": "",
							"onUpdate:modelValue": ($event) => toggle(row, $event)
						}, null, 8, [
							"model-value",
							"label",
							"onUpdate:modelValue"
						])]),
						"cell-actions": withCtx(({ row }) => [createVNode(unref(KButton), {
							size: "sm",
							icon: "play",
							loading: running.value === row.name,
							disabled: running.value !== null,
							onClick: ($event) => runNow(row)
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Run now")), 1)]),
							_: 1
						}, 8, [
							"loading",
							"disabled",
							"onClick"
						])]),
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
			_push(ssrRenderComponent(unref(KModal), {
				open: !!output.value,
				title: output.value ? unref(__)("Output of {name}", { name: output.value.name }) : "",
				size: "lg",
				onClose: ($event) => output.value = null
			}, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						if (output.value) _push(`<pre class="k-pre"${_scopeId}>${ssrInterpolate(output.value.last.output)}</pre>`);
						else _push(`<!---->`);
					} else return [output.value ? (openBlock(), createBlock("pre", {
						key: 0,
						class: "k-pre"
					}, toDisplayString(output.value.last.output), 1)) : createCommentVNode("v-if", true)];
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
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/admin/system/Schedule.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export { _sfc_main as default };
