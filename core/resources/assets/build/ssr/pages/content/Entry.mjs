/* empty css                                  */
import { t as _plugin_vue_export_helper_default } from "../../chunks/_plugin-vue_export-helper-DjkACiAr.mjs";
import { computed, createTextVNode, toDisplayString, unref, useSSRContext, withCtx } from "vue";
import { ssrInterpolate, ssrRenderAttr, ssrRenderComponent, ssrRenderList } from "vue/server-renderer";
import { BlockTree, Head, KImage, Link } from "@kestello/app";
// core/resources/js/pages/content/Entry.vue
var _sfc_main = /*@__PURE__*/ Object.assign({ layout: "Site" }, {
	__name: "Entry",
	__ssrInlineRender: true,
	props: {
		entry: {
			type: Object,
			required: true
		},
		type: {
			type: Object,
			required: true
		},
		terms: {
			type: Array,
			default: () => []
		}
	},
	setup(__props) {
		const props = __props;
		const trees = computed(() => (props.entry.blocks ?? []).map((k) => ({
			key: k,
			blocks: props.entry.data?.[k]
		})).filter((t) => Array.isArray(t.blocks) && t.blocks.length));
		const body = computed(() => (props.entry.html ?? []).map((k) => ({
			key: k,
			html: props.entry.data?.[k]
		})).filter((p) => typeof p.html === "string" && p.html !== ""));
		const date = computed(() => props.type.archive !== null && props.entry.published_at ? new Intl.DateTimeFormat(void 0, { dateStyle: "long" }).format(new Date(props.entry.published_at)) : "");
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<!--[-->`);
			_push(ssrRenderComponent(unref(Head), { title: __props.entry.title }, null, _parent));
			_push(`<article class="k-entry" data-v-fb844458><header class="k-entry__header" data-v-fb844458>`);
			if (__props.type.archive) _push(ssrRenderComponent(unref(Link), {
				href: __props.type.archive,
				class: "k-entry__back"
			}, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(`${ssrInterpolate(__props.type.plural)}`);
					else return [createTextVNode(toDisplayString(__props.type.plural), 1)];
				}),
				_: 1
			}, _parent));
			else _push(`<!---->`);
			_push(`<h1 class="k-entry__title" data-k-title data-v-fb844458>${ssrInterpolate(__props.entry.title)}</h1>`);
			if (date.value) {
				_push(`<p class="k-entry__meta" data-v-fb844458><time${ssrRenderAttr("datetime", __props.entry.published_at)} data-v-fb844458>${ssrInterpolate(date.value)}</time>`);
				if (__props.entry.author) {
					_push(`<!--[--><span aria-hidden="true" data-v-fb844458> · </span>`);
					if (__props.entry.author.path) _push(ssrRenderComponent(unref(Link), { href: __props.entry.author.path }, {
						default: withCtx((_, _push, _parent, _scopeId) => {
							if (_push) _push(`${ssrInterpolate(__props.entry.author.name)}`);
							else return [createTextVNode(toDisplayString(__props.entry.author.name), 1)];
						}),
						_: 1
					}, _parent));
					else _push(`<span data-v-fb844458>${ssrInterpolate(__props.entry.author.name)}</span>`);
					_push(`<!--]-->`);
				} else _push(`<!---->`);
				_push(`</p>`);
			} else _push(`<!---->`);
			_push(`</header>`);
			if (__props.entry.data?.image?.url) {
				_push(`<figure class="k-entry__image" data-v-fb844458>`);
				_push(ssrRenderComponent(unref(KImage), {
					media: __props.entry.data.image,
					size: "large",
					sizes: "(min-width: 46rem) 44rem, calc(100vw - 2rem)",
					priority: ""
				}, null, _parent));
				if (__props.entry.data.image.caption) _push(`<figcaption data-v-fb844458>${ssrInterpolate(__props.entry.data.image.caption)}</figcaption>`);
				else _push(`<!---->`);
				_push(`</figure>`);
			} else _push(`<!---->`);
			_push(`<!--[-->`);
			ssrRenderList(body.value, (part) => {
				_push(`<div class="prose k-entry__body" data-v-fb844458>${part.html ?? ""}</div>`);
			});
			_push(`<!--]--><!--[-->`);
			ssrRenderList(trees.value, (tree) => {
				_push(`<div class="k-blocks k-entry__blocks" data-v-fb844458>`);
				_push(ssrRenderComponent(unref(BlockTree), { blocks: tree.blocks }, null, _parent));
				_push(`</div>`);
			});
			_push(`<!--]-->`);
			if (__props.terms.length) {
				_push(`<footer class="k-entry__terms" data-v-fb844458><!--[-->`);
				ssrRenderList(__props.terms, (group) => {
					_push(`<p data-v-fb844458><span class="k-entry__terms-label" data-v-fb844458>${ssrInterpolate(group.label)}</span><!--[-->`);
					ssrRenderList(group.terms, (t, i) => {
						_push(`<!--[-->`);
						if (i) _push(`<span aria-hidden="true" data-v-fb844458>, </span>`);
						else _push(`<!---->`);
						if (t.path) _push(ssrRenderComponent(unref(Link), { href: t.path }, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(t.name)}`);
								else return [createTextVNode(toDisplayString(t.name), 1)];
							}),
							_: 2
						}, _parent));
						else _push(`<span data-v-fb844458>${ssrInterpolate(t.name)}</span>`);
						_push(`<!--]-->`);
					});
					_push(`<!--]--></p>`);
				});
				_push(`<!--]--></footer>`);
			} else _push(`<!---->`);
			_push(`</article><!--]-->`);
		};
	}
});
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/content/Entry.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
var Entry_default = /*#__PURE__*/ _plugin_vue_export_helper_default(_sfc_main, [["__scopeId", "data-v-fb844458"]]);
export { Entry_default as default };
