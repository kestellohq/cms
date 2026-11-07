<script setup>
import { computed, ref, watch } from 'vue';
import { Link, usePage } from '@kestello/app';
import { useI18n } from '@kestello/sdk';

const { __ } = useI18n('base');

const page = usePage();
const site = computed(() => page.props.site?.name ?? 'Kestello');
const user = computed(() => page.props.auth?.user ?? null);
const path = computed(() => page.url.split('?')[0]);
const open = ref(false);
const year = new Date().getFullYear();

watch(
    () => page.url,
    () => {
        open.value = false;
    },
);

// Menus from the admin (Appearance > Menus); the sample links until one is assigned.
const fallback = [
    { url: '/', label: __('Home'), target: null, children: [] },
    { url: '/about', label: __('About'), target: null, children: [] },
];
const primary = computed(() => (page.props.menus?.primary?.length ? page.props.menus.primary : fallback));
const footer = computed(() => page.props.menus?.footer ?? []);
const internal = (item) => item.url.startsWith('/') && !item.url.startsWith('//') && !item.target;
const current = (item) => (path.value === item.url || item.children.some(current) ? 'page' : undefined);
</script>

<template>
    <div class="site">
        <a class="site-skip" href="#main">{{ __('Skip to content') }}</a>
        <div v-if="page.props.recoveryMode" class="site-notice" role="status">{{ __('Recovery mode: plugins are disabled.') }}</div>

        <header class="site-header">
            <div class="site-wrap">
                <Link class="site-brand" href="/">{{ site }}</Link>
                <span v-if="page.component === 'Home'" class="site-sample" :title="__('This front page shows sample content. Replace it with your own.')">{{
                    __('Sample content')
                }}</span>
                <button type="button" class="site-menu-btn" :aria-expanded="open ? 'true' : 'false'" aria-controls="site-nav" @click="open = !open">
                    {{ __('Menu') }}
                </button>
                <nav id="site-nav" class="site-nav" :class="{ 'is-open': open }" :aria-label="__('Main')">
                    <div v-for="item in primary" :key="item.url + item.label" class="site-nav__item" :class="{ 'has-children': item.children.length }">
                        <Link v-if="internal(item)" :href="item.url" :aria-current="current(item)">{{ item.label }}</Link>
                        <a v-else :href="item.url" :target="item.target ?? undefined" :rel="item.target ? 'noopener' : undefined">{{ item.label }}</a>
                        <ul v-if="item.children.length" class="site-nav__sub">
                            <li v-for="child in item.children" :key="child.url + child.label">
                                <Link v-if="internal(child)" :href="child.url" :aria-current="path === child.url ? 'page' : undefined">{{ child.label }}</Link>
                                <a v-else :href="child.url" :target="child.target ?? undefined" :rel="child.target ? 'noopener' : undefined">{{
                                    child.label
                                }}</a>
                            </li>
                        </ul>
                    </div>
                    <Link href="/search" class="site-nav__search" :aria-current="path === '/search' ? 'page' : undefined">
                        <svg viewBox="0 0 20 20" width="18" height="18" aria-hidden="true">
                            <circle cx="8.5" cy="8.5" r="5.75" fill="none" stroke="currentColor" stroke-width="2" />
                            <path d="M13 13l4.5 4.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                        </svg>
                        <span>{{ __('Search') }}</span>
                    </Link>
                    <template v-if="user">
                        <Link v-if="user.admin" href="/admin" class="site-nav__account">{{ __('Admin') }}</Link>
                        <Link href="/logout" method="post" as="button" class="site-nav__account">{{ __('Sign out') }}</Link>
                    </template>
                    <Link v-else href="/login" class="site-nav__account">{{ __('Sign in') }}</Link>
                </nav>
            </div>
        </header>

        <main id="main" class="site-main" tabindex="-1"><slot /></main>

        <footer class="site-footer">
            <div class="site-wrap">
                <p>&copy; {{ year }} {{ site }}</p>
                <nav v-if="footer.length" class="site-footer__nav" :aria-label="__('Footer')">
                    <template v-for="item in footer" :key="item.url + item.label">
                        <Link v-if="internal(item)" :href="item.url">{{ item.label }}</Link>
                        <a v-else :href="item.url" :target="item.target ?? undefined" :rel="item.target ? 'noopener' : undefined">{{ item.label }}</a>
                    </template>
                </nav>
                <p>{{ __('Built with') }} <a href="https://kestello.dev" rel="noopener">Kestello</a></p>
            </div>
        </footer>
    </div>
</template>
