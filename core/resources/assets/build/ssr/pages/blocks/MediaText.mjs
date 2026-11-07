import { t as _plugin_vue_export_helper_default } from "../../chunks/_plugin-vue_export-helper-DjkACiAr.mjs";
import { computed, mergeProps, unref, useSSRContext } from "vue";
import { ssrRenderAttrs, ssrRenderComponent, ssrRenderSlot } from "vue/server-renderer";
import { KImage, useCanvas } from "@kestello/app";
// core/resources/js/pages/blocks/MediaText.vue
var _sfc_main = {
	__name: "MediaText",
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
		side: {
			type: String,
			default: "start"
		},
		share: {
			type: String,
			default: "50"
		},
		valign: {
			type: String,
			default: "center"
		},
		fill: Boolean,
		width: {
			type: String,
			default: "wide"
		}
	},
	setup(__props) {
		const props = __props;
		const canvas = useCanvas();
		const share = computed(() => [
			"40",
			"50",
			"60"
		].includes(String(props.share)) ? Number(props.share) : 50);
		const sizes = computed(() => {
			return `(min-width: 600px) calc((${{
				content: "min(44rem, 100vw - 2rem)",
				wide: "min(72rem, 100vw - 2rem)"
			}[props.width] ?? "100vw"}) * ${share.value / 100}), calc(100vw - 2rem)`;
		});
		const classes = computed(() => [
			props.side === "end" ? "is-end" : "is-start",
			`is-v-${[
				"start",
				"center",
				"end"
			].includes(props.valign) ? props.valign : "center"}`,
			props.fill && "is-fill"
		]);
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<div${ssrRenderAttrs(mergeProps({
				class: ["k-b-mediatext", classes.value],
				"data-width": __props.width,
				style: { "--k-share": `${share.value}%` }
			}, _attrs))} data-v-3e80a4af><figure class="k-b-mediatext__media" data-v-3e80a4af>`);
			if (__props.image?.url) _push(ssrRenderComponent(unref(KImage), {
				media: __props.image,
				size: "large",
				sizes: sizes.value,
				alt: __props.alt || null
			}, null, _parent));
			else if (unref(canvas)) _push(`<div class="k-b-mediatext__empty" data-v-3e80a4af>Choose an image in this block&#39;s settings.</div>`);
			else _push(`<!---->`);
			_push(`</figure><div class="k-b-mediatext__text k-b-stack" data-v-3e80a4af>`);
			ssrRenderSlot(_ctx.$slots, "default", {}, null, _push, _parent);
			_push(`</div></div>`);
		};
	}
};
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/blocks/MediaText.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
var MediaText_default = /*#__PURE__*/ _plugin_vue_export_helper_default(_sfc_main, [["__scopeId", "data-v-3e80a4af"]]);
export { MediaText_default as default };
