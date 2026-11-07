import { t as _plugin_vue_export_helper_default } from "./_plugin-vue_export-helper-DjkACiAr.mjs";
import { Fragment, computed, createBlock, createCommentVNode, createTextVNode, createVNode, mergeModels, mergeProps, openBlock, toDisplayString, unref, useModel, useSSRContext, withCtx } from "vue";
import { ssrIncludeBooleanAttr, ssrInterpolate, ssrRenderAttrs, ssrRenderComponent, ssrRenderList } from "vue/server-renderer";
import { __, notify } from "@kestello/sdk";
import { KButton, KModal } from "@kestello/ui";
// core/resources/js/components/webhooks/EventPicker.vue
/** Checkboxes for the events a webhook sends, grouped as core and plugins declare them. */
var _sfc_main$1 = {
	__name: "EventPicker",
	__ssrInlineRender: true,
	props: /*@__PURE__*/ mergeModels({
		events: {
			type: Array,
			default: () => []
		},
		error: {
			type: String,
			default: ""
		}
	}, {
		"modelValue": {
			type: Array,
			default: () => []
		},
		"modelModifiers": {}
	}),
	emits: ["update:modelValue"],
	setup(__props) {
		const props = __props;
		const model = useModel(__props, "modelValue");
		const groups = computed(() => {
			const out = /* @__PURE__ */ new Map();
			for (const e of props.events) {
				if (e.name === "webhook.ping") continue;
				const g = e.group || __("Other");
				if (!out.has(g)) out.set(g, []);
				out.get(g).push(e);
			}
			return [...out.entries()];
		});
		const all = computed(() => model.value.includes("*"));
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<fieldset${ssrRenderAttrs(mergeProps({ class: "k-fieldset k-hook-events" }, _attrs))} data-v-e586cc85><legend class="k-field__label" data-v-e586cc85>${ssrInterpolate(unref(__)("Events"))}</legend>`);
			if (__props.error) _push(`<p class="k-field__error" data-v-e586cc85>${ssrInterpolate(__props.error)}</p>`);
			else _push(`<!---->`);
			_push(`<label class="k-option" data-v-e586cc85><input type="checkbox"${ssrIncludeBooleanAttr(all.value) ? " checked" : ""} data-v-e586cc85><span data-v-e586cc85><strong data-v-e586cc85>${ssrInterpolate(unref(__)("Everything"))}</strong> <span class="k-muted" data-v-e586cc85>${ssrInterpolate(unref(__)("including events added later by plugins"))}</span></span></label><!--[-->`);
			ssrRenderList(groups.value, ([group, items]) => {
				_push(`<div class="k-hook-events__group" data-v-e586cc85><p class="k-hook-events__name" data-v-e586cc85>${ssrInterpolate(group)}</p><!--[-->`);
				ssrRenderList(items, (e) => {
					_push(`<label class="k-option" data-v-e586cc85><input type="checkbox"${ssrIncludeBooleanAttr(all.value || model.value.includes(e.name)) ? " checked" : ""}${ssrIncludeBooleanAttr(all.value) ? " disabled" : ""} data-v-e586cc85><span data-v-e586cc85>${ssrInterpolate(e.label)} <code class="k-small" data-v-e586cc85>${ssrInterpolate(e.name)}</code></span></label>`);
				});
				_push(`<!--]--></div>`);
			});
			_push(`<!--]--></fieldset>`);
		};
	}
};
var _sfc_setup$1 = _sfc_main$1.setup;
_sfc_main$1.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/components/webhooks/EventPicker.vue");
	return _sfc_setup$1 ? _sfc_setup$1(props, ctx) : void 0;
};
var EventPicker_default = /*#__PURE__*/ _plugin_vue_export_helper_default(_sfc_main$1, [["__scopeId", "data-v-e586cc85"]]);
// core/resources/js/components/webhooks/SecretModal.vue
/** Shows a webhook secret once, after it is made or rotated. */
var _sfc_main = {
	__name: "SecretModal",
	__ssrInlineRender: true,
	props: {
		secret: {
			type: String,
			default: null
		},
		rotated: Boolean
	},
	emits: ["done"],
	setup(__props, { emit: __emit }) {
		const props = __props;
		const emit = __emit;
		function copy() {
			navigator.clipboard?.writeText(props.secret).then(() => notify.success(__("Copied.")), () => {});
		}
		return (_ctx, _push, _parent, _attrs) => {
			_push(ssrRenderComponent(unref(KModal), mergeProps({
				open: !!__props.secret,
				title: unref(__)("Copy the signing secret now"),
				dismissible: false,
				onClose: ($event) => emit("done")
			}, _attrs), {
				footer: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(ssrRenderComponent(unref(KButton), {
							icon: "clipboard",
							onClick: copy
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Copy"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Copy")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						_push(ssrRenderComponent(unref(KButton), {
							variant: "primary",
							onClick: ($event) => emit("done")
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("I have stored it"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("I have stored it")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
					} else return [createVNode(unref(KButton), {
						icon: "clipboard",
						onClick: copy
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Copy")), 1)]),
						_: 1
					}), createVNode(unref(KButton), {
						variant: "primary",
						onClick: ($event) => emit("done")
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("I have stored it")), 1)]),
						_: 1
					}, 8, ["onClick"])];
				}),
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						if (__props.secret) {
							_push(`<!--[--><p${_scopeId}>${ssrInterpolate(unref(__)("Give it to the receiving service so it can check that messages come from this site. This is the only time it is shown."))}</p><div class="k-secret k-secret--block"${_scopeId}><code${_scopeId}>${ssrInterpolate(__props.secret)}</code></div>`);
							if (__props.rotated) _push(`<p class="k-muted k-small"${_scopeId}>${ssrInterpolate(unref(__)("For the next 24 hours, messages are signed with the old secret too, so nothing is missed while you switch."))}</p>`);
							else _push(`<!---->`);
							_push(`<!--]-->`);
						} else _push(`<!---->`);
					} else return [__props.secret ? (openBlock(), createBlock(Fragment, { key: 0 }, [
						createVNode("p", null, toDisplayString(unref(__)("Give it to the receiving service so it can check that messages come from this site. This is the only time it is shown.")), 1),
						createVNode("div", { class: "k-secret k-secret--block" }, [createVNode("code", null, toDisplayString(__props.secret), 1)]),
						__props.rotated ? (openBlock(), createBlock("p", {
							key: 0,
							class: "k-muted k-small"
						}, toDisplayString(unref(__)("For the next 24 hours, messages are signed with the old secret too, so nothing is missed while you switch.")), 1)) : createCommentVNode("v-if", true)
					], 64)) : createCommentVNode("v-if", true)];
				}),
				_: 1
			}, _parent));
		};
	}
};
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/components/webhooks/SecretModal.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export { EventPicker_default as n, _sfc_main as t };
