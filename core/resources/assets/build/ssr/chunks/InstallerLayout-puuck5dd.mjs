import { unref, useSSRContext } from "vue";
import { ssrInterpolate, ssrRenderAttr, ssrRenderClass, ssrRenderComponent, ssrRenderList, ssrRenderSlot } from "vue/server-renderer";
import { Head } from "@kestello/app";
import { KIcon } from "@kestello/ui";
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
			"Server check",
			"Database",
			"Your site",
			"Administrator",
			"Install"
		];
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<!--[-->`);
			_push(ssrRenderComponent(unref(Head), {
				title: `Install Kestello: ${__props.title}`,
				raw: ""
			}, null, _parent));
			_push(`<main class="k-setup"><div class="k-setup__frame"><aside class="k-setup__rail"><div class="k-setup__brand"><img${ssrRenderAttr("src", "/assets/core/brand/kestello-symbol.svg")} alt="" width="24" height="24" class="k-logo k-logo--light"><img${ssrRenderAttr("src", "/assets/core/brand/kestello-symbol-white.svg")} alt="" width="24" height="24" class="k-logo k-logo--dark"><div><span class="k-setup__name">Kestello</span><span class="k-setup__sub">Installation</span></div></div>`);
			if (props.step >= 1) {
				_push(`<ol class="k-setup__steps" aria-label="Installation steps"><!--[-->`);
				ssrRenderList(STEPS, (label, i) => {
					_push(`<li class="${ssrRenderClass({
						"is-done": i + 1 < props.step,
						"is-current": i + 1 === props.step
					})}"${ssrRenderAttr("aria-current", i + 1 === props.step ? "step" : void 0)}><span class="k-setup__n">`);
					if (i + 1 < props.step) _push(ssrRenderComponent(unref(KIcon), { name: "check" }, null, _parent));
					else _push(`<!--[-->${ssrInterpolate(i + 1)}<!--]-->`);
					_push(`</span><span class="k-setup__label">${ssrInterpolate(label)}`);
					if (i + 1 < props.step) _push(`<span class="k-sr-only"> (done)</span>`);
					else _push(`<!---->`);
					_push(`</span></li>`);
				});
				_push(`<!--]--></ol>`);
			} else _push(`<!---->`);
			if (props.step <= 5) _push(`<p class="k-setup__help"> Stuck? The <a href="https://docs.kestello.com/guide/installation" target="_blank" rel="noopener">installation guide</a> covers each step. </p>`);
			else _push(`<!---->`);
			_push(`</aside><section class="k-setup__work"><header class="k-setup__head"><h1 class="k-setup__title">${ssrInterpolate(__props.title)}</h1>`);
			if (__props.description) _push(`<p class="k-setup__description">${ssrInterpolate(__props.description)}</p>`);
			else _push(`<!---->`);
			_push(`</header>`);
			ssrRenderSlot(_ctx.$slots, "default", {}, null, _push, _parent);
			_push(`</section></div></main><!--]-->`);
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
