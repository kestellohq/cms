<script setup>
// One icon from shared/icons.js or social-icons.js: <Icon :d="ICONS.check" />. It takes the
// colour and size of the text around it. With a label it is announced; without, it is hidden
// from screen readers (the text next to it says what it means).
defineProps({ d: { type: String, default: '' }, label: { type: String, default: '' } });
</script>

<template>
    <svg
        v-if="d"
        class="k-icon-svg"
        viewBox="0 0 256 256"
        width="1em"
        height="1em"
        fill="currentColor"
        focusable="false"
        :role="label ? 'img' : undefined"
        :aria-label="label || undefined"
        :aria-hidden="label ? undefined : 'true'"
    >
        <path :d="d" />
    </svg>
</template>
