<script setup>
// The installer runs before the site exists: plain form posts, no router.
// It wears the admin's world: a navy station sign listing the stops, the work beside it.
import { Head } from '@kestello/app';
import { KIcon } from '@kestello/ui';

const props = defineProps({
    step: { type: Number, default: 0 },
    title: { type: String, required: true },
    description: { type: String, default: '' },
});
const STEPS = ['Server check', 'Database', 'Your site', 'Administrator', 'Install'];
</script>

<template>
    <Head :title="`Install Kestello: ${title}`" raw />
    <main class="k-setup">
        <div class="k-setup__frame">
            <aside class="k-setup__rail">
                <div class="k-setup__brand">
                    <img :src="'/assets/core/brand/kestello-symbol.svg'" alt="" width="24" height="24" class="k-logo k-logo--light" />
                    <img :src="'/assets/core/brand/kestello-symbol-white.svg'" alt="" width="24" height="24" class="k-logo k-logo--dark" />
                    <div>
                        <span class="k-setup__name">Kestello</span>
                        <span class="k-setup__sub">Installation</span>
                    </div>
                </div>

                <ol v-if="props.step >= 1" class="k-setup__steps" aria-label="Installation steps">
                    <li
                        v-for="(label, i) in STEPS"
                        :key="label"
                        :class="{ 'is-done': i + 1 < props.step, 'is-current': i + 1 === props.step }"
                        :aria-current="i + 1 === props.step ? 'step' : undefined"
                    >
                        <span class="k-setup__n">
                            <KIcon v-if="i + 1 < props.step" name="check" />
                            <template v-else>{{ i + 1 }}</template>
                        </span>
                        <span class="k-setup__label"> {{ label }}<span v-if="i + 1 < props.step" class="k-sr-only"> (done)</span> </span>
                    </li>
                </ol>

                <p v-if="props.step <= 5" class="k-setup__help">
                    Stuck? The <a href="https://docs.kestello.com/guide/installation" target="_blank" rel="noopener">installation guide</a> covers each step.
                </p>
            </aside>

            <section class="k-setup__work">
                <header class="k-setup__head">
                    <h1 class="k-setup__title">{{ title }}</h1>
                    <p v-if="description" class="k-setup__description">{{ description }}</p>
                </header>

                <slot />
            </section>
        </div>
    </main>
</template>
