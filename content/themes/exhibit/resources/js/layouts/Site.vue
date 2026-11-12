<script setup>
import { computed, ref, watch } from 'vue';
import { Link, usePage } from '@kestello/app';
import { useI18n } from '@kestello/sdk';
import { roomOf } from '../components/rooms.js';

const { __ } = useI18n('exhibit');

const page = usePage();
const site = computed(() => page.props.site?.name ?? 'Kestello');
const path = computed(() => page.url.split('?')[0]);
const prefix = computed(() => page.props.i18n?.prefix ?? '');
const open = ref(false);
watch(
    () => page.url,
    () => (open.value = false),
);

// the wall this page is painted in
const room = computed(() => roomOf(page.component, page.props));

// the numbered rooms: the menu in Appearance > Menus, else the entrance alone
const rooms = computed(() =>
    page.props.menus?.primary?.length ? page.props.menus.primary : [{ url: prefix.value || '/', label: __('Entrance'), target: null, children: [] }],
);
const footer = computed(() => page.props.menus?.footer ?? []);
const languages = computed(() => page.props.i18n?.languages ?? []);
const internal = (item) => item.url.startsWith('/') && !item.url.startsWith('//') && !item.target;
// a room is current on its own address and on every page under it
const current = (item) => {
    const home = prefix.value || '/';
    if (item.url === home) return path.value === home ? 'page' : undefined;
    return path.value === item.url || path.value.startsWith(`${item.url}/`) ? 'page' : undefined;
};
</script>

<template>
    <div class="site" :class="`room-${room}`">
        <a class="site-skip" href="#main">{{ __('Skip to content') }}</a>
        <div v-if="page.props.recoveryMode" class="site-notice" role="status">{{ __('Recovery mode: plugins are disabled.') }}</div>

        <header class="site-header">
            <Link class="site-brand" :href="prefix || '/'">{{ site }}</Link>
            <button type="button" class="site-menu-btn" :aria-expanded="open ? 'true' : 'false'" aria-controls="site-rooms" @click="open = !open">
                {{ __('Rooms') }}
            </button>
            <nav :aria-label="__('Rooms')" class="site-rooms-nav">
                <ol id="site-rooms" class="site-rooms" :class="{ 'is-open': open }">
                    <li v-for="(item, i) in rooms" :key="item.url + item.label">
                        <Link v-if="internal(item)" :href="item.url" :aria-current="current(item)">
                            <span class="site-rooms__no" aria-hidden="true">{{ i + 1 }}</span>
                            <span class="site-rooms__name">{{ item.label }}</span>
                        </Link>
                        <a v-else :href="item.url" :target="item.target ?? undefined" :rel="item.target ? 'noopener' : undefined">
                            <span class="site-rooms__no" aria-hidden="true">{{ i + 1 }}</span>
                            <span class="site-rooms__name">{{ item.label }}</span>
                        </a>
                    </li>
                </ol>
            </nav>
            <!-- each language's own page; a full load, since the menus and words change -->
            <nav v-if="languages.length" class="site-langs" :aria-label="__('Language')">
                <template v-for="l in languages" :key="l.code">
                    <span v-if="l.current" :lang="l.code" aria-current="true" :title="l.name">{{ l.code }}</span>
                    <a v-else :href="l.url" :lang="l.code" :hreflang="l.code" :title="l.name" :aria-label="l.name">{{ l.code }}</a>
                </template>
            </nav>
        </header>

        <main id="main">
            <slot />
        </main>

        <footer class="site-footer">
            <div class="site-footer__grid">
                <span class="site-footer__name">{{ site }}</span>
                <nav v-if="footer.length" :aria-label="__('Footer')">
                    <template v-for="item in footer" :key="item.url + item.label">
                        <Link v-if="internal(item)" :href="item.url">{{ item.label }}</Link>
                        <a v-else :href="item.url" :target="item.target ?? undefined" :rel="item.target ? 'noopener' : undefined">{{ item.label }}</a>
                    </template>
                </nav>
                <p class="site-footer__made">{{ __('Built with') }} <a href="https://kestello.com" rel="noopener">Kestello</a></p>
            </div>
        </footer>
    </div>
</template>
