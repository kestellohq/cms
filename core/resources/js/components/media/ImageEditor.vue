<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { ApiError, confirm, notify, useApi, __ } from '@kestello/sdk';
import { KAlert, KButton, KField, KModal, KSelect } from '@kestello/ui';
import { dragged, flipped, fromMedia, isIdentity, orientedSize, resultSize, rotated, toBody, withAspect } from './image-edit.js';

/**
 * Crop, turn and flip an image from the media library. Works on the
 * original (shown through an expiring link when the image was edited
 * before), so every save starts from the untouched picture.
 */
const props = defineProps({ media: { type: Object, required: true } });
const open = defineModel('open', { type: Boolean, default: false });
const emit = defineEmits(['saved']);
const api = useApi();

const source = computed(() =>
    props.media.edit
        ? { url: props.media.original?.url ?? null, width: props.media.original?.width ?? 0, height: props.media.original?.height ?? 0 }
        : { url: props.media.url, width: props.media.width ?? 0, height: props.media.height ?? 0 },
);
const state = ref(fromMedia(props.media.edit));
const aspect = ref('');
const saving = ref(false);
const problem = ref('');
watch(open, (value) => {
    if (value) {
        state.value = fromMedia(props.media.edit);
        aspect.value = '';
        problem.value = '';
    }
});

const ASPECTS = [
    { value: '', label: __('Free') },
    { value: 'original', label: __('Original') },
    { value: '1', label: __('Square') },
    { value: '1.3333333', label: '4:3' },
    { value: '1.5', label: '3:2' },
    { value: '1.7777778', label: '16:9' },
    { value: '0.75', label: '3:4' },
];
const oriented = computed(() => orientedSize(source.value.width, source.value.height, state.value.rotate));
const ratio = computed(() => {
    if (aspect.value === 'original') return source.value.width / Math.max(1, source.value.height);
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
    aspect.value = '';
}
const result = computed(() => resultSize(state.value, source.value.width, source.value.height));
const unchanged = computed(() => JSON.stringify(toBody(state.value)) === JSON.stringify(toBody(fromMedia(props.media.edit))));

// ── the stage: the picture fitted into the space, turned with CSS ────
const frame = ref(null);
const room = ref({ width: 0, height: 0 });
let observer = null;
watch(frame, (el) => {
    observer?.disconnect();
    if (!el) return;
    observer = new ResizeObserver(([entry]) => (room.value = { width: entry.contentRect.width, height: entry.contentRect.height }));
    observer.observe(el);
});
onBeforeUnmount(() => observer?.disconnect());
const stage = computed(() => {
    const [w, h] = oriented.value;
    if (!w || !h || !room.value.width) return { width: 0, height: 0 };
    const scale = Math.min(room.value.width / w, room.value.height / h, 1);
    return { width: Math.round(w * scale), height: Math.round(h * scale) };
});
const picture = computed(() => {
    const { width, height } = stage.value;
    const sideways = state.value.rotate % 180 !== 0;
    const s = state.value;
    return {
        width: `${sideways ? height : width}px`,
        height: `${sideways ? width : height}px`,
        // applied right to left: turn first, then mirror on screen, as the server does
        transform: `translate(-50%, -50%) scale(${s.flipH ? -1 : 1}, ${s.flipV ? -1 : 1}) rotate(${s.rotate}deg)`,
    };
});
const box = computed(() => {
    const c = state.value.crop;
    return { left: `${c.x * 100}%`, top: `${c.y * 100}%`, width: `${c.w * 100}%`, height: `${c.h * 100}%` };
});
const HANDLES = ['nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w'];
const handles = computed(() => (ratio.value ? HANDLES.filter((h) => h.length === 2) : HANDLES));
const HANDLE_LABEL = {
    nw: __('Top left corner'),
    n: __('Top edge'),
    ne: __('Top right corner'),
    e: __('Right edge'),
    se: __('Bottom right corner'),
    s: __('Bottom edge'),
    sw: __('Bottom left corner'),
    w: __('Left edge'),
};

let drag = null;
function startDrag(event, handle) {
    if (event.button !== 0) return;
    event.preventDefault();
    event.currentTarget.setPointerCapture?.(event.pointerId);
    drag = { handle, x: event.clientX, y: event.clientY, crop: { ...state.value.crop } };
}
function moveDrag(event) {
    if (!drag || !stage.value.width) return;
    const dx = (event.clientX - drag.x) / stage.value.width;
    const dy = (event.clientY - drag.y) / stage.value.height;
    const [width, height] = oriented.value;
    state.value = { ...state.value, crop: dragged(drag.crop, drag.handle, dx, dy, { width, height, ratio: ratio.value }) };
}
function endDrag() {
    drag = null;
}
function nudge(event, handle) {
    const step = event.shiftKey ? 0.05 : 0.01;
    const d = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] }[event.key];
    if (!d) return;
    event.preventDefault();
    const [width, height] = oriented.value;
    state.value = { ...state.value, crop: dragged(state.value.crop, handle, d[0], d[1], { width, height, ratio: ratio.value }) };
}

