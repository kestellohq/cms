<script setup>
// The installer runs before the site exists: plain form posts, no router.
// It wears the admin's drawing set, like the sign-in screens.
import { Head } from '@kestello/app';

const props = defineProps({
    step: { type: Number, default: 0 },
    title: { type: String, required: true },
    description: { type: String, default: '' },
});
const STEPS = ['Server', 'Database', 'Site', 'Administrator', 'Install'];
</script>

<template>
    <Head :title="`Install Kestello: ${title}`" raw />
    <main class="k-auth k-auth--wide">
        <div class="k-auth__sheet">
            <div class="k-auth__brand">
                <img :src="'/assets/core/brand/kestello-symbol.svg'" alt="" width="26" height="26" class="k-logo k-logo--light" />
                <img :src="'/assets/core/brand/kestello-symbol-white.svg'" alt="" width="26" height="26" class="k-logo k-logo--dark" />
                <div>
                    <span class="k-auth__site">Kestello</span>
                    <span class="k-sidebar__product">Installation</span>
                </div>
            </div>

            <ol v-if="props.step >= 1 && props.step <= 5" class="k-steps" aria-label="Installation steps">
                <li
                    v-for="(label, i) in STEPS"
                    :key="label"
                    :class="{ 'is-done': i + 1 < props.step, 'is-current': i + 1 === props.step }"
                    :aria-current="i + 1 === props.step ? 'step' : undefined"
                >
                    <span class="k-steps__n">{{ i + 1 }}</span
                    ><span class="k-steps__label">{{ label }}</span>
                </li>
            </ol>

            <header class="k-auth__head">
                <h1 class="k-auth__title">{{ title }}</h1>
                <p v-if="description" class="k-auth__description">{{ description }}</p>
            </header>

            <slot />
        </div>
    </main>
</template>
