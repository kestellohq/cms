import { t as _plugin_vue_export_helper_default } from "../../chunks/_plugin-vue_export-helper-DjkACiAr.mjs";
import { computed, mergeProps, onMounted, ref, unref, useSSRContext } from "vue";
import { ssrIncludeBooleanAttr, ssrInterpolate, ssrRenderAttr, ssrRenderAttrs, ssrRenderComponent } from "vue/server-renderer";
import { KImage, useCanvas } from "@kestello/app";
// core/resources/js/pages/blocks/Embed.vue
var _sfc_main = {
	__name: "Embed",
	__ssrInlineRender: true,
	props: {
		file: {
			type: Object,
			default: null
		},
		url: {
			type: String,
			default: ""
		},
		title: {
			type: String,
			default: ""
		},
		poster: {
			type: Object,
			default: null
		},
		ratio: {
			type: String,
			default: "16/9"
		},
		start: {
			type: [Number, String],
			default: null
		},
		autoplay: Boolean,
		loop: Boolean,
		controls: {
			type: Boolean,
			default: true
		},
		width: {
			type: String,
			default: "wide"
		},
		embed: {
			type: Object,
			default: null
		}
	},
	setup(__props) {
		const props = __props;
		const canvas = useCanvas();
		const playing = ref(false);
		const provider = computed(() => props.embed?.provider === "vimeo" ? "Vimeo" : "YouTube");
		const RATIOS = [
			"16/9",
			"4/3",
			"1/1",
			"9/16",
			"21/9"
		];
		const ratio = computed(() => RATIOS.includes(props.ratio) ? props.ratio.replace("/", " / ") : "16 / 9");
		const video = computed(() => props.file?.url && props.file.kind === "video" ? props.file : null);
		const startAt = computed(() => Math.max(0, Number(props.start) || 0));
		const src = computed(() => video.value ? video.value.url + (startAt.value ? `#t=${startAt.value}` : "") : "");
		const reduced = ref(false);
		onMounted(() => {
			reduced.value = window.matchMedia?.("(prefers-reduced-motion: reduce)").matches ?? false;
		});
		const autoplaying = computed(() => props.autoplay && !reduced.value && !canvas);
		return (_ctx, _push, _parent, _attrs) => {
			if (video.value) _push(`<figure${ssrRenderAttrs(mergeProps({
				class: "k-b-embed",
				"data-width": __props.width,
				style: { "--k-video-ratio": ratio.value }
			}, _attrs))} data-v-b246ecba><div class="k-b-embed__frame" data-v-b246ecba><video${ssrRenderAttr("src", src.value)}${ssrRenderAttr("poster", __props.poster?.sizes?.large?.src ?? __props.poster?.url ?? void 0)}${ssrRenderAttr("title", __props.title || void 0)}${ssrRenderAttr("aria-label", __props.title || void 0)}${ssrIncludeBooleanAttr(autoplaying.value) ? " autoplay" : ""}${ssrIncludeBooleanAttr(autoplaying.value) ? " muted" : ""}${ssrIncludeBooleanAttr(__props.loop || autoplaying.value) ? " loop" : ""}${ssrIncludeBooleanAttr(__props.controls || !autoplaying.value) ? " controls" : ""} playsinline preload="metadata" data-v-b246ecba></video></div></figure>`);
			else if (__props.embed?.src) {
				_push(`<figure${ssrRenderAttrs(mergeProps({
					class: "k-b-embed",
					"data-width": __props.width,
					style: { "--k-video-ratio": ratio.value }
				}, _attrs))} data-v-b246ecba><div class="k-b-embed__frame" data-v-b246ecba>`);
				if (playing.value && !unref(canvas)) _push(`<iframe${ssrRenderAttr("src", __props.embed.src)}${ssrRenderAttr("title", __props.title || `${provider.value} video`)} allow="autoplay; encrypted-media; picture-in-picture; fullscreen" allowfullscreen referrerpolicy="strict-origin-when-cross-origin" data-v-b246ecba></iframe>`);
				else {
					_push(`<button type="button" class="k-b-embed__play" data-v-b246ecba>`);
					if (__props.poster) _push(ssrRenderComponent(unref(KImage), {
						media: __props.poster,
						size: "large",
						sizes: "(min-width: 74rem) 72rem, 100vw",
						class: "k-b-embed__poster",
						alt: ""
					}, null, _parent));
					else _push(`<!---->`);
					_push(`<svg class="k-b-embed__icon" viewBox="0 0 64 64" aria-hidden="true" data-v-b246ecba><circle cx="32" cy="32" r="31" data-v-b246ecba></circle><path d="M26 21l19 11-19 11z" data-v-b246ecba></path></svg><span class="k-b-embed__label" data-v-b246ecba>Play${ssrInterpolate(__props.title ? `: ${__props.title}` : " video")}</span><span class="k-b-embed__note" data-v-b246ecba>Loads from ${ssrInterpolate(provider.value)}</span></button>`);
				}
				_push(`</div></figure>`);
			} else if (__props.url) _push(`<p${ssrRenderAttrs(mergeProps({ class: "k-b-embed__fallback" }, _attrs))} data-v-b246ecba><a${ssrRenderAttr("href", __props.url)} rel="noopener noreferrer" data-v-b246ecba>${ssrInterpolate(__props.title || __props.url)}</a></p>`);
			else if (unref(canvas)) _push(`<div${ssrRenderAttrs(mergeProps({ class: "k-b-embed__empty" }, _attrs))} data-v-b246ecba>Choose a video file, or paste a YouTube or Vimeo address in the block settings.</div>`);
			else _push(`<!---->`);
		};
	}
};
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/blocks/Embed.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
var Embed_default = /*#__PURE__*/ _plugin_vue_export_helper_default(_sfc_main, [["__scopeId", "data-v-b246ecba"]]);
export { Embed_default as default };
