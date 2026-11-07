import { t as _plugin_vue_export_helper_default } from "../../chunks/_plugin-vue_export-helper-DjkACiAr.mjs";
import { Fragment, computed, createBlock, createTextVNode, createVNode, mergeProps, openBlock, resolveDynamicComponent, toDisplayString, unref, useSSRContext, withCtx } from "vue";
import { ssrInterpolate, ssrRenderAttr, ssrRenderComponent, ssrRenderVNode } from "vue/server-renderer";
import { KImage, Link } from "@kestello/app";
// core/resources/js/pages/blocks/EntryField.vue
var _sfc_main = {
	__name: "EntryField",
	__ssrInlineRender: true,
	props: {
		field: {
			type: String,
			default: "title"
		},
		custom: {
			type: String,
			default: ""
		},
		tag: {
			type: String,
			default: "p"
		},
		link: Boolean,
		kind: {
			type: String,
			default: "text"
		},
		value: {
			type: [
				String,
				Number,
				Object
			],
			default: null
		},
		url: {
			type: String,
			default: null
		}
	},
	setup(__props) {
		const props = __props;
		const tags = [
			"p",
			"h2",
			"h3",
			"h4",
			"span",
			"div"
		];
		const el = computed(() => props.kind === "media" ? "div" : tags.includes(props.tag) ? props.tag : "p");
		const date = computed(() => props.kind === "date" && props.value ? new Intl.DateTimeFormat(void 0, { dateStyle: "long" }).format(new Date(props.value)) : "");
		return (_ctx, _push, _parent, _attrs) => {
			if (__props.value !== null && __props.value !== "") ssrRenderVNode(_push, createVNode(resolveDynamicComponent(el.value), mergeProps({ class: ["k-b-field", `k-b-field--${__props.kind}`] }, _attrs), {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) ssrRenderVNode(_push, createVNode(resolveDynamicComponent(__props.url ? unref(Link) : "span"), {
						href: __props.url ?? void 0,
						class: "k-b-field__inner"
					}, {
						default: withCtx((_, _push, _parent, _scopeId) => {
							if (_push) {
								if (__props.kind === "media") _push(ssrRenderComponent(unref(KImage), {
									media: __props.value,
									size: "medium",
									sizes: "(min-width: 40rem) 24rem, 100vw"
								}, null, _parent, _scopeId));
								else if (__props.kind === "date") _push(`<time${ssrRenderAttr("datetime", __props.value)} data-v-9019ed45${_scopeId}>${ssrInterpolate(date.value)}</time>`);
								else if (__props.kind === "html") _push(`<span class="prose" data-v-9019ed45${_scopeId}>${__props.value ?? ""}</span>`);
								else _push(`<!--[-->${ssrInterpolate(__props.value)}<!--]-->`);
							} else return [__props.kind === "media" ? (openBlock(), createBlock(unref(KImage), {
								key: 0,
								media: __props.value,
								size: "medium",
								sizes: "(min-width: 40rem) 24rem, 100vw"
							}, null, 8, ["media"])) : __props.kind === "date" ? (openBlock(), createBlock("time", {
								key: 1,
								datetime: __props.value
							}, toDisplayString(date.value), 9, ["datetime"])) : __props.kind === "html" ? (openBlock(), createBlock("span", {
								key: 2,
								class: "prose",
								innerHTML: __props.value
							}, null, 8, ["innerHTML"])) : (openBlock(), createBlock(Fragment, { key: 3 }, [createTextVNode(toDisplayString(__props.value), 1)], 64))];
						}),
						_: 1
					}), _parent, _scopeId);
					else return [(openBlock(), createBlock(resolveDynamicComponent(__props.url ? unref(Link) : "span"), {
						href: __props.url ?? void 0,
						class: "k-b-field__inner"
					}, {
						default: withCtx(() => [__props.kind === "media" ? (openBlock(), createBlock(unref(KImage), {
							key: 0,
							media: __props.value,
							size: "medium",
							sizes: "(min-width: 40rem) 24rem, 100vw"
						}, null, 8, ["media"])) : __props.kind === "date" ? (openBlock(), createBlock("time", {
							key: 1,
							datetime: __props.value
						}, toDisplayString(date.value), 9, ["datetime"])) : __props.kind === "html" ? (openBlock(), createBlock("span", {
							key: 2,
							class: "prose",
							innerHTML: __props.value
						}, null, 8, ["innerHTML"])) : (openBlock(), createBlock(Fragment, { key: 3 }, [createTextVNode(toDisplayString(__props.value), 1)], 64))]),
						_: 1
					}, 8, ["href"]))];
				}),
				_: 1
			}), _parent);
			else _push(`<!---->`);
		};
	}
};
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/blocks/EntryField.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
var EntryField_default = /*#__PURE__*/ _plugin_vue_export_helper_default(_sfc_main, [["__scopeId", "data-v-9019ed45"]]);
export { EntryField_default as default };
