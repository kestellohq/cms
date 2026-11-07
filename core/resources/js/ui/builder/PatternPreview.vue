<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * A pattern as the site draws it, shrunk to fit its card: the page at 1200px wide,
 * scaled down. It loads when the card scrolls into view (loading="lazy") and can't
 * be focused or clicked: the card around it is the button.
 */
const props = defineProps({ patternKey: { type: String, required: true } });
const WIDTH = 1200;
const box = ref(null);
const scale = ref(0.25);
const loaded = ref(false);
let observer = null;
onMounted(() => {
    observer = new ResizeObserver(([e]) => {
        scale.value = e.contentRect.width / WIDTH;
    });
    observer.observe(box.value);
});
onBeforeUnmount(() => observer?.disconnect());
const src = `/system/design/canvas?kind=pattern&key=${encodeURIComponent(props.patternKey)}&preview=1`;
</script>

<template>
    <div ref="box" class="k-patterns__preview" :class="{ 'is-loaded': loaded }" aria-hidden="true">
        <iframe
            :src="src"
            loading="lazy"
            tabindex="-1"
            title=""
            :width="WIDTH"
            height="900"
            :style="{ transform: `scale(${scale})` }"
            @load="loaded = true"
        ></iframe>
    </div>
</template>
