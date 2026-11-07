<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Link, usePage } from '@kestello/app';
import { setReauthHandler, requestPassword, useAdmin, useApi, useCan, useUser, __, notify } from '@kestello/sdk';
import { KAvatar, KDialogs, KFinder, KIcon, KToasts, KAlert, KTime } from '@kestello/ui';

/**
 * The admin app frame. Pages opt in with defineOptions({ layout: 'Admin' });
 * it stays mounted while navigating between them, so the sidebar, the
 * notification bell and open toasts survive page changes.
 */
const page = usePage();
const admin = useAdmin();
const user = useUser();
const can = useCan();
const api = useApi();
const site = computed(() => page.props.site?.name ?? 'Kestello');
const flash = computed(() => page.props.flash ?? {});
const path = computed(() => page.url.split('?')[0].replace(/\/$/, '') || '/admin');
const sidebarOpen = ref(false);

// The finder: Ctrl/Cmd+K anywhere, or "/" when not typing.
const finderOpen = ref(false);
const isMac = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform ?? '');
function onFinderKey(event) {
    const typing = event.target instanceof HTMLElement && (event.target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(event.target.tagName));
    if ((event.key === 'k' || event.key === 'K') && (event.metaKey || event.ctrlKey) && !event.altKey) {
        event.preventDefault();
        finderOpen.value = !finderOpen.value;
    } else if (event.key === '/' && !typing && !finderOpen.value && !event.metaKey && !event.ctrlKey) {
        event.preventDefault();
        finderOpen.value = true;
    }
}
onMounted(() => window.addEventListener('keydown', onFinderKey));
onBeforeUnmount(() => window.removeEventListener('keydown', onFinderKey));

const sheets = computed(() => admin.value.menu ?? []);
// The one menu entry this page belongs to: the longest match wins (Categories over Content).
const current = computed(
    () =>
        sheets.value
            .flatMap((g) => g.items)
            .filter((item) => !item.href.startsWith('https://') && isActive(item))
            .sort((a, b) => b.match.length - a.match.length)[0] ?? null,
);
function isActive(item) {
    const match = item.match.replace(/\/$/, '');
    return match === '/admin' ? path.value === '/admin' : path.value === match || path.value.startsWith(match + '/');
}

watch(
    () => page.url,
    () => {
        sidebarOpen.value = false;
        bellOpen.value = false;
    },
);

// ── theme (light / dark / system), remembered per browser ────────────
const theme = ref('system');
function applyTheme(value) {
    theme.value = value;
    if (value === 'system') document.documentElement.removeAttribute('data-theme');
    else document.documentElement.setAttribute('data-theme', value);
    try {
        localStorage.setItem('kestello.theme', value);
    } catch {
        /* private mode */
    }
}
function cycleTheme() {
    applyTheme({ system: 'light', light: 'dark', dark: 'system' }[theme.value]);
}
const themeIcon = computed(() => ({ system: 'circle-half', light: 'sun', dark: 'moon-stars' })[theme.value]);
const themeName = computed(() => ({ system: __('as the system'), light: __('light'), dark: __('dark') })[theme.value]);

// ── notifications bell ───────────────────────────────────────────────
const bellOpen = ref(false);
const unread = ref(0);
const recent = ref([]);
let timer = null;

async function loadNotifications() {
    if (!can('profile.manage')) return;
    try {
        const body = await api.get('/me/notifications', { limit: 8 });
        recent.value = body.data;
        unread.value = body.meta.unread;
    } catch {
        /* the bell is best effort */
    }
}

async function markAllRead() {
    try {
        await api.post('/me/notifications/read', {});
        await loadNotifications();
    } catch (e) {
        notify.error(e);
    }
}

async function openNotification(item) {
    if (!item.read_at) await api.post('/me/notifications/read', { id: item.id }).catch(() => null);
    bellOpen.value = false;
    const url = item.data?.url;
    if (typeof url === 'string' && url.startsWith('/') && !url.startsWith('//')) window.location.assign(url);
    else loadNotifications();
}

function onDocumentClick(event) {
    if (bellOpen.value && !event.target.closest?.('.k-bell')) bellOpen.value = false;
}

onMounted(() => {
    try {
        applyTheme(localStorage.getItem('kestello.theme') ?? 'system');
    } catch {
        applyTheme('system');
    }
    setReauthHandler(requestPassword);
    loadNotifications();
    timer = setInterval(() => {
        if (document.visibilityState === 'visible') loadNotifications();
    }, 60000);
    document.addEventListener('click', onDocumentClick);
});
onBeforeUnmount(() => {
    clearInterval(timer);
    document.removeEventListener('click', onDocumentClick);
});
</script>

