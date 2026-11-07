/* empty css                                  */
import { unref, useSSRContext } from "vue";
import { ssrRenderAttr, ssrRenderClass, ssrRenderComponent } from "vue/server-renderer";
import { BlockTree, Head } from "@kestello/app";
// core/resources/js/pages/content/TemplateBare.vue
var _sfc_main = /*@__PURE__*/ Object.assign({ layout: null }, {
	__name: "TemplateBare",
	__ssrInlineRender: true,
	props: {
		entry: {
			type: Object,
			required: true
		},
		template: {
			type: Object,
			required: true
		},
		design: {
			type: Object,
			default: null
		}
	},
	setup(__props) {
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<!--[-->`);
			_push(ssrRenderComponent(unref(Head), { title: __props.entry.title }, null, _parent));
			_push(`<div class="${ssrRenderClass([{ "is-preview": __props.design?.preview }, "k-template k-template--bare k-blocks"])}"${ssrRenderAttr("data-template", __props.template.key)}>`);
			_push(ssrRenderComponent(unref(BlockTree), { blocks: __props.template.blocks }, null, _parent));
			_push(`</div><!--]-->`);
		};
	}
});
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/content/TemplateBare.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export { _sfc_main as default };
