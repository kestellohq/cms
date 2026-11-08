import { t as _plugin_vue_export_helper_default } from "./_plugin-vue_export-helper-DjkACiAr.mjs";
import { computed, createBlock, createCommentVNode, createTextVNode, createVNode, mergeProps, openBlock, ref, toDisplayString, unref, useSSRContext, withCtx } from "vue";
import { ssrInterpolate, ssrRenderComponent } from "vue/server-renderer";
import { __, formatDateTime, notify, useApi } from "@kestello/sdk";
import { KAlert, KButton } from "@kestello/ui";
// core/resources/js/components/updates/StoppedUpdate.vue
/**
* An update that stopped halfway (PHP was stopped by the host, the server
* restarted). The site puts it back by itself on the next request; this
* shows up when that did not work three times, with a way to try again.
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
		const what = computed(() => props.stopped.owner === "core" ? "Kestello" : props.stopped.owner.replace(/^(plugin|theme):/, ""));
		const describe = computed(() => props.stopped.kind === "switch-back" ? __("Switching {name} back from {to} to {from} started {date} and did not finish.", {
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
			_push(ssrRenderComponent(unref(KAlert), mergeProps({
				tone: "danger",
				title: __props.stopped.gave_up ? unref(__)("An update stopped halfway and was not put back") : unref(__)("An update stopped halfway")
			}, _attrs), {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(`<p data-v-e3f95a36${_scopeId}>${ssrInterpolate(describe.value)}</p>`);
						if (__props.stopped.gave_up) _push(`<p data-v-e3f95a36${_scopeId}>${ssrInterpolate(unref(__)("Putting {name} and the database back as they were failed {n} times. Visitors see the maintenance page until it works. The reasons are in the audit log.", {
							name: what.value,
							n: __props.stopped.attempts
						}))}</p>`);
						else _push(`<p data-v-e3f95a36${_scopeId}>${ssrInterpolate(unref(__)("The site is putting it back. Reload the page in a moment."))}</p>`);
						_push(`<p class="k-small" data-v-e3f95a36${_scopeId}>${ssrInterpolate(unref(__)("On the server, the same is done with: bin/cms update:recover"))}</p>`);
						if (__props.canRetry) {
							_push(`<div class="k-stopped-update__actions" data-v-e3f95a36${_scopeId}>`);
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
var StoppedUpdate_default = /*#__PURE__*/ _plugin_vue_export_helper_default(_sfc_main, [["__scopeId", "data-v-e3f95a36"]]);
// core/resources/js/components/updates/switch-back.js
/**
* Switching back after an update reloads the admin: the next request runs
* the old code and puts the database back, and the page has to come from
* that code too. What was switched back is kept in sessionStorage across
* the reload, so the page can say how it went.
*/
var STORAGE_KEY = "kestello.switchedBack";
/** What a page remembers before reloading: who was switched back, and to which version. */
function switchBackNote(owner, name, version) {
	return JSON.stringify({
		owner: String(owner),
		name: String(name),
		version: String(version)
	});
}
/** The note read back after the reload, or null when there is none (or it is not one of ours). */
function readSwitchBackNote(raw) {
	if (typeof raw !== "string") return null;
	try {
		const note = JSON.parse(raw);
		return note && typeof note === "object" && [
			note.owner,
			note.name,
			note.version
		].every((v) => typeof v === "string" && v.length > 0 && v.length <= 200) && /^(core|(plugin|theme):[a-z0-9]+(?:-[a-z0-9]+)*)$/.test(note.owner) ? {
			owner: note.owner,
			name: note.name,
			version: note.version
		} : null;
	} catch {
		return null;
	}
}
/**
* How it went, from what the page loaded after the reload.
*
* @param {{owner: string, version: string}} note
* @param {?string} running the version of note.owner the site runs now
* @param {?object} stopped the update that stopped halfway, if there is one
* @returns {'done' | 'stopped' | 'not-done'}
*/
function switchBackOutcome(note, running, stopped) {
	if (stopped && stopped.owner === note.owner) return "stopped";
	return running === note.version ? "done" : "not-done";
}
/** Remember, read once, forget: storage can be missing (private windows), and that only costs the message. */
function rememberSwitchBack(owner, name, version) {
	try {
		sessionStorage.setItem(STORAGE_KEY, switchBackNote(owner, name, version));
	} catch {}
}
function takeSwitchBack() {
	try {
		const raw = sessionStorage.getItem(STORAGE_KEY);
		sessionStorage.removeItem(STORAGE_KEY);
		return readSwitchBackNote(raw);
	} catch {
		return null;
	}
}
export { StoppedUpdate_default as i, switchBackOutcome as n, takeSwitchBack as r, rememberSwitchBack as t };