// ── saving ───────────────────────────────────────────────────────────
async function save() {
    saving.value = true;
    problem.value = '';
    try {
        const media = (await api.post(`/media/${props.media.id}/edit`, toBody(state.value))).data;
        emit('saved', media);
        open.value = false;
        notify.success(isIdentity(state.value) ? __('The original is back.') : __('Image saved.'));
    } catch (e) {
        problem.value = e instanceof ApiError ? (Object.values(e.fieldErrors())[0] ?? e.message) : String(e);
    } finally {
        saving.value = false;
    }
}
async function restore() {
    const ok = await confirm({
        title: __('Restore the original?'),
        message: __('The crop, turns and flips are removed, and the image is as it was uploaded.'),
        confirmLabel: __('Restore original'),
    });
    if (!ok) return;
    saving.value = true;
    try {
        const media = (await api.post(`/media/${props.media.id}/restore`)).data;
        emit('saved', media);
        open.value = false;
        notify.success(__('The original is back.'));
    } catch (e) {
        notify.error(e);
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <KModal v-model:open="open" :title="__('Edit image')" size="xl">
        <div class="k-imgedit">
            <div class="k-imgedit__tools" role="toolbar" :aria-label="__('Image tools')">
                <KButton size="sm" variant="ghost" icon="arrow-counter-clockwise" @click="turn(false)">{{ __('Turn left') }}</KButton>
                <KButton size="sm" variant="ghost" icon="arrow-clockwise" @click="turn(true)">{{ __('Turn right') }}</KButton>
                <KButton size="sm" variant="ghost" icon="flip-horizontal" @click="flip(true)">{{ __('Flip horizontally') }}</KButton>
                <KButton size="sm" variant="ghost" icon="flip-vertical" @click="flip(false)">{{ __('Flip vertically') }}</KButton>
                <KField v-slot="{ id }" :label="__('Shape')" class="k-imgedit__aspect">
                    <KSelect :id="id" :model-value="aspect" :options="ASPECTS" @update:model-value="chooseAspect" />
                </KField>
            </div>

            <KAlert v-if="!source.url" tone="warning">{{
                __("The original can't be shown from this storage, so the image can't be edited here. Restoring the original still works.")
            }}</KAlert>
            <div v-else ref="frame" class="k-imgedit__frame">
                <div
                    class="k-imgedit__stage"
                    :style="{ width: `${stage.width}px`, height: `${stage.height}px` }"
                    @pointermove="moveDrag"
                    @pointerup="endDrag"
                    @pointercancel="endDrag"
                >
                    <img class="k-imgedit__picture" :src="source.url" :style="picture" alt="" draggable="false" />
                    <div
                        class="k-imgedit__crop"
                        :style="box"
                        tabindex="0"
                        role="group"
                        :aria-label="__('Crop area: drag to move it, or use the arrow keys. Shift moves further.')"
                        @pointerdown.self="startDrag($event, 'move')"
                        @keydown.self="nudge($event, 'move')"
                    >
                        <button
                            v-for="h in handles"
                            :key="h"
                            type="button"
                            class="k-imgedit__handle"
                            :class="`is-${h}`"
                            :aria-label="__('{edge}: drag, or use the arrow keys, to resize the crop.', { edge: HANDLE_LABEL[h] })"
                            @pointerdown="startDrag($event, h)"
                            @keydown="nudge($event, h)"
                        ></button>
                    </div>
                </div>
            </div>
            <KAlert v-if="problem" tone="danger">{{ problem }}</KAlert>
        </div>

        <template #footer>
            <div class="k-form__actions k-imgedit__actions">
                <span class="k-muted k-small k-num k-imgedit__size">{{ __('Result: {w} × {h} pixels', { w: result[0], h: result[1] }) }}</span>
                <KButton v-if="media.edit" variant="ghost" icon="arrow-u-up-left" :disabled="saving" @click="restore">{{ __('Restore original') }}</KButton>
                <KButton variant="ghost" :disabled="saving || !source.url" @click="reset">{{ __('Reset') }}</KButton>
                <KButton @click="open = false">{{ __('Cancel') }}</KButton>
                <KButton variant="primary" :loading="saving" :disabled="unchanged || !source.url" @click="save">{{ __('Save image') }}</KButton>
            </div>
        </template>
    </KModal>
</template>
