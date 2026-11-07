import { t as _plugin_vue_export_helper_default } from "../../chunks/_plugin-vue_export-helper-DjkACiAr.mjs";
import { computed, createTextVNode, createVNode, toDisplayString, unref, useSSRContext, withCtx } from "vue";
import { ssrInterpolate, ssrRenderAttr, ssrRenderClass, ssrRenderComponent, ssrRenderList } from "vue/server-renderer";
import { Head, KImage, Link } from "@kestello/app";
// core/resources/js/pages/content/Archive.vue
var _sfc_main = /*@__PURE__*/ Object.assign({ layout: "Site" }, {
	__name: "Archive",
	__ssrInlineRender: true,
	props: {
		type: {
			type: Object,
			required: true
		},
		entries: {
			type: Array,
			default: () => []
		},
		pagination: {
			type: Object,
			required: true
		},
		term: {
			type: Object,
			default: null
		},
		date: {
			type: Object,
			default: null
		},
		author: {
			type: Object,
			default: null
		}
	},
	setup(__props) {
		const props = __props;
		const heading = computed(() => {
			if (props.date) return `${props.date.label} from ${props.type.plural}`;
			if (props.author) return `Published by ${props.author.name}`;
			return props.type.plural;
		});
		const fmt = (iso) => iso ? new Intl.DateTimeFormat(void 0, { dateStyle: "medium" }).format(new Date(iso)) : "";
		const pageUrl = (n) => n <= 1 ? props.type.path : `${props.type.path}?page=${n}`;
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<!--[-->`);
			_push(ssrRenderComponent(unref(Head), { title: heading.value }, null, _parent));
			_push(`<section class="k-archive" data-v-b3b1d159><header class="k-archive__header" data-v-b3b1d159><h1 data-v-b3b1d159>${ssrInterpolate(heading.value)}</h1>`);
			if (__props.type.description) _push(`<p data-v-b3b1d159>${ssrInterpolate(__props.type.description)}</p>`);
			else if (__props.date) {
				_push(`<p data-v-b3b1d159>`);
				_push(ssrRenderComponent(unref(Link), { href: __props.date.archive }, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`All ${ssrInterpolate(__props.date.label.toLowerCase())}`);
						else return [createTextVNode("All " + toDisplayString(__props.date.label.toLowerCase()), 1)];
					}),
					_: 1
				}, _parent));
				_push(`</p>`);
			} else _push(`<!---->`);
			_push(`</header>`);
			if (!__props.entries.length) _push(`<p class="k-archive__empty" data-v-b3b1d159>${ssrInterpolate(__props.term ? "Nothing published here uses it yet." : "Nothing has been published here yet.")}</p>`);
			else {
				_push(`<ol class="k-archive__list" data-v-b3b1d159><!--[-->`);
				ssrRenderList(__props.entries, (e, i) => {
					_push(`<li class="${ssrRenderClass([{ "has-image": e.image }, "k-archive__item"])}" data-v-b3b1d159><div class="k-archive__text" data-v-b3b1d159>`);
					if (e.published_at) _push(`<time${ssrRenderAttr("datetime", e.published_at)} class="k-archive__date" data-v-b3b1d159>${ssrInterpolate(fmt(e.published_at))}</time>`);
					else _push(`<!---->`);
					_push(`<h2 class="k-archive__title" data-v-b3b1d159>`);
					_push(ssrRenderComponent(unref(Link), { href: e.path }, {
						default: withCtx((_, _push, _parent, _scopeId) => {
							if (_push) _push(`${ssrInterpolate(e.title)}`);
							else return [createTextVNode(toDisplayString(e.title), 1)];
						}),
						_: 2
					}, _parent));
					_push(`</h2>`);
					if (e.excerpt) _push(`<p class="k-archive__excerpt" data-v-b3b1d159>${ssrInterpolate(e.excerpt)}</p>`);
					else _push(`<!---->`);
					_push(`</div>`);
					if (e.image) _push(ssrRenderComponent(unref(Link), {
						href: e.path,
						class: "k-archive__image",
						tabindex: "-1",
						"aria-hidden": "true"
					}, {
						default: withCtx((_, _push, _parent, _scopeId) => {
							if (_push) _push(ssrRenderComponent(unref(KImage), {
								media: e.image,
								size: "medium",
								sizes: "(min-width: 40rem) 12rem, 100vw",
								priority: i === 0,
								alt: ""
							}, null, _parent, _scopeId));
							else return [createVNode(unref(KImage), {
								media: e.image,
								size: "medium",
								sizes: "(min-width: 40rem) 12rem, 100vw",
								priority: i === 0,
								alt: ""
							}, null, 8, ["media", "priority"])];
						}),
						_: 2
					}, _parent));
					else _push(`<!---->`);
					_push(`</li>`);
				});
				_push(`<!--]--></ol>`);
			}
			if (__props.pagination.last > 1) {
				_push(`<nav class="k-archive__pager" aria-label="Pages" data-v-b3b1d159>`);
				if (__props.pagination.page > 1) _push(ssrRenderComponent(unref(Link), {
					href: pageUrl(__props.pagination.page - 1),
					rel: "prev"
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`Newer`);
						else return [createTextVNode("Newer")];
					}),
					_: 1
				}, _parent));
				else _push(`<!---->`);
				_push(`<span data-v-b3b1d159>Page ${ssrInterpolate(__props.pagination.page)} of ${ssrInterpolate(__props.pagination.last)}</span>`);
				if (__props.pagination.page < __props.pagination.last) _push(ssrRenderComponent(unref(Link), {
					href: pageUrl(__props.pagination.page + 1),
					rel: "next"
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`Older`);
						else return [createTextVNode("Older")];
					}),
					_: 1
				}, _parent));
				else _push(`<!---->`);
				_push(`</nav>`);
			} else _push(`<!---->`);
			_push(`</section><!--]-->`);
		};
	}
});
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/content/Archive.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
var Archive_default = /*#__PURE__*/ _plugin_vue_export_helper_default(_sfc_main, [["__scopeId", "data-v-b3b1d159"]]);
export { Archive_default as default };
