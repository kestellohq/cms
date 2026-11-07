import { mergeProps, useSSRContext } from "vue";
import { ssrRenderAttrs, ssrRenderSlot } from "vue/server-renderer";
// core/resources/js/pages/blocks/EntryContent.vue
var _sfc_main = {
	__name: "EntryContent",
	__ssrInlineRender: true,
	props: {
		field: {
			type: String,
			default: ""
		},
		kind: {
			type: String,
			default: "blocks"
		},
		html: {
			type: String,
			default: ""
		}
	},
	setup(__props) {
		return (_ctx, _push, _parent, _attrs) => {
			if (__props.kind === "html") _push(`<div${ssrRenderAttrs(mergeProps({ class: "k-b-entry-content" }, _attrs))}><div class="prose">${__props.html ?? ""}</div></div>`);
			else {
				_push(`<div${ssrRenderAttrs(mergeProps({ class: "k-b-entry-content k-b-stack" }, _attrs))}>`);
				ssrRenderSlot(_ctx.$slots, "default", {}, null, _push, _parent);
				_push(`</div>`);
			}
		};
	}
};
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/blocks/EntryContent.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export { _sfc_main as default };
