import { Fragment, computed, createBlock, createCommentVNode, createSlots, createTextVNode, createVNode, mergeModels, mergeProps, onBeforeUnmount, onMounted, openBlock, reactive, ref, renderList, toDisplayString, unref, useModel, useSSRContext, vModelCheckbox, vModelText, watch, withCtx, withDirectives, withModifiers } from "vue";
import { ssrIncludeBooleanAttr, ssrInterpolate, ssrLooseContain, ssrRenderAttr, ssrRenderClass, ssrRenderComponent, ssrRenderList, ssrRenderStyle } from "vue/server-renderer";
import { Head, KImage, Link, usePage } from "@kestello/app";
import { ApiError, __, confirm, formatBytes, formatDateTime, notify, useApi, useResource } from "@kestello/sdk";
import { KAlert, KButton, KCard, KEmpty, KField, KIcon, KInput, KMediaGrid, KModal, KPageHeader, KSelect, KTabs, KUploadZone } from "@kestello/ui";
// core/resources/js/components/media/image-edit.js
/**
* The image editor's model, pure so it can be tested in Node.
*
* Matches the server (ImageEdit.php): the original is turned clockwise
* (rotate), then mirrored (flipH, flipV), then cropped. The crop is x, y,
* w, h as fractions of the turned and mirrored picture, the one on screen.
*/
var MIN_PX = 8;
var clamp = (n, lo, hi) => Math.min(hi, Math.max(lo, n));
var FULL = Object.freeze({
	x: 0,
	y: 0,
	w: 1,
	h: 1
});
/** Editor state from the API's `edit` (or null for an unedited image). */
function fromMedia(edit) {
	if (!edit) return {
		rotate: 0,
		flipH: false,
		flipV: false,
		crop: { ...FULL }
	};
	const c = edit.crop;
	return {
		rotate: edit.rotate ?? 0,
		flipH: !!edit.flip_horizontal,
		flipV: !!edit.flip_vertical,
		crop: c ? {
			x: c.x,
			y: c.y,
			w: c.width,
			h: c.height
		} : { ...FULL }
	};
}
/** [width, height] of the original once turned. */
function orientedSize(width, height, rotate) {
	return rotate % 180 === 0 ? [width, height] : [height, width];
}
/**
* A quarter turn, clockwise or not. The crop turns with the picture; a
* flip made before changes axis, since the server turns first.
*/
function rotated(s, clockwise) {
	const { x, y, w, h } = s.crop;
	const crop = clockwise ? {
		x: 1 - (y + h),
		y: x,
		w: h,
		h: w
	} : {
		x: y,
		y: 1 - (x + w),
		w: h,
		h: w
	};
	return {
		rotate: (s.rotate + (clockwise ? 90 : 270)) % 360,
		flipH: s.flipV,
		flipV: s.flipH,
		crop
	};
}
/** Mirror left to right (horizontal) or top to bottom; the crop follows. */
function flipped(s, horizontal) {
	const { x, y, w, h } = s.crop;
	return horizontal ? {
		...s,
		flipH: !s.flipH,
		crop: {
			x: 1 - (x + w),
			y,
			w,
			h
		}
	} : {
		...s,
		flipV: !s.flipV,
		crop: {
			x,
			y: 1 - (y + h),
			w,
			h
		}
	};
}
/**
* The largest centred crop with this width-to-height ratio (null keeps
* the crop as it is), for a turned picture of width × height pixels.
*/
function withAspect(s, ratio, width, height) {
	if (!ratio) return s;
	let w = 1;
	let h = width / (ratio * height);
	if (h > 1) {
		h = 1;
		w = ratio * height / width;
	}
	return {
		...s,
		crop: {
			x: (1 - w) / 2,
			y: (1 - h) / 2,
			w,
			h
		}
	};
}
/**
* The crop after dragging a handle ('move', 'n', 'ne', 'e', 'se', 's',
* 'sw', 'w', 'nw') by dx, dy (fractions of the picture). Stays inside
* the picture and at least 8 pixels; with a ratio, corners keep the shape.
*
* @param {{width: number, height: number, ratio?: number|null}} size the turned picture in pixels
*/
function dragged(start, handle, dx, dy, { width, height, ratio = null }) {
	const minW = Math.min(1, MIN_PX / width);
	const minH = Math.min(1, MIN_PX / height);
	if (handle === "move") return {
		...start,
		x: clamp(start.x + dx, 0, 1 - start.w),
		y: clamp(start.y + dy, 0, 1 - start.h)
	};
	let left = start.x;
	let top = start.y;
	let right = start.x + start.w;
	let bottom = start.y + start.h;
	if (handle.includes("w")) left = clamp(left + dx, 0, right - minW);
	if (handle.includes("e")) right = clamp(right + dx, left + minW, 1);
	if (handle.includes("n")) top = clamp(top + dy, 0, bottom - minH);
	if (handle.includes("s")) bottom = clamp(bottom + dy, top + minH, 1);
	if (ratio && handle.length === 2) {
		const hFor = (w) => w * width / (ratio * height);
		const anchorX = handle.includes("w") ? start.x + start.w : start.x;
		const anchorY = handle.includes("n") ? start.y + start.h : start.y;
		const roomW = handle.includes("w") ? anchorX : 1 - anchorX;
		const roomH = handle.includes("n") ? anchorY : 1 - anchorY;
		let w = Math.max(right - left, minW);
		w = Math.min(w, roomW, roomH * ratio * height / width);
		const h = hFor(w);
		left = handle.includes("w") ? anchorX - w : anchorX;
		top = handle.includes("n") ? anchorY - h : anchorY;
		right = left + w;
		bottom = top + h;
	}
	return {
		x: left,
		y: top,
		w: right - left,
		h: bottom - top
	};
}
/** Pixel size of the result, for an original of width × height. */
function resultSize(s, width, height) {
	const [w, h] = orientedSize(width, height, s.rotate);
	return [Math.max(1, Math.round(s.crop.w * w)), Math.max(1, Math.round(s.crop.h * h))];
}
var isFull = (c) => c.x < 1e-6 && c.y < 1e-6 && c.w > 1 - 1e-6 && c.h > 1 - 1e-6;
/** Whether the state leaves the picture as it is. */
function isIdentity(s) {
	return s.rotate === 0 && !s.flipH && !s.flipV && isFull(s.crop);
}
var r6 = (n) => Math.round(n * 1e6) / 1e6;
/** The body for POST /media/{id}/edit. */
function toBody(s) {
	const c = s.crop;
	return {
		rotate: s.rotate,
		flip_horizontal: s.flipH,
		flip_vertical: s.flipV,
		crop: isFull(c) ? null : {
			x: r6(c.x),
			y: r6(c.y),
			width: r6(Math.min(c.w, 1 - c.x)),
			height: r6(Math.min(c.h, 1 - c.y))
		}
	};
}
// core/resources/js/components/media/ImageEditor.vue
/**
* Crop, turn and flip an image from the media library. Works on the
* original (shown through an expiring link when the image was edited
* before), so every save starts from the untouched picture.
*/
var _sfc_main$1 = {
	__name: "ImageEditor",
	__ssrInlineRender: true,
	props: /*@__PURE__*/ mergeModels({ media: {
		type: Object,
		required: true
	} }, {
		"open": {
			type: Boolean,
			default: false
		},
		"openModifiers": {}
	}),
	emits: /*@__PURE__*/ mergeModels(["saved"], ["update:open"]),
	setup(__props, { emit: __emit }) {
		const props = __props;
		const open = useModel(__props, "open");
		const emit = __emit;
		const api = useApi();
		const source = computed(() => props.media.edit ? {
			url: props.media.original?.url ?? null,
			width: props.media.original?.width ?? 0,
			height: props.media.original?.height ?? 0
		} : {
			url: props.media.url,
			width: props.media.width ?? 0,
			height: props.media.height ?? 0
		});
		const state = ref(fromMedia(props.media.edit));
		const aspect = ref("");
		const saving = ref(false);
		const problem = ref("");
		watch(open, (value) => {
			if (value) {
				state.value = fromMedia(props.media.edit);
				aspect.value = "";
				problem.value = "";
			}
		});
		const ASPECTS = [
			{
				value: "",
				label: __("Free")
			},
			{
				value: "original",
				label: __("Original")
			},
			{
				value: "1",
				label: __("Square")
			},
			{
				value: "1.3333333",
				label: "4:3"
			},
			{
				value: "1.5",
				label: "3:2"
			},
			{
				value: "1.7777778",
				label: "16:9"
			},
			{
				value: "0.75",
				label: "3:4"
			}
		];
		const oriented = computed(() => orientedSize(source.value.width, source.value.height, state.value.rotate));
		const ratio = computed(() => {
			if (aspect.value === "original") return source.value.width / Math.max(1, source.value.height);
			return aspect.value ? Number(aspect.value) : null;
		});
		function chooseAspect(value) {
			aspect.value = value;
			state.value = withAspect(state.value, ratio.value, ...oriented.value);
		}
		function turn(clockwise) {
			state.value = rotated(state.value, clockwise);
			if (ratio.value) state.value = withAspect(state.value, ratio.value, ...oriented.value);
		}
		function flip(horizontal) {
			state.value = flipped(state.value, horizontal);
		}
		function reset() {
			state.value = fromMedia(null);
			aspect.value = "";
		}
		const result = computed(() => resultSize(state.value, source.value.width, source.value.height));
		const unchanged = computed(() => JSON.stringify(toBody(state.value)) === JSON.stringify(toBody(fromMedia(props.media.edit))));
		const frame = ref(null);
		const room = ref({
			width: 0,
			height: 0
		});
		let observer = null;
		watch(frame, (el) => {
			observer?.disconnect();
			if (!el) return;
			observer = new ResizeObserver(([entry]) => room.value = {
				width: entry.contentRect.width,
				height: entry.contentRect.height
			});
			observer.observe(el);
		});
		onBeforeUnmount(() => observer?.disconnect());
		const stage = computed(() => {
			const [w, h] = oriented.value;
			if (!w || !h || !room.value.width) return {
				width: 0,
				height: 0
			};
			const scale = Math.min(room.value.width / w, room.value.height / h, 1);
			return {
				width: Math.round(w * scale),
				height: Math.round(h * scale)
			};
		});
		const picture = computed(() => {
			const { width, height } = stage.value;
			const sideways = state.value.rotate % 180 !== 0;
			const s = state.value;
			return {
				width: `${sideways ? height : width}px`,
				height: `${sideways ? width : height}px`,
				transform: `translate(-50%, -50%) scale(${s.flipH ? -1 : 1}, ${s.flipV ? -1 : 1}) rotate(${s.rotate}deg)`
			};
		});
		const box = computed(() => {
			const c = state.value.crop;
			return {
				left: `${c.x * 100}%`,
				top: `${c.y * 100}%`,
				width: `${c.w * 100}%`,
				height: `${c.h * 100}%`
			};
		});
		const HANDLES = [
			"nw",
			"n",
			"ne",
			"e",
			"se",
			"s",
			"sw",
			"w"
		];
		const handles = computed(() => ratio.value ? HANDLES.filter((h) => h.length === 2) : HANDLES);
		const HANDLE_LABEL = {
			nw: __("Top left corner"),
			n: __("Top edge"),
			ne: __("Top right corner"),
			e: __("Right edge"),
			se: __("Bottom right corner"),
			s: __("Bottom edge"),
			sw: __("Bottom left corner"),
			w: __("Left edge")
		};
		let drag = null;
		function startDrag(event, handle) {
			if (event.button !== 0) return;
			event.preventDefault();
			event.currentTarget.setPointerCapture?.(event.pointerId);
			drag = {
				handle,
				x: event.clientX,
				y: event.clientY,
				crop: { ...state.value.crop }
			};
		}
		function moveDrag(event) {
			if (!drag || !stage.value.width) return;
			const dx = (event.clientX - drag.x) / stage.value.width;
			const dy = (event.clientY - drag.y) / stage.value.height;
			const [width, height] = oriented.value;
			state.value = {
				...state.value,
				crop: dragged(drag.crop, drag.handle, dx, dy, {
					width,
					height,
					ratio: ratio.value
				})
			};
		}
		function endDrag() {
			drag = null;
		}
		function nudge(event, handle) {
			const step = event.shiftKey ? .05 : .01;
			const d = {
				ArrowLeft: [-step, 0],
				ArrowRight: [step, 0],
				ArrowUp: [0, -step],
				ArrowDown: [0, step]
			}[event.key];
			if (!d) return;
			event.preventDefault();
			const [width, height] = oriented.value;
			state.value = {
				...state.value,
				crop: dragged(state.value.crop, handle, d[0], d[1], {
					width,
					height,
					ratio: ratio.value
				})
			};
		}
		async function save() {
			saving.value = true;
			problem.value = "";
			try {
				const media = (await api.post(`/media/${props.media.id}/edit`, toBody(state.value))).data;
				emit("saved", media);
				open.value = false;
				notify.success(isIdentity(state.value) ? __("The original is back.") : __("Image saved."));
			} catch (e) {
				problem.value = e instanceof ApiError ? Object.values(e.fieldErrors())[0] ?? e.message : String(e);
			} finally {
				saving.value = false;
			}
		}
		async function restore() {
			if (!await confirm({
				title: __("Restore the original?"),
				message: __("The crop, turns and flips are removed, and the image is as it was uploaded."),
				confirmLabel: __("Restore original")
			})) return;
			saving.value = true;
			try {
				const media = (await api.post(`/media/${props.media.id}/restore`)).data;
				emit("saved", media);
				open.value = false;
				notify.success(__("The original is back."));
			} catch (e) {
				notify.error(e);
			} finally {
				saving.value = false;
			}
		}
		return (_ctx, _push, _parent, _attrs) => {
			_push(ssrRenderComponent(unref(KModal), mergeProps({
				open: open.value,
				"onUpdate:open": ($event) => open.value = $event,
				title: unref(__)("Edit image"),
				size: "xl"
			}, _attrs), {
				footer: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(`<div class="k-form__actions k-imgedit__actions"${_scopeId}><span class="k-muted k-small k-num k-imgedit__size"${_scopeId}>${ssrInterpolate(unref(__)("Result: {w} × {h} pixels", {
							w: result.value[0],
							h: result.value[1]
						}))}</span>`);
						if (__props.media.edit) _push(ssrRenderComponent(unref(KButton), {
							variant: "ghost",
							icon: "arrow-u-up-left",
							disabled: saving.value,
							onClick: restore
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Restore original"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Restore original")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						else _push(`<!---->`);
						_push(ssrRenderComponent(unref(KButton), {
							variant: "ghost",
							disabled: saving.value || !source.value.url,
							onClick: reset
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Reset"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Reset")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						_push(ssrRenderComponent(unref(KButton), { onClick: ($event) => open.value = false }, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Cancel"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Cancel")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						_push(ssrRenderComponent(unref(KButton), {
							variant: "primary",
							loading: saving.value,
							disabled: unchanged.value || !source.value.url,
							onClick: save
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Save image"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Save image")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						_push(`</div>`);
					} else return [createVNode("div", { class: "k-form__actions k-imgedit__actions" }, [
						createVNode("span", { class: "k-muted k-small k-num k-imgedit__size" }, toDisplayString(unref(__)("Result: {w} × {h} pixels", {
							w: result.value[0],
							h: result.value[1]
						})), 1),
						__props.media.edit ? (openBlock(), createBlock(unref(KButton), {
							key: 0,
							variant: "ghost",
							icon: "arrow-u-up-left",
							disabled: saving.value,
							onClick: restore
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Restore original")), 1)]),
							_: 1
						}, 8, ["disabled"])) : createCommentVNode("v-if", true),
						createVNode(unref(KButton), {
							variant: "ghost",
							disabled: saving.value || !source.value.url,
							onClick: reset
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Reset")), 1)]),
							_: 1
						}, 8, ["disabled"]),
						createVNode(unref(KButton), { onClick: ($event) => open.value = false }, {
							default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Cancel")), 1)]),
							_: 1
						}, 8, ["onClick"]),
						createVNode(unref(KButton), {
							variant: "primary",
							loading: saving.value,
							disabled: unchanged.value || !source.value.url,
							onClick: save
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Save image")), 1)]),
							_: 1
						}, 8, ["loading", "disabled"])
					])];
				}),
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(`<div class="k-imgedit"${_scopeId}><div class="k-imgedit__tools" role="toolbar"${ssrRenderAttr("aria-label", unref(__)("Image tools"))}${_scopeId}>`);
						_push(ssrRenderComponent(unref(KButton), {
							size: "sm",
							variant: "ghost",
							icon: "arrow-counter-clockwise",
							onClick: ($event) => turn(false)
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Turn left"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Turn left")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						_push(ssrRenderComponent(unref(KButton), {
							size: "sm",
							variant: "ghost",
							icon: "arrow-clockwise",
							onClick: ($event) => turn(true)
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Turn right"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Turn right")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						_push(ssrRenderComponent(unref(KButton), {
							size: "sm",
							variant: "ghost",
							icon: "flip-horizontal",
							onClick: ($event) => flip(true)
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Flip horizontally"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Flip horizontally")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						_push(ssrRenderComponent(unref(KButton), {
							size: "sm",
							variant: "ghost",
							icon: "flip-vertical",
							onClick: ($event) => flip(false)
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Flip vertically"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Flip vertically")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						_push(ssrRenderComponent(unref(KField), {
							label: unref(__)("Shape"),
							class: "k-imgedit__aspect"
						}, {
							default: withCtx(({ id }, _push, _parent, _scopeId) => {
								if (_push) _push(ssrRenderComponent(unref(KSelect), {
									id,
									"model-value": aspect.value,
									options: ASPECTS,
									"onUpdate:modelValue": chooseAspect
								}, null, _parent, _scopeId));
								else return [createVNode(unref(KSelect), {
									id,
									"model-value": aspect.value,
									options: ASPECTS,
									"onUpdate:modelValue": chooseAspect
								}, null, 8, ["id", "model-value"])];
							}),
							_: 1
						}, _parent, _scopeId));
						_push(`</div>`);
						if (!source.value.url) _push(ssrRenderComponent(unref(KAlert), { tone: "warning" }, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("The original can't be shown from this storage, so the image can't be edited here. Restoring the original still works."))}`);
								else return [createTextVNode(toDisplayString(unref(__)("The original can't be shown from this storage, so the image can't be edited here. Restoring the original still works.")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						else {
							_push(`<div class="k-imgedit__frame"${_scopeId}><div class="k-imgedit__stage" style="${ssrRenderStyle({
								width: `${stage.value.width}px`,
								height: `${stage.value.height}px`
							})}"${_scopeId}><img class="k-imgedit__picture"${ssrRenderAttr("src", source.value.url)} style="${ssrRenderStyle(picture.value)}" alt="" draggable="false"${_scopeId}><div class="k-imgedit__crop" style="${ssrRenderStyle(box.value)}" tabindex="0" role="group"${ssrRenderAttr("aria-label", unref(__)("Crop area: drag to move it, or use the arrow keys. Shift moves further."))}${_scopeId}><!--[-->`);
							ssrRenderList(handles.value, (h) => {
								_push(`<button type="button" class="${ssrRenderClass([`is-${h}`, "k-imgedit__handle"])}"${ssrRenderAttr("aria-label", unref(__)("{edge}: drag, or use the arrow keys, to resize the crop.", { edge: HANDLE_LABEL[h] }))}${_scopeId}></button>`);
							});
							_push(`<!--]--></div></div></div>`);
						}
						if (problem.value) _push(ssrRenderComponent(unref(KAlert), { tone: "danger" }, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(problem.value)}`);
								else return [createTextVNode(toDisplayString(problem.value), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						else _push(`<!---->`);
						_push(`</div>`);
					} else return [createVNode("div", { class: "k-imgedit" }, [
						createVNode("div", {
							class: "k-imgedit__tools",
							role: "toolbar",
							"aria-label": unref(__)("Image tools")
						}, [
							createVNode(unref(KButton), {
								size: "sm",
								variant: "ghost",
								icon: "arrow-counter-clockwise",
								onClick: ($event) => turn(false)
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Turn left")), 1)]),
								_: 1
							}, 8, ["onClick"]),
							createVNode(unref(KButton), {
								size: "sm",
								variant: "ghost",
								icon: "arrow-clockwise",
								onClick: ($event) => turn(true)
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Turn right")), 1)]),
								_: 1
							}, 8, ["onClick"]),
							createVNode(unref(KButton), {
								size: "sm",
								variant: "ghost",
								icon: "flip-horizontal",
								onClick: ($event) => flip(true)
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Flip horizontally")), 1)]),
								_: 1
							}, 8, ["onClick"]),
							createVNode(unref(KButton), {
								size: "sm",
								variant: "ghost",
								icon: "flip-vertical",
								onClick: ($event) => flip(false)
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Flip vertically")), 1)]),
								_: 1
							}, 8, ["onClick"]),
							createVNode(unref(KField), {
								label: unref(__)("Shape"),
								class: "k-imgedit__aspect"
							}, {
								default: withCtx(({ id }) => [createVNode(unref(KSelect), {
									id,
									"model-value": aspect.value,
									options: ASPECTS,
									"onUpdate:modelValue": chooseAspect
								}, null, 8, ["id", "model-value"])]),
								_: 1
							}, 8, ["label"])
						], 8, ["aria-label"]),
						!source.value.url ? (openBlock(), createBlock(unref(KAlert), {
							key: 0,
							tone: "warning"
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("The original can't be shown from this storage, so the image can't be edited here. Restoring the original still works.")), 1)]),
							_: 1
						})) : (openBlock(), createBlock("div", {
							key: 1,
							ref_key: "frame",
							ref: frame,
							class: "k-imgedit__frame"
						}, [createVNode("div", {
							class: "k-imgedit__stage",
							style: {
								width: `${stage.value.width}px`,
								height: `${stage.value.height}px`
							},
							onPointermove: moveDrag,
							onPointerup: endDrag,
							onPointercancel: endDrag
						}, [createVNode("img", {
							class: "k-imgedit__picture",
							src: source.value.url,
							style: picture.value,
							alt: "",
							draggable: "false"
						}, null, 12, ["src"]), createVNode("div", {
							class: "k-imgedit__crop",
							style: box.value,
							tabindex: "0",
							role: "group",
							"aria-label": unref(__)("Crop area: drag to move it, or use the arrow keys. Shift moves further."),
							onPointerdown: withModifiers(($event) => startDrag($event, "move"), ["self"]),
							onKeydown: withModifiers(($event) => nudge($event, "move"), ["self"])
						}, [(openBlock(true), createBlock(Fragment, null, renderList(handles.value, (h) => {
							return openBlock(), createBlock("button", {
								key: h,
								type: "button",
								class: ["k-imgedit__handle", `is-${h}`],
								"aria-label": unref(__)("{edge}: drag, or use the arrow keys, to resize the crop.", { edge: HANDLE_LABEL[h] }),
								onPointerdown: ($event) => startDrag($event, h),
								onKeydown: ($event) => nudge($event, h)
							}, null, 42, [
								"aria-label",
								"onPointerdown",
								"onKeydown"
							]);
						}), 128))], 44, [
							"aria-label",
							"onPointerdown",
							"onKeydown"
						])], 36)], 512)),
						problem.value ? (openBlock(), createBlock(unref(KAlert), {
							key: 2,
							tone: "danger"
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(problem.value), 1)]),
							_: 1
						})) : createCommentVNode("v-if", true)
					])];
				}),
				_: 1
			}, _parent));
		};
	}
};
var _sfc_setup$1 = _sfc_main$1.setup;
_sfc_main$1.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/components/media/ImageEditor.vue");
	return _sfc_setup$1 ? _sfc_setup$1(props, ctx) : void 0;
};
// core/resources/js/pages/admin/media/Index.vue
var _sfc_main = /*@__PURE__*/ Object.assign({ layout: "Admin" }, {
	__name: "Index",
	__ssrInlineRender: true,
	setup(__props) {
		const api = useApi();
		const limits = useResource("/media/limits");
		const zone = ref(null);
		const kind = ref("");
		const search = ref("");
		const mine = ref(false);
		const items = ref([]);
		const meta = ref({
			page: 1,
			last_page: 1,
			total: 0
		});
		const loading = ref(false);
		const failed = ref(null);
		let seq = 0;
		async function load(reset = true) {
			const mine = ++seq;
			loading.value = true;
			failed.value = null;
			try {
				const res = await api.get("/media", {
					page: reset ? 1 : meta.value.page + 1,
					per_page: 48,
					kind: kind.value || void 0,
					search: search.value || void 0,
					mine: mine.value || void 0
				});
				if (mine !== seq) return;
				const shown = new Set(items.value.map((m) => m.id));
				items.value = reset ? res.data : [...items.value, ...res.data.filter((m) => !shown.has(m.id))];
				meta.value = res.meta;
			} catch (e) {
				if (mine === seq) failed.value = e;
			} finally {
				if (mine === seq) loading.value = false;
			}
		}
		let timer;
		watch(search, () => {
			clearTimeout(timer);
			timer = setTimeout(load, 250);
		});
		watch([kind, mine], () => load());
		load();
		const tabs = computed(() => [{
			id: "",
			label: __("All")
		}, ...(limits.data.value?.kinds ?? ["image"]).map((k) => ({
			id: k,
			label: {
				image: __("Images"),
				document: __("Documents"),
				audio: __("Audio"),
				video: __("Video"),
				archive: __("Archives")
			}[k]
		}))]);
		function uploaded(m) {
			if ((!kind.value || kind.value === m.kind) && !search.value) {
				items.value = [m, ...items.value];
				meta.value = {
					...meta.value,
					total: meta.value.total + 1
				};
			}
		}
		const current = ref(null);
		const form = reactive({
			title: "",
			alt: "",
			caption: "",
			focus: [.5, .5]
		});
		const errors = ref({});
		const saving = ref(false);
		const mayLeave = async () => !dirty.value || await confirm({
			title: __("Discard unsaved changes?"),
			message: __("Your changes to “{title}” are not saved.", { title: current.value.title }),
			confirmLabel: __("Discard")
		});
		async function close() {
			if (await mayLeave()) current.value = null;
		}
		async function open(m) {
			if (!await mayLeave()) return;
			try {
				current.value = (await api.get(`/media/${m.id}`)).data;
				fill();
			} catch (e) {
				notify.error(e);
			}
		}
		const page = usePage();
		onMounted(() => {
			const id = Number(new URLSearchParams(page.url.split("?")[1] ?? "").get("file"));
			if (Number.isInteger(id) && id > 0) open({ id });
		});
		function fill() {
			Object.assign(form, {
				title: current.value.title,
				alt: current.value.alt ?? "",
				caption: current.value.caption ?? "",
				focus: [...current.value.focus ?? [.5, .5]]
			});
			errors.value = {};
		}
		const focusMoved = computed(() => !!current.value?.focus && (form.focus[0] !== current.value.focus[0] || form.focus[1] !== current.value.focus[1]));
		const dirty = computed(() => !!current.value && (form.title !== current.value.title || form.alt !== (current.value.alt ?? "") || form.caption !== (current.value.caption ?? "") || focusMoved.value));
		async function save() {
			saving.value = true;
			errors.value = {};
			try {
				const body = {
					title: form.title,
					alt: form.alt || null,
					caption: form.caption || null
				};
				if (focusMoved.value) body.focus = form.focus;
				current.value = (await api.patch(`/media/${current.value.id}`, body)).data;
				items.value = items.value.map((m) => m.id === current.value.id ? {
					...m,
					...current.value
				} : m);
				fill();
				notify.success(__("Saved."));
			} catch (e) {
				if (e instanceof ApiError && e.status === 422) errors.value = e.fieldErrors();
				else notify.error(e);
			} finally {
				saving.value = false;
			}
		}
		async function remove() {
			const m = current.value;
			const used = m.usage?.length ?? 0;
			if (!await confirm({
				title: __("Delete “{title}”?", { title: m.title }),
				message: used ? __("{n} entries use this file. They will show a missing file until you pick another one.", { n: used }) : __("The file is removed from storage. This cannot be undone."),
				confirmLabel: used ? __("Delete anyway") : __("Delete file"),
				danger: true
			})) return;
			try {
				await api.delete(`/media/${m.id}`, { query: { force: used > 0 } });
				items.value = items.value.filter((x) => x.id !== m.id);
				meta.value = {
					...meta.value,
					total: Math.max(0, meta.value.total - 1)
				};
				current.value = null;
				notify.success(__("Deleted “{title}”.", { title: m.title }));
			} catch (e) {
				notify.error(e);
			}
		}
		async function copyUrl() {
			try {
				await navigator.clipboard.writeText(new URL(current.value.url, window.location.origin).href);
				notify.success(__("Address copied."));
			} catch {
				notify.error(__("Could not copy. Select the address and copy it yourself."));
			}
		}
		const STATUS = {
			draft: __("Draft"),
			pending: __("In review"),
			scheduled: __("Scheduled"),
			private: __("Private"),
			published: __("Published"),
			trash: __("In the trash")
		};
		const isImage = computed(() => current.value?.kind === "image");
		const editing = ref(false);
		function edited(media) {
			current.value = media;
			items.value = items.value.map((m) => m.id === media.id ? {
				...m,
				...media
			} : m);
			form.focus = [...media.focus ?? [.5, .5]];
		}
		const canFocus = computed(() => isImage.value && current.value?.can.edit && !!current.value?.sizes);
		const round = (n) => Math.round(Math.min(1, Math.max(0, n)) * 100) / 100;
		function pickFocus(event) {
			if (!canFocus.value) return;
			const box = event.currentTarget.getBoundingClientRect();
			form.focus = [round((event.clientX - box.left) / box.width), round((event.clientY - box.top) / box.height)];
		}
		function nudgeFocus(event) {
			const step = event.shiftKey ? .1 : .02;
			const d = {
				ArrowLeft: [-step, 0],
				ArrowRight: [step, 0],
				ArrowUp: [0, -step],
				ArrowDown: [0, step]
			}[event.key];
			if (!d) return;
			event.preventDefault();
			form.focus = [round(form.focus[0] + d[0]), round(form.focus[1] + d[1])];
		}
		const focusLabel = computed(() => __("Focal point: {x}% across, {y}% down. Arrow keys move it.", {
			x: Math.round(form.focus[0] * 100),
			y: Math.round(form.focus[1] * 100)
		}));
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<!--[-->`);
			_push(ssrRenderComponent(unref(Head), { title: unref(__)("Media") }, null, _parent));
			_push(ssrRenderComponent(unref(KPageHeader), {
				title: unref(__)("Media"),
				description: unref(__)("Images and files for your pages and posts. Drop files anywhere on this page to upload them.")
			}, {
				actions: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						if (unref(limits).data.value?.can_upload) _push(ssrRenderComponent(unref(KButton), {
							variant: "primary",
							icon: "upload",
							onClick: ($event) => zone.value?.choose()
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Upload"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Upload")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						else _push(`<!---->`);
					} else return [unref(limits).data.value?.can_upload ? (openBlock(), createBlock(unref(KButton), {
						key: 0,
						variant: "primary",
						icon: "upload",
						onClick: ($event) => zone.value?.choose()
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Upload")), 1)]),
						_: 1
					}, 8, ["onClick"])) : createCommentVNode("v-if", true)];
				}),
				_: 1
			}, _parent));
			if (unref(limits).data.value?.can_upload) _push(ssrRenderComponent(unref(KUploadZone), {
				ref_key: "zone",
				ref: zone,
				page: "",
				class: "k-media-upload",
				onUploaded: uploaded
			}, null, _parent));
			else _push(`<!---->`);
			_push(`<div class="${ssrRenderClass([{ "has-detail": current.value }, "k-media-layout"])}">`);
			_push(ssrRenderComponent(unref(KCard), {
				coord: "A1",
				flush: "",
				class: "k-media-browser"
			}, {
				title: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(`${ssrInterpolate(unref(__)("Library"))} <span class="k-muted k-small k-num"${_scopeId}>${ssrInterpolate(meta.value.total)}</span>`);
					else return [createTextVNode(toDisplayString(unref(__)("Library")) + " ", 1), createVNode("span", { class: "k-muted k-small k-num" }, toDisplayString(meta.value.total), 1)];
				}),
				actions: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(`<label class="k-choice k-media-mine"${_scopeId}><input${ssrIncludeBooleanAttr(Array.isArray(mine.value) ? ssrLooseContain(mine.value, null) : mine.value) ? " checked" : ""} type="checkbox"${_scopeId}> <span${_scopeId}>${ssrInterpolate(unref(__)("Only mine"))}</span></label><label class="k-inline-field"${_scopeId}><span${_scopeId}>${ssrInterpolate(unref(__)("Search"))}</span><input${ssrRenderAttr("value", search.value)} type="search" class="k-input k-media-search"${ssrRenderAttr("aria-label", unref(__)("Search the media library"))}${_scopeId}></label>`);
					else return [createVNode("label", { class: "k-choice k-media-mine" }, [
						withDirectives(createVNode("input", {
							"onUpdate:modelValue": ($event) => mine.value = $event,
							type: "checkbox"
						}, null, 8, ["onUpdate:modelValue"]), [[vModelCheckbox, mine.value]]),
						createTextVNode(),
						createVNode("span", null, toDisplayString(unref(__)("Only mine")), 1)
					]), createVNode("label", { class: "k-inline-field" }, [createVNode("span", null, toDisplayString(unref(__)("Search")), 1), withDirectives(createVNode("input", {
						"onUpdate:modelValue": ($event) => search.value = $event,
						type: "search",
						class: "k-input k-media-search",
						"aria-label": unref(__)("Search the media library")
					}, null, 8, ["onUpdate:modelValue", "aria-label"]), [[vModelText, search.value]])])];
				}),
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(`<div class="k-media-browser__tabs"${_scopeId}>`);
						_push(ssrRenderComponent(unref(KTabs), {
							modelValue: kind.value,
							"onUpdate:modelValue": ($event) => kind.value = $event,
							tabs: tabs.value,
							label: unref(__)("Kind of file")
						}, null, _parent, _scopeId));
						_push(`</div>`);
						if (failed.value) _push(ssrRenderComponent(unref(KAlert), { tone: "danger" }, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(failed.value.message)}`);
								else return [createTextVNode(toDisplayString(failed.value.message), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						else _push(`<!---->`);
						if (items.value.length) _push(ssrRenderComponent(unref(KMediaGrid), {
							items: items.value,
							current: current.value?.id ?? null,
							label: unref(__)("Media library"),
							onPick: open
						}, null, _parent, _scopeId));
						else if (!loading.value && !failed.value) _push(ssrRenderComponent(unref(KEmpty), {
							icon: "images",
							title: search.value || kind.value || mine.value ? unref(__)("Nothing matches") : unref(__)("No files yet")
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`<p${_scopeId}>${ssrInterpolate(search.value || kind.value || mine.value ? unref(__)("Try another search or kind.") : unref(__)("Upload images and documents here, then use them in your pages and posts."))}</p>`);
								else return [createVNode("p", null, toDisplayString(search.value || kind.value || mine.value ? unref(__)("Try another search or kind.") : unref(__)("Upload images and documents here, then use them in your pages and posts.")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						else _push(`<!---->`);
						if (meta.value.page < meta.value.last_page) {
							_push(`<div class="k-media-more"${_scopeId}>`);
							_push(ssrRenderComponent(unref(KButton), {
								loading: loading.value,
								onClick: ($event) => load(false)
							}, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate(unref(__)("Show more"))}`);
									else return [createTextVNode(toDisplayString(unref(__)("Show more")), 1)];
								}),
								_: 1
							}, _parent, _scopeId));
							_push(`</div>`);
						} else _push(`<!---->`);
					} else return [
						createVNode("div", { class: "k-media-browser__tabs" }, [createVNode(unref(KTabs), {
							modelValue: kind.value,
							"onUpdate:modelValue": ($event) => kind.value = $event,
							tabs: tabs.value,
							label: unref(__)("Kind of file")
						}, null, 8, [
							"modelValue",
							"onUpdate:modelValue",
							"tabs",
							"label"
						])]),
						failed.value ? (openBlock(), createBlock(unref(KAlert), {
							key: 0,
							tone: "danger"
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(failed.value.message), 1)]),
							_: 1
						})) : createCommentVNode("v-if", true),
						items.value.length ? (openBlock(), createBlock(unref(KMediaGrid), {
							key: 1,
							items: items.value,
							current: current.value?.id ?? null,
							label: unref(__)("Media library"),
							onPick: open
						}, null, 8, [
							"items",
							"current",
							"label"
						])) : !loading.value && !failed.value ? (openBlock(), createBlock(unref(KEmpty), {
							key: 2,
							icon: "images",
							title: search.value || kind.value || mine.value ? unref(__)("Nothing matches") : unref(__)("No files yet")
						}, {
							default: withCtx(() => [createVNode("p", null, toDisplayString(search.value || kind.value || mine.value ? unref(__)("Try another search or kind.") : unref(__)("Upload images and documents here, then use them in your pages and posts.")), 1)]),
							_: 1
						}, 8, ["title"])) : createCommentVNode("v-if", true),
						meta.value.page < meta.value.last_page ? (openBlock(), createBlock("div", {
							key: 3,
							class: "k-media-more"
						}, [createVNode(unref(KButton), {
							loading: loading.value,
							onClick: ($event) => load(false)
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Show more")), 1)]),
							_: 1
						}, 8, ["loading", "onClick"])])) : createCommentVNode("v-if", true)
					];
				}),
				_: 1
			}, _parent));
			if (current.value) _push(ssrRenderComponent(unref(KCard), {
				coord: "B1",
				class: "k-media-detail",
				title: current.value.title
			}, createSlots({
				actions: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(ssrRenderComponent(unref(KButton), {
						size: "sm",
						variant: "ghost",
						icon: "x",
						"aria-label": unref(__)("Close details"),
						onClick: close
					}, null, _parent, _scopeId));
					else return [createVNode(unref(KButton), {
						size: "sm",
						variant: "ghost",
						icon: "x",
						"aria-label": unref(__)("Close details"),
						onClick: close
					}, null, 8, ["aria-label"])];
				}),
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(`<figure class="k-media-preview"${_scopeId}>`);
						if (isImage.value && current.value.url) {
							_push(`<span class="${ssrRenderClass([{ "is-editable": canFocus.value }, "k-media-focus"])}"${_scopeId}>`);
							_push(ssrRenderComponent(unref(KImage), {
								media: current.value,
								size: "medium",
								sizes: "380px",
								alt: current.value.alt || ""
							}, null, _parent, _scopeId));
							if (canFocus.value) _push(`<button type="button" class="k-media-focus__dot" style="${ssrRenderStyle({
								left: `${form.focus[0] * 100}%`,
								top: `${form.focus[1] * 100}%`
							})}"${ssrRenderAttr("aria-label", focusLabel.value)}${_scopeId}></button>`);
							else _push(`<!---->`);
							_push(`</span>`);
						} else if (current.value.sizes?.medium || current.value.sizes?.thumb) {
							_push(`<span class="k-media-preview__page"${_scopeId}>`);
							_push(ssrRenderComponent(unref(KImage), {
								media: current.value,
								size: current.value.sizes.medium ? "medium" : "thumb",
								sizes: "380px",
								alt: unref(__)("First page of {name}", { name: current.value.title })
							}, null, _parent, _scopeId));
							_push(`</span>`);
						} else {
							_push(`<span class="k-media-preview__file"${_scopeId}>`);
							_push(ssrRenderComponent(unref(KIcon), { name: "file" }, null, _parent, _scopeId));
							_push(`<span${_scopeId}>${ssrInterpolate(current.value.ext.toUpperCase())}</span></span>`);
						}
						_push(`</figure>`);
						if (current.value.can.edit_image || current.value.edit) {
							_push(`<div class="k-media-edit"${_scopeId}>`);
							if (current.value.can.edit_image) _push(ssrRenderComponent(unref(KButton), {
								size: "sm",
								icon: "crop",
								onClick: ($event) => editing.value = true
							}, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate(unref(__)("Edit image"))}`);
									else return [createTextVNode(toDisplayString(unref(__)("Edit image")), 1)];
								}),
								_: 1
							}, _parent, _scopeId));
							else _push(`<!---->`);
							if (current.value.edit) _push(`<span class="k-muted k-small"${_scopeId}>${ssrInterpolate(unref(__)("Cropped, turned or flipped. The original is kept."))}</span>`);
							else _push(`<!---->`);
							_push(`</div>`);
						} else _push(`<!---->`);
						if (current.value.can.edit_image) _push(ssrRenderComponent(_sfc_main$1, {
							open: editing.value,
							"onUpdate:open": ($event) => editing.value = $event,
							media: current.value,
							onSaved: edited
						}, null, _parent, _scopeId));
						else _push(`<!---->`);
						if (canFocus.value) _push(`<p class="k-muted k-small k-media-focus__help"${_scopeId}>${ssrInterpolate(unref(__)("Click the image to choose what must stay in view when it is cropped."))}</p>`);
						else _push(`<!---->`);
						_push(`<dl class="k-media-facts"${_scopeId}><div${_scopeId}><dt${_scopeId}>${ssrInterpolate(unref(__)("File"))}</dt><dd class="k-mono-input"${_scopeId}>${ssrInterpolate(current.value.filename)}</dd></div><div${_scopeId}><dt${_scopeId}>${ssrInterpolate(unref(__)("Size"))}</dt><dd class="k-num"${_scopeId}>${ssrInterpolate(unref(formatBytes)(current.value.size))}`);
						if (current.value.width) _push(`<!--[--> · ${ssrInterpolate(current.value.width)} × ${ssrInterpolate(current.value.height)}<!--]-->`);
						else _push(`<!---->`);
						_push(`</dd></div><div${_scopeId}><dt${_scopeId}>${ssrInterpolate(unref(__)("Uploaded"))}</dt><dd${_scopeId}>${ssrInterpolate(unref(formatDateTime)(current.value.created_at))}`);
						if (current.value.uploader) _push(`<!--[--> · ${ssrInterpolate(current.value.uploader.name)}<!--]-->`);
						else _push(`<!---->`);
						_push(`</dd></div>`);
						if (current.value.url) {
							_push(`<div${_scopeId}><dt${_scopeId}>${ssrInterpolate(unref(__)("Address"))}</dt><dd class="k-media-url"${_scopeId}><a${ssrRenderAttr("href", current.value.url)} target="_blank" rel="noopener" class="k-mono-input"${_scopeId}>${ssrInterpolate(current.value.url)}</a>`);
							_push(ssrRenderComponent(unref(KButton), {
								size: "sm",
								variant: "ghost",
								icon: "clipboard",
								"aria-label": unref(__)("Copy address"),
								onClick: copyUrl
							}, null, _parent, _scopeId));
							_push(`</dd></div>`);
						} else _push(`<!---->`);
						_push(`</dl><form id="k-media-form" class="k-form"${_scopeId}>`);
						if (isImage.value) _push(ssrRenderComponent(unref(KField), {
							label: unref(__)("Alt text"),
							help: unref(__)("Describe the image for people who can't see it. Leave empty only if it is purely decorative."),
							error: errors.value.alt ?? ""
						}, {
							default: withCtx(({ id, describedBy }, _push, _parent, _scopeId) => {
								if (_push) _push(`<textarea${ssrRenderAttr("id", id)} class="k-input k-textarea" rows="2" maxlength="1000"${ssrIncludeBooleanAttr(!current.value.can.edit) ? " disabled" : ""}${ssrRenderAttr("aria-describedby", describedBy)}${_scopeId}>${ssrInterpolate(form.alt)}</textarea>`);
								else return [withDirectives(createVNode("textarea", {
									id,
									"onUpdate:modelValue": ($event) => form.alt = $event,
									class: "k-input k-textarea",
									rows: "2",
									maxlength: "1000",
									disabled: !current.value.can.edit,
									"aria-describedby": describedBy
								}, null, 8, [
									"id",
									"onUpdate:modelValue",
									"disabled",
									"aria-describedby"
								]), [[vModelText, form.alt]])];
							}),
							_: 1
						}, _parent, _scopeId));
						else _push(`<!---->`);
						_push(ssrRenderComponent(unref(KField), {
							label: unref(__)("Title"),
							error: errors.value.title ?? ""
						}, {
							default: withCtx(({ id }, _push, _parent, _scopeId) => {
								if (_push) _push(ssrRenderComponent(unref(KInput), {
									id,
									modelValue: form.title,
									"onUpdate:modelValue": ($event) => form.title = $event,
									maxlength: "191",
									disabled: !current.value.can.edit
								}, null, _parent, _scopeId));
								else return [createVNode(unref(KInput), {
									id,
									modelValue: form.title,
									"onUpdate:modelValue": ($event) => form.title = $event,
									maxlength: "191",
									disabled: !current.value.can.edit
								}, null, 8, [
									"id",
									"modelValue",
									"onUpdate:modelValue",
									"disabled"
								])];
							}),
							_: 1
						}, _parent, _scopeId));
						_push(ssrRenderComponent(unref(KField), {
							label: unref(__)("Caption"),
							help: unref(__)("Themes may show it under the file."),
							error: errors.value.caption ?? ""
						}, {
							default: withCtx(({ id }, _push, _parent, _scopeId) => {
								if (_push) _push(`<textarea${ssrRenderAttr("id", id)} class="k-input k-textarea" rows="2" maxlength="2000"${ssrIncludeBooleanAttr(!current.value.can.edit) ? " disabled" : ""}${_scopeId}>${ssrInterpolate(form.caption)}</textarea>`);
								else return [withDirectives(createVNode("textarea", {
									id,
									"onUpdate:modelValue": ($event) => form.caption = $event,
									class: "k-input k-textarea",
									rows: "2",
									maxlength: "2000",
									disabled: !current.value.can.edit
								}, null, 8, [
									"id",
									"onUpdate:modelValue",
									"disabled"
								]), [[vModelText, form.caption]])];
							}),
							_: 1
						}, _parent, _scopeId));
						_push(`</form><section class="k-media-usage"${ssrRenderAttr("aria-label", unref(__)("Where it is used"))}${_scopeId}><h3 class="k-media-usage__title"${_scopeId}>${ssrInterpolate(unref(__)("Used in"))}</h3>`);
						if (current.value.usage?.length) {
							_push(`<ul${_scopeId}><!--[-->`);
							ssrRenderList(current.value.usage, (u) => {
								_push(`<li${_scopeId}>`);
								_push(ssrRenderComponent(unref(Link), { href: `/admin/content/${u.type}/${u.id}` }, {
									default: withCtx((_, _push, _parent, _scopeId) => {
										if (_push) _push(`${ssrInterpolate(u.title || unref(__)("(untitled)"))}`);
										else return [createTextVNode(toDisplayString(u.title || unref(__)("(untitled)")), 1)];
									}),
									_: 2
								}, _parent, _scopeId));
								_push(`<span class="k-muted k-small"${_scopeId}> · ${ssrInterpolate(STATUS[u.status] ?? u.status)}</span></li>`);
							});
							_push(`<!--]--></ul>`);
						} else _push(`<p class="k-muted k-small"${_scopeId}>${ssrInterpolate(unref(__)("Not used in any entry yet."))}</p>`);
						_push(`</section>`);
					} else return [
						createVNode("figure", { class: "k-media-preview" }, [isImage.value && current.value.url ? (openBlock(), createBlock("span", {
							key: 0,
							class: ["k-media-focus", { "is-editable": canFocus.value }],
							onClick: pickFocus
						}, [createVNode(unref(KImage), {
							media: current.value,
							size: "medium",
							sizes: "380px",
							alt: current.value.alt || ""
						}, null, 8, ["media", "alt"]), canFocus.value ? (openBlock(), createBlock("button", {
							key: 0,
							type: "button",
							class: "k-media-focus__dot",
							style: {
								left: `${form.focus[0] * 100}%`,
								top: `${form.focus[1] * 100}%`
							},
							"aria-label": focusLabel.value,
							onClick: withModifiers(() => {}, ["stop"]),
							onKeydown: nudgeFocus
						}, null, 44, ["aria-label", "onClick"])) : createCommentVNode("v-if", true)], 2)) : current.value.sizes?.medium || current.value.sizes?.thumb ? (openBlock(), createBlock("span", {
							key: 1,
							class: "k-media-preview__page"
						}, [createVNode(unref(KImage), {
							media: current.value,
							size: current.value.sizes.medium ? "medium" : "thumb",
							sizes: "380px",
							alt: unref(__)("First page of {name}", { name: current.value.title })
						}, null, 8, [
							"media",
							"size",
							"alt"
						])])) : (openBlock(), createBlock("span", {
							key: 2,
							class: "k-media-preview__file"
						}, [createVNode(unref(KIcon), { name: "file" }), createVNode("span", null, toDisplayString(current.value.ext.toUpperCase()), 1)]))]),
						current.value.can.edit_image || current.value.edit ? (openBlock(), createBlock("div", {
							key: 0,
							class: "k-media-edit"
						}, [current.value.can.edit_image ? (openBlock(), createBlock(unref(KButton), {
							key: 0,
							size: "sm",
							icon: "crop",
							onClick: ($event) => editing.value = true
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Edit image")), 1)]),
							_: 1
						}, 8, ["onClick"])) : createCommentVNode("v-if", true), current.value.edit ? (openBlock(), createBlock("span", {
							key: 1,
							class: "k-muted k-small"
						}, toDisplayString(unref(__)("Cropped, turned or flipped. The original is kept.")), 1)) : createCommentVNode("v-if", true)])) : createCommentVNode("v-if", true),
						current.value.can.edit_image ? (openBlock(), createBlock(_sfc_main$1, {
							key: 1,
							open: editing.value,
							"onUpdate:open": ($event) => editing.value = $event,
							media: current.value,
							onSaved: edited
						}, null, 8, [
							"open",
							"onUpdate:open",
							"media"
						])) : createCommentVNode("v-if", true),
						canFocus.value ? (openBlock(), createBlock("p", {
							key: 2,
							class: "k-muted k-small k-media-focus__help"
						}, toDisplayString(unref(__)("Click the image to choose what must stay in view when it is cropped.")), 1)) : createCommentVNode("v-if", true),
						createVNode("dl", { class: "k-media-facts" }, [
							createVNode("div", null, [createVNode("dt", null, toDisplayString(unref(__)("File")), 1), createVNode("dd", { class: "k-mono-input" }, toDisplayString(current.value.filename), 1)]),
							createVNode("div", null, [createVNode("dt", null, toDisplayString(unref(__)("Size")), 1), createVNode("dd", { class: "k-num" }, [createTextVNode(toDisplayString(unref(formatBytes)(current.value.size)), 1), current.value.width ? (openBlock(), createBlock(Fragment, { key: 0 }, [createTextVNode(" · " + toDisplayString(current.value.width) + " × " + toDisplayString(current.value.height), 1)], 64)) : createCommentVNode("v-if", true)])]),
							createVNode("div", null, [createVNode("dt", null, toDisplayString(unref(__)("Uploaded")), 1), createVNode("dd", null, [createTextVNode(toDisplayString(unref(formatDateTime)(current.value.created_at)), 1), current.value.uploader ? (openBlock(), createBlock(Fragment, { key: 0 }, [createTextVNode(" · " + toDisplayString(current.value.uploader.name), 1)], 64)) : createCommentVNode("v-if", true)])]),
							current.value.url ? (openBlock(), createBlock("div", { key: 0 }, [createVNode("dt", null, toDisplayString(unref(__)("Address")), 1), createVNode("dd", { class: "k-media-url" }, [createVNode("a", {
								href: current.value.url,
								target: "_blank",
								rel: "noopener",
								class: "k-mono-input"
							}, toDisplayString(current.value.url), 9, ["href"]), createVNode(unref(KButton), {
								size: "sm",
								variant: "ghost",
								icon: "clipboard",
								"aria-label": unref(__)("Copy address"),
								onClick: copyUrl
							}, null, 8, ["aria-label"])])])) : createCommentVNode("v-if", true)
						]),
						createVNode("form", {
							id: "k-media-form",
							class: "k-form",
							onSubmit: withModifiers(save, ["prevent"])
						}, [
							isImage.value ? (openBlock(), createBlock(unref(KField), {
								key: 0,
								label: unref(__)("Alt text"),
								help: unref(__)("Describe the image for people who can't see it. Leave empty only if it is purely decorative."),
								error: errors.value.alt ?? ""
							}, {
								default: withCtx(({ id, describedBy }) => [withDirectives(createVNode("textarea", {
									id,
									"onUpdate:modelValue": ($event) => form.alt = $event,
									class: "k-input k-textarea",
									rows: "2",
									maxlength: "1000",
									disabled: !current.value.can.edit,
									"aria-describedby": describedBy
								}, null, 8, [
									"id",
									"onUpdate:modelValue",
									"disabled",
									"aria-describedby"
								]), [[vModelText, form.alt]])]),
								_: 1
							}, 8, [
								"label",
								"help",
								"error"
							])) : createCommentVNode("v-if", true),
							createVNode(unref(KField), {
								label: unref(__)("Title"),
								error: errors.value.title ?? ""
							}, {
								default: withCtx(({ id }) => [createVNode(unref(KInput), {
									id,
									modelValue: form.title,
									"onUpdate:modelValue": ($event) => form.title = $event,
									maxlength: "191",
									disabled: !current.value.can.edit
								}, null, 8, [
									"id",
									"modelValue",
									"onUpdate:modelValue",
									"disabled"
								])]),
								_: 1
							}, 8, ["label", "error"]),
							createVNode(unref(KField), {
								label: unref(__)("Caption"),
								help: unref(__)("Themes may show it under the file."),
								error: errors.value.caption ?? ""
							}, {
								default: withCtx(({ id }) => [withDirectives(createVNode("textarea", {
									id,
									"onUpdate:modelValue": ($event) => form.caption = $event,
									class: "k-input k-textarea",
									rows: "2",
									maxlength: "2000",
									disabled: !current.value.can.edit
								}, null, 8, [
									"id",
									"onUpdate:modelValue",
									"disabled"
								]), [[vModelText, form.caption]])]),
								_: 1
							}, 8, [
								"label",
								"help",
								"error"
							])
						], 32),
						createVNode("section", {
							class: "k-media-usage",
							"aria-label": unref(__)("Where it is used")
						}, [createVNode("h3", { class: "k-media-usage__title" }, toDisplayString(unref(__)("Used in")), 1), current.value.usage?.length ? (openBlock(), createBlock("ul", { key: 0 }, [(openBlock(true), createBlock(Fragment, null, renderList(current.value.usage, (u) => {
							return openBlock(), createBlock("li", { key: `${u.id}-${u.field}` }, [createVNode(unref(Link), { href: `/admin/content/${u.type}/${u.id}` }, {
								default: withCtx(() => [createTextVNode(toDisplayString(u.title || unref(__)("(untitled)")), 1)]),
								_: 2
							}, 1032, ["href"]), createVNode("span", { class: "k-muted k-small" }, " · " + toDisplayString(STATUS[u.status] ?? u.status), 1)]);
						}), 128))])) : (openBlock(), createBlock("p", {
							key: 1,
							class: "k-muted k-small"
						}, toDisplayString(unref(__)("Not used in any entry yet.")), 1))], 8, ["aria-label"])
					];
				}),
				_: 2
			}, [current.value.can.edit ? {
				name: "footer",
				fn: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(`<div class="k-form__actions"${_scopeId}>`);
						_push(ssrRenderComponent(unref(KButton), {
							variant: "ghost",
							icon: "trash",
							class: "k-media-delete",
							onClick: remove
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Delete"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Delete")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						if (dirty.value) _push(ssrRenderComponent(unref(KButton), {
							variant: "ghost",
							onClick: fill
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Cancel"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Cancel")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						else _push(`<!---->`);
						_push(ssrRenderComponent(unref(KButton), {
							type: "submit",
							form: "k-media-form",
							variant: "primary",
							loading: saving.value,
							disabled: !dirty.value
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Save"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Save")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						_push(`</div>`);
					} else return [createVNode("div", { class: "k-form__actions" }, [
						createVNode(unref(KButton), {
							variant: "ghost",
							icon: "trash",
							class: "k-media-delete",
							onClick: remove
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Delete")), 1)]),
							_: 1
						}),
						dirty.value ? (openBlock(), createBlock(unref(KButton), {
							key: 0,
							variant: "ghost",
							onClick: fill
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Cancel")), 1)]),
							_: 1
						})) : createCommentVNode("v-if", true),
						createVNode(unref(KButton), {
							type: "submit",
							form: "k-media-form",
							variant: "primary",
							loading: saving.value,
							disabled: !dirty.value
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Save")), 1)]),
							_: 1
						}, 8, ["loading", "disabled"])
					])];
				}),
				key: "0"
			} : void 0]), _parent));
			else _push(`<!---->`);
			_push(`</div><!--]-->`);
		};
	}
});
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/admin/media/Index.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export { _sfc_main as default };
