import { t as _plugin_vue_export_helper_default } from "./_plugin-vue_export-helper-DjkACiAr.mjs";
import { computed, createBlock, createCommentVNode, createTextVNode, createVNode, mergeProps, openBlock, ref, toDisplayString, unref, useSSRContext, withCtx } from "vue";
import { ssrInterpolate, ssrRenderComponent } from "vue/server-renderer";
import { __, formatDateTime, notify, useApi } from "@kestello/sdk";
import { KAlert, KButton } from "@kestello/ui";
// core/resources/js/components/updates/StoppedUpdate.vue
/**
* An update that stopped halfway (PHP was stopped by the host, the server
* restarted). The site puts it back by itself on the next request; this
* shows up when that did not work three times, with a way to try again,
* or when an update has been running for unusually long (most likely its
* check outlived an updater the server stopped: nothing to try until it ends).
*/
var _sfc_main = {
	__name: "StoppedUpdate",
	__ssrInlineRender: true,
	props: {
		/** stopped_update from GET /updates or GET /plugins */
		stopped: {
			type: Object,
			required: true
		},
		/** Whether this person may ask for another try (core.update). */
		canRetry: {
			type: Boolean,
			default: true
		}
	},
	setup(__props) {
		const props = __props;
		const api = useApi();
		const busy = ref(false);
		const what = computed(() => props.stopped.name || (props.stopped.owner === "core" ? "Kestello" : props.stopped.owner.replace(/^(plugin|theme):/, "")));
		const describe = computed(() => props.stopped.running ? __("The update of {name} from {from} to {to} started {date} and is still running on the server.", {
			name: what.value,
			from: props.stopped.from,
			to: props.stopped.to,
			date: formatDateTime(props.stopped.started_at)
		}) : props.stopped.kind === "switch-back" ? __("Switching {name} back from {to} to {from} started {date} and did not finish.", {
			name: what.value,
			from: props.stopped.from,
			to: props.stopped.to,
			date: formatDateTime(props.stopped.started_at)
		}) : __("The update of {name} from {from} to {to} started {date} and stopped before it finished.", {
			name: what.value,
			from: props.stopped.from,
			to: props.stopped.to,
			date: formatDateTime(props.stopped.started_at)
		}));
		async function retry() {
			busy.value = true;
			try {
				await api.post("/updates/recover");
				window.location.reload();
			} catch (e) {
				notify.error(e);
				busy.value = false;
			}
		}
		return (_ctx, _push, _parent, _attrs) => {
			if (__props.stopped.running) _push(ssrRenderComponent(unref(KAlert), mergeProps({
				tone: "warning",
				title: unref(__)("An update is taking unusually long")
			}, _attrs), {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(`<p data-v-993522bc${_scopeId}>${ssrInterpolate(describe.value)}</p><p data-v-993522bc${_scopeId}>${ssrInterpolate(unref(__)("Visitors see the maintenance page until it ends. When the server stops an update, its last check can keep running for a few minutes; the site puts everything back as soon as it ends. If this message is still here in an hour, ask your host to restart PHP for this site."))}</p>`);
					else return [createVNode("p", null, toDisplayString(describe.value), 1), createVNode("p", null, toDisplayString(unref(__)("Visitors see the maintenance page until it ends. When the server stops an update, its last check can keep running for a few minutes; the site puts everything back as soon as it ends. If this message is still here in an hour, ask your host to restart PHP for this site.")), 1)];
				}),
				_: 1
			}, _parent));
			else _push(ssrRenderComponent(unref(KAlert), mergeProps({
				tone: "danger",
				title: __props.stopped.gave_up ? unref(__)("An update stopped halfway and was not put back") : unref(__)("An update stopped halfway")
			}, _attrs), {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(`<p data-v-993522bc${_scopeId}>${ssrInterpolate(describe.value)}</p>`);
						if (__props.stopped.gave_up) _push(`<p data-v-993522bc${_scopeId}>${ssrInterpolate(unref(__)("Putting {name} and the database back as they were failed {n} times. Visitors see the maintenance page until it works. The reasons are in the audit log.", {
							name: what.value,
							n: __props.stopped.attempts
						}))}</p>`);
						else _push(`<p data-v-993522bc${_scopeId}>${ssrInterpolate(unref(__)("The site is putting it back. Reload the page in a moment."))}</p>`);
						_push(`<p class="k-small" data-v-993522bc${_scopeId}>${ssrInterpolate(unref(__)("On the server, the same is done with: bin/cms update:recover"))}</p>`);
						if (__props.canRetry) {
							_push(`<div class="k-stopped-update__actions" data-v-993522bc${_scopeId}>`);
							_push(ssrRenderComponent(unref(KButton), {
								variant: "primary",
								icon: "arrow-counter-clockwise",
								loading: busy.value,
								onClick: retry
							}, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate(unref(__)("Try again"))}`);
									else return [createTextVNode(toDisplayString(unref(__)("Try again")), 1)];
								}),
								_: 1
							}, _parent, _scopeId));
							_push(`</div>`);
						} else _push(`<!---->`);
					} else return [
						createVNode("p", null, toDisplayString(describe.value), 1),
						__props.stopped.gave_up ? (openBlock(), createBlock("p", { key: 0 }, toDisplayString(unref(__)("Putting {name} and the database back as they were failed {n} times. Visitors see the maintenance page until it works. The reasons are in the audit log.", {
							name: what.value,
							n: __props.stopped.attempts
						})), 1)) : (openBlock(), createBlock("p", { key: 1 }, toDisplayString(unref(__)("The site is putting it back. Reload the page in a moment.")), 1)),
						createVNode("p", { class: "k-small" }, toDisplayString(unref(__)("On the server, the same is done with: bin/cms update:recover")), 1),
						__props.canRetry ? (openBlock(), createBlock("div", {
							key: 2,
							class: "k-stopped-update__actions"
						}, [createVNode(unref(KButton), {
							variant: "primary",
							icon: "arrow-counter-clockwise",
							loading: busy.value,
							onClick: retry
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Try again")), 1)]),
							_: 1
						}, 8, ["loading"])])) : createCommentVNode("v-if", true)
					];
				}),
				_: 1
			}, _parent));
		};
	}
};
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/components/updates/StoppedUpdate.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
var StoppedUpdate_default = /*#__PURE__*/ _plugin_vue_export_helper_default(_sfc_main, [["__scopeId", "data-v-993522bc"]]);
export { StoppedUpdate_default as t };
