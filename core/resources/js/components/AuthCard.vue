<script setup>
// The shell of every sign-in screen: one panel under the navy sign that
// names the site.
import { computed } from 'vue';
import { Head, Link, usePage } from '@kestello/app';
import { __ } from '@kestello/sdk';
import { KAlert } from '@kestello/ui';

const props = defineProps({
    title: { type: String, required: true },
    description: { type: String, default: '' },
});

const page = usePage();
const site = computed(() => page.props.site?.name || 'Kestello');
const flash = computed(() => page.props.flash ?? {});
</script>

<template>
    <Head :title="title" />
    <main class="k-auth">
        <div class="k-auth__sheet">
            <div class="k-auth__brand">
                <img :src="'/assets/core/brand/kestello-symbol.svg'" alt="" width="26" height="26" class="k-logo k-logo--light" />
                <img :src="'/assets/core/brand/kestello-symbol-white.svg'" alt="" width="26" height="26" class="k-logo k-logo--dark" />
                <div>
                    <span class="k-auth__site">{{ site }}</span>
                    <span class="k-sidebar__product">Kestello</span>
                </div>
            </div>

            <header class="k-auth__head">
                <h1 class="k-auth__title">{{ props.title }}</h1>
                <p v-if="description" class="k-auth__description">{{ description }}</p>
            </header>

            <KAlert v-if="flash.success" tone="success">{{ flash.success }}</KAlert>
            <KAlert v-if="flash.error" tone="warning">{{ flash.error }}</KAlert>

            <slot />
        </div>

        <nav class="k-auth__foot" :aria-label="__('More')">
            <slot name="footer"
                ><Link href="/" class="k-link">{{ __('Back to {site}', { site }) }}</Link></slot
            >
        </nav>
    </main>
</template>
