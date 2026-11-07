import { t as _plugin_vue_export_helper_default } from "../../chunks/_plugin-vue_export-helper-DjkACiAr.mjs";
import { computed, createVNode, mergeProps, ref, resolveDynamicComponent, unref, useSSRContext, withCtx } from "vue";
import { ssrInterpolate, ssrRenderAttr, ssrRenderAttrs, ssrRenderComponent, ssrRenderVNode } from "vue/server-renderer";
import { KImage, Link, useCanvas } from "@kestello/app";
// core/resources/js/pages/blocks/Image.vue
var _sfc_main = {
	__name: "Image",
	__ssrInlineRender: true,
	props: {
		image: {
			type: Object,
			default: null
		},
		alt: {
			type: String,
			default: ""
		},
		caption: {
			type: String,
			default: ""
		},
		link: {
			type: String,
			default: ""
		},
		scale: {
			type: [Number, String],
			default: 100
		},
		align: {
			type: String,
			default: "center"
		},
		ratio: {
			type: String,
			default: ""
		},
		corners: {
			type: String,
			default: ""
		},
		lightbox: Boolean,
		width: {
			type: String,
			default: "content"
		}
	},
	setup(__props) {
		const props = __props;
		const canvas = useCanvas();
		const pct = computed(() => Math.min(100, Math.max(10, Number(props.scale) || 100)));
		const RATIOS = [
			"1/1",
			"4/3",
			"3/2",
			"16/9",
			"3/4",
			"2/3"
		];
		const ratio = computed(() => RATIOS.includes(props.ratio) ? props.ratio.replace("/", " / ") : null);
		const ALIGNS = [
			"center",
			"start",
			"end",
			"wrap-start",
			"wrap-end"
		];
		const align = computed(() => ALIGNS.includes(props.align) ? props.align : "center");
		const CORNERS = [
			"square",
			"rounded",
			"round"
		];
		const corners = computed(() => CORNERS.includes(props.corners) ? `is-${props.corners}` : null);
		const sizes = computed(() => {
			const column = {
				content: "min(44rem, 100vw - 2rem)",
				wide: "min(72rem, 100vw - 2rem)",
				full: "100vw"
			}[props.width] ?? "100vw";
			return pct.value === 100 ? `calc(${column})` : `calc((${column}) * ${pct.value / 100})`;
		});
		const rendition = computed(() => pct.value <= 40 && props.width !== "full" ? "medium" : "large");
		const internal = computed(() => props.link.startsWith("/") && !props.link.startsWith("//"));
		const zoomable = computed(() => props.lightbox && !props.link && !canvas);
		const box = ref(null);
		const big = computed(() => props.image?.sizes?.large?.src ?? props.image?.url);
		const label = computed(() => props.alt || props.image?.alt || "");
		return (_ctx, _push, _parent, _attrs) => {
			if (__props.image?.url) {
				_push(`<figure${ssrRenderAttrs(mergeProps({
					class: ["k-b-image", [
						`is-${align.value}`,
						corners.value,
						ratio.value && "has-ratio"
					]],
					"data-width": __props.width,
					style: {
						"--k-size": `${pct.value}%`,
						"--k-size-f": pct.value / 100,
						"--k-img-ratio": ratio.value ?? void 0
					}
				}, _attrs))} data-v-efb9c063>`);
				ssrRenderVNode(_push, createVNode(resolveDynamicComponent(__props.link ? internal.value ? unref(Link) : "a" : zoomable.value ? "button" : "div"), {
					href: __props.link || void 0,
					type: zoomable.value ? "button" : void 0,
					class: "k-b-image__frame",
					"data-k-resize": "scale",
					"aria-label": zoomable.value ? label.value ? `Open larger: ${label.value}` : "Open the image larger" : void 0,
					onClick: ($event) => zoomable.value && box.value?.showModal()
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(ssrRenderComponent(unref(KImage), {
							media: __props.image,
							size: rendition.value,
							sizes: sizes.value,
							alt: __props.alt || null
						}, null, _parent, _scopeId));
						else return [createVNode(unref(KImage), {
							media: __props.image,
							size: rendition.value,
							sizes: sizes.value,
							alt: __props.alt || null
						}, null, 8, [
							"media",
							"size",
							"sizes",
							"alt"
						])];
					}),
					_: 1
				}), _parent);
				if (__props.caption) _push(`<figcaption data-v-efb9c063>${ssrInterpolate(__props.caption)}</figcaption>`);
				else _push(`<!---->`);
				if (zoomable.value) _push(`<dialog class="k-b-image__box" data-v-efb9c063><img${ssrRenderAttr("src", big.value)}${ssrRenderAttr("alt", label.value)} data-v-efb9c063><button type="button" class="k-b-image__close" aria-label="Close" data-v-efb9c063>×</button></dialog>`);
				else _push(`<!---->`);
				_push(`</figure>`);
			} else _push(`<!---->`);
		};
	}
};
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/blocks/Image.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
var Image_default = /*#__PURE__*/ _plugin_vue_export_helper_default(_sfc_main, [["__scopeId", "data-v-efb9c063"]]);
export { Image_default as default };
