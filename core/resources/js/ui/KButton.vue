<script setup>
import { computed } from 'vue';
import { Link } from '@kestello/app';
import KIcon from './KIcon.vue';
import KSpinner from './KSpinner.vue';

const props = defineProps({
    variant: { type: String, default: 'secondary' }, // primary | secondary | danger | ghost
    size: { type: String, default: 'md' }, // sm | md
    type: { type: String, default: 'button' },
    href: { type: String, default: null },
    icon: { type: String, default: null },
    loading: Boolean,
    disabled: Boolean,
    block: Boolean,
});

const classes = computed(() => ['k-btn', `k-btn--${props.variant}`, `k-btn--${props.size}`, { 'k-btn--block': props.block, 'is-loading': props.loading }]);
const external = computed(() => props.href !== null && /^https?:\/\//.test(props.href));
</script>

<template>
    <a v-if="href && external" :href="href" :class="classes" target="_blank" rel="noopener noreferrer">
        <KIcon v-if="icon" :name="icon" /><span><slot /></span>
    </a>
    <Link v-else-if="href" :href="href" :class="classes">
        <KIcon v-if="icon" :name="icon" /><span><slot /></span>
    </Link>
    <button v-else :type="type" :class="classes" :disabled="disabled || loading" :aria-busy="loading ? 'true' : undefined">
        <KSpinner v-if="loading" size="sm" label="" /><KIcon v-else-if="icon" :name="icon" /><span v-if="$slots.default"><slot /></span>
    </button>
</template>
