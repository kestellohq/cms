import { t as _plugin_vue_export_helper_default } from "../../chunks/_plugin-vue_export-helper-DjkACiAr.mjs";
import { computed, mergeProps, ref, unref, useSSRContext } from "vue";
import { ssrInterpolate, ssrRenderAttr, ssrRenderAttrs, ssrRenderComponent, ssrRenderList } from "vue/server-renderer";
import { KImage, useCanvas } from "@kestello/app";
// core/resources/js/pages/blocks/Gallery.vue
var _sfc_main = {
	__name: "Gallery",
	__ssrInlineRender: true,
	props: {
		images: {
			type: Array,
			default: () => []
		},
		columns: {
			type: [String, Number],
			default: "3"
		},
		ratio: {
			type: String,
			default: "1/1"
		},
		gap: {
			type: String,
			default: "md"
		},
		captions: Boolean,
		lightbox: {
			type: Boolean,
			default: true
		},
		width: {
			type: String,
			default: "wide"
		}
	},
	setup(__props) {
		const props = __props;
		const canvas = useCanvas();
		const items = computed(() => Array.isArray(props.images) ? props.images.filter((m) => m?.url) : []);
		const cols = computed(() => Math.min(6, Math.max(2, Number(props.columns) || 3)));
		const ratio = computed(() => [
			"1/1",
			"4/3",
			"3/2",
			"16/9",
			"3/4"
		].includes(props.ratio) ? props.ratio.replace("/", " / ") : null);
		const sizes = computed(() => {
			return `(min-width: 600px) calc((${{
				content: "min(44rem, 100vw - 2rem)",
				wide: "min(72rem, 100vw - 2rem)"
			}[props.width] ?? "100vw"}) / ${cols.value}), 50vw`;
		});
		const zoomable = computed(() => props.lightbox && !canvas);
		ref(null);
		const at = ref(0);
		const current = computed(() => items.value[at.value] ?? null);
		const big = (m) => m?.sizes?.large?.src ?? m?.url;
		const label = (m, i) => m?.alt || `Image ${i + 1} of ${items.value.length}`;
		return (_ctx, _push, _parent, _attrs) => {
			if (items.value.length) {
				_push(`<figure${ssrRenderAttrs(mergeProps({
					class: ["k-b-gallery", [`is-gap-${__props.gap}`, ratio.value && "has-ratio"]],
					"data-width": __props.width,
					style: {
						"--k-cols": cols.value,
						"--k-ratio": ratio.value ?? void 0
					}
				}, _attrs))} data-v-59c88615><ul class="k-b-gallery__grid" data-v-59c88615><!--[-->`);
				ssrRenderList(items.value, (m, i) => {
					_push(`<li class="k-b-gallery__item" data-v-59c88615>`);
					if (zoomable.value) {
						_push(`<button type="button" class="k-b-gallery__open"${ssrRenderAttr("aria-label", `Open larger: ${label(m, i)}`)} data-v-59c88615>`);
						_push(ssrRenderComponent(unref(KImage), {
							media: m,
							size: "medium",
							sizes: sizes.value
						}, null, _parent));
						_push(`</button>`);
					} else _push(ssrRenderComponent(unref(KImage), {
						media: m,
						size: "medium",
						sizes: sizes.value
					}, null, _parent));
					if (__props.captions && m.caption) _push(`<span class="k-b-gallery__caption" data-v-59c88615>${ssrInterpolate(m.caption)}</span>`);
					else _push(`<!---->`);
					_push(`</li>`);
				});
				_push(`<!--]--></ul>`);
				if (zoomable.value) {
					_push(`<dialog class="k-b-gallery__box" aria-label="Images, larger" data-v-59c88615>`);
					if (current.value) {
						_push(`<figure class="k-b-gallery__shown" data-v-59c88615><img${ssrRenderAttr("src", big(current.value))}${ssrRenderAttr("alt", current.value.alt || "")} data-v-59c88615><figcaption data-v-59c88615>`);
						if (current.value.caption) _push(`<span data-v-59c88615>${ssrInterpolate(current.value.caption)}</span>`);
						else _push(`<!---->`);
						_push(`<span class="k-b-gallery__count" data-v-59c88615>${ssrInterpolate(at.value + 1)} / ${ssrInterpolate(items.value.length)}</span></figcaption></figure>`);
					} else _push(`<!---->`);
					if (items.value.length > 1) _push(`<!--[--><button type="button" class="k-b-gallery__nav is-prev" aria-label="Previous image" data-v-59c88615>‹</button><button type="button" class="k-b-gallery__nav is-next" aria-label="Next image" data-v-59c88615>›</button><!--]-->`);
					else _push(`<!---->`);
					_push(`<button type="button" class="k-b-gallery__close" aria-label="Close" data-v-59c88615>×</button></dialog>`);
				} else _push(`<!---->`);
				_push(`</figure>`);
			} else _push(`<!---->`);
		};
	}
};
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/blocks/Gallery.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
var Gallery_default = /*#__PURE__*/ _plugin_vue_export_helper_default(_sfc_main, [["__scopeId", "data-v-59c88615"]]);
export { Gallery_default as default };
