import { unref, useSSRContext } from "vue";
import { ssrInterpolate, ssrRenderAttr, ssrRenderClass, ssrRenderComponent, ssrRenderList, ssrRenderSlot } from "vue/server-renderer";
import { Head } from "@kestello/app";
// core/resources/js/components/InstallerLayout.vue
var _sfc_main = {
	__name: "InstallerLayout",
	__ssrInlineRender: true,
	props: {
		step: {
			type: Number,
			default: 0
		},
		title: {
			type: String,
			required: true
		},
		description: {
			type: String,
			default: ""
		}
	},
	setup(__props) {
		const props = __props;
		const STEPS = [
			"Server",
			"Database",
			"Site",
			"Administrator",
			"Install"
		];
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<!--[-->`);
			_push(ssrRenderComponent(unref(Head), {
				title: `Install Kestello: ${__props.title}`,
				raw: ""
			}, null, _parent));
			_push(`<main class="k-auth k-auth--wide"><div class="k-auth__sheet"><div class="k-auth__brand"><img${ssrRenderAttr("src", "/assets/core/brand/kestello-symbol.svg")} alt="" width="26" height="26" class="k-logo k-logo--light"><img${ssrRenderAttr("src", "/assets/core/brand/kestello-symbol-white.svg")} alt="" width="26" height="26" class="k-logo k-logo--dark"><div><span class="k-auth__site">Kestello</span><span class="k-sidebar__product">Installation</span></div></div>`);
			if (props.step >= 1 && props.step <= 5) {
				_push(`<ol class="k-steps" aria-label="Installation steps"><!--[-->`);
				ssrRenderList(STEPS, (label, i) => {
					_push(`<li class="${ssrRenderClass({
						"is-done": i + 1 < props.step,
						"is-current": i + 1 === props.step
					})}"${ssrRenderAttr("aria-current", i + 1 === props.step ? "step" : void 0)}><span class="k-steps__n">${ssrInterpolate(i + 1)}</span><span class="k-steps__label">${ssrInterpolate(label)}</span></li>`);
				});
				_push(`<!--]--></ol>`);
			} else _push(`<!---->`);
			_push(`<header class="k-auth__head"><h1 class="k-auth__title">${ssrInterpolate(__props.title)}</h1>`);
			if (__props.description) _push(`<p class="k-auth__description">${ssrInterpolate(__props.description)}</p>`);
			else _push(`<!---->`);
			_push(`</header>`);
			ssrRenderSlot(_ctx.$slots, "default", {}, null, _push, _parent);
			_push(`</div></main><!--]-->`);
		};
	}
};
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/components/InstallerLayout.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export { _sfc_main as t };