<template>
    <div class="k-admin" :class="{ 'is-nav-open': sidebarOpen }">
        <a class="k-skip" href="#k-main">{{ __('Skip to content') }}</a>

        <aside class="k-sidebar" :aria-label="__('Admin')">
            <div class="k-sidebar__brand">
                <img :src="'/assets/core/brand/kestello-symbol.svg'" alt="" width="24" height="24" class="k-logo k-logo--light" />
                <img :src="'/assets/core/brand/kestello-symbol-white.svg'" alt="" width="24" height="24" class="k-logo k-logo--dark" />
                <div>
                    <Link href="/admin" class="k-sidebar__site">{{ site }}</Link>
                    <span class="k-sidebar__product">Kestello {{ admin.version }}</span>
                </div>
                <a href="/" class="k-icon-btn" :title="__('View site')" :aria-label="__('View site')"><KIcon name="arrow-square-out" /></a>
            </div>
            <button
                type="button"
                class="k-finder-btn"
                :aria-label="__('Find ({keys})', { keys: isMac ? 'Command K' : 'Control K' })"
                @click="finderOpen = true"
            >
                <KIcon name="magnifying-glass" /><span class="k-finder-btn__text">{{ __('Find') }}</span
                ><kbd class="k-finder-btn__keys" aria-hidden="true">{{ isMac ? '⌘K' : 'Ctrl K' }}</kbd>
            </button>
            <nav class="k-nav">
                <div v-for="group in sheets" :key="group.id" class="k-nav__group">
                    <p v-if="group.label" class="k-nav__heading">{{ group.label }}</p>
                    <ul>
                        <li v-for="item in group.items" :key="item.id">
                            <a v-if="item.href.startsWith('https://')" :href="item.href" class="k-nav__link" target="_blank" rel="noopener noreferrer">
                                <span>{{ item.label }}</span
                                ><KIcon name="arrow-square-out" class="k-nav__external" />
                            </a>
                            <Link
                                v-else
                                :href="item.href"
                                class="k-nav__link"
                                :class="{ 'is-active': item === current }"
                                :aria-current="item === current ? 'page' : undefined"
                            >
                                <span>{{ item.label }}</span>
                            </Link>
                        </li>
                    </ul>
                </div>
            </nav>
            <div class="k-sidebar__footer">
                <Link href="/admin/profile" class="k-sidebar__me" :title="__('Your profile')">
                    <KAvatar :user="user" />
                    <span class="k-sidebar__user">
                        <span>{{ user?.username }}</span>
                        <small>{{ user?.role }}</small>
                    </span>
                </Link>
                <div class="k-bell">
                    <button
                        type="button"
                        class="k-icon-btn"
                        :aria-expanded="bellOpen ? 'true' : 'false'"
                        aria-haspopup="true"
                        :aria-label="unread ? __('Notifications ({count} unread)', { count: unread }) : __('Notifications')"
                        :title="__('Notifications')"
                        @click="bellOpen = !bellOpen"
                    >
                        <KIcon name="bell" /><span v-if="unread" class="k-bell__count">{{ unread > 99 ? '99+' : unread }}</span>
                    </button>
                    <div v-if="bellOpen" class="k-popover k-bell__panel">
                        <div class="k-popover__header">
                            <strong>{{ __('Notifications') }}</strong>
                            <button v-if="unread" type="button" class="k-link-btn" @click="markAllRead">{{ __('Mark all as read') }}</button>
                        </div>
                        <ul v-if="recent.length" class="k-bell__list">
                            <li v-for="item in recent" :key="item.id">
                                <button type="button" class="k-bell__item" :class="{ 'is-unread': !item.read_at }" @click="openNotification(item)">
                                    <span class="k-bell__title">{{ item.data?.title ?? item.type }}</span>
                                    <span v-if="item.data?.body" class="k-bell__body">{{ item.data.body }}</span>
                                    <KTime :value="item.created_at" class="k-bell__time" />
                                </button>
                            </li>
                        </ul>
                        <p v-else class="k-bell__empty">{{ __('You are all caught up.') }}</p>
                    </div>
                </div>
                <button
                    type="button"
                    class="k-icon-btn"
                    :title="__('Theme: {mode}', { mode: themeName })"
                    :aria-label="__('Switch theme (now {mode})', { mode: themeName })"
                    @click="cycleTheme"
                >
                    <KIcon :name="themeIcon" />
                </button>
                <Link href="/logout" method="post" as="button" class="k-icon-btn" :aria-label="__('Sign out')" :title="__('Sign out')"
                    ><KIcon name="sign-out"
                /></Link>
            </div>
        </aside>
        <div class="k-scrim" @click="sidebarOpen = false"></div>

        <div class="k-shell">
            <header class="k-topbar">
                <button
                    type="button"
                    class="k-icon-btn"
                    :aria-expanded="sidebarOpen ? 'true' : 'false'"
                    :aria-label="__('Menu')"
                    @click="sidebarOpen = !sidebarOpen"
                >
                    <KIcon name="list" />
                </button>
                <span class="k-topbar__site">{{ site }}</span>
                <button type="button" class="k-icon-btn" :aria-label="__('Find')" @click="finderOpen = true"><KIcon name="magnifying-glass" /></button>
            </header>

            <main id="k-main" class="k-main" tabindex="-1">
                <div class="k-flash">
                    <KAlert v-if="page.props.recoveryMode" tone="warning" :title="__('Recovery mode')">
                        {{
                            __(
                                'All plugins are disabled and the default theme is active. It ends when the recovery link expires; to leave sooner, delete the kestello_recovery cookie for this site in your browser.',
                            )
                        }}
                    </KAlert>
                    <KAlert v-if="flash.success" tone="success">{{ flash.success }}</KAlert>
                    <KAlert v-if="flash.error" tone="danger">{{ flash.error }}</KAlert>
                </div>
                <div class="k-page"><slot /></div>
            </main>
        </div>

        <KToasts />
        <KDialogs />
        <KFinder v-model:open="finderOpen" />
    </div>
</template>
