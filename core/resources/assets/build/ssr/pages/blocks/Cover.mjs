import { t as _plugin_vue_export_helper_default } from "../../chunks/_plugin-vue_export-helper-DjkACiAr.mjs";
import { computed, createBlock, createCommentVNode, createVNode, mergeProps, openBlock, renderSlot, resolveDynamicComponent, unref, useSSRContext, withCtx } from "vue";
import { ssrRenderComponent, ssrRenderSlot, ssrRenderVNode } from "vue/server-renderer";
import { KImage } from "@kestello/app";
// core/resources/js/pages/blocks/Cover.vue
var _sfc_main = {
	__name: "Cover",
	__ssrInlineRender: true,
	props: {
		image: {
			type: Object,
			default: null
		},
		shade: {
			type: String,
			default: "dark"
		},
		strength: {
			type: [Number, String],
			default: 45
		},
		height: {
			type: String,
			default: "medium"
		},
		valign: {
			type: String,
			default: "center"
		},
		align: {
			type: String,
			default: "center"
		},
		tag: {
			type: String,
			default: "section"
		},
		width: {
			type: String,
			default: "full"
		}
	},
	setup(__props) {
		const props = __props;
		const pick = (value, allowed, fallback) => allowed.includes(value) ? value : fallback;
		const tag = computed(() => pick(props.tag, [
			"section",
			"header",
			"div"
		], "section"));
		const shade = computed(() => props.image?.url ? pick(props.shade, [
			"dark",
			"light",
			"none"
		], "dark") : "none");
		const level = computed(() => Math.min(90, Math.max(0, Number(props.strength) || 0)) / 100);
		const classes = computed(() => [
			`is-h-${pick(props.height, [
				"auto",
				"short",
				"medium",
				"tall",
				"screen"
			], "medium")}`,
			`is-v-${pick(props.valign, [
				"start",
				"center",
				"end"
			], "center")}`,
			`is-a-${pick(props.align, [
				"start",
				"center",
				"end"
			], "center")}`,
			`is-shade-${shade.value}`,
			props.image?.url ? "has-image" : "no-image"
		]);
		return (_ctx, _push, _parent, _attrs) => {
			ssrRenderVNode(_push, createVNode(resolveDynamicComponent(tag.value), mergeProps({
				class: ["k-b-cover", classes.value],
				"data-width": __props.width,
				style: { "--k-shade": level.value }
			}, _attrs), {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						if (__props.image?.url) _push(ssrRenderComponent(unref(KImage), {
							class: "k-b-cover__img",
							media: __props.image,
							size: "large",
							sizes: "100vw",
							alt: ""
						}, null, _parent, _scopeId));
						else _push(`<!---->`);
						if (shade.value !== "none") _push(`<span class="k-b-cover__shade" aria-hidden="true" data-v-9bfa8554${_scopeId}></span>`);
						else _push(`<!---->`);
						_push(`<div class="k-b-cover__inner k-b-stack" data-v-9bfa8554${_scopeId}>`);
						ssrRenderSlot(_ctx.$slots, "default", {}, null, _push, _parent, _scopeId);
						_push(`</div>`);
					} else return [
						__props.image?.url ? (openBlock(), createBlock(unref(KImage), {
							key: 0,
							class: "k-b-cover__img",
							media: __props.image,
							size: "large",
							sizes: "100vw",
							alt: ""
						}, null, 8, ["media"])) : createCommentVNode("v-if", true),
						shade.value !== "none" ? (openBlock(), createBlock("span", {
							key: 1,
							class: "k-b-cover__shade",
							"aria-hidden": "true"
						})) : createCommentVNode("v-if", true),
						createVNode("div", { class: "k-b-cover__inner k-b-stack" }, [renderSlot(_ctx.$slots, "default", {}, void 0, true)])
					];
				}),
				_: 3
			}), _parent);
		};
	}
};
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/blocks/Cover.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
var Cover_default = /*#__PURE__*/ _plugin_vue_export_helper_default(_sfc_main, [["__scopeId", "data-v-9bfa8554"]]);
export { Cover_default as default };
