/**
 * The panels around the canvas (which are open, how wide; remembered in this
 * browser) and the menus of the bar and the toolbar (one open at a time).
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { ONE_PANEL_BELOW, onePanel } from './panels.js';

const STORE = 'kestello.builder.panels';
const RIGHT = { min: 280, max: 640, initial: 320 };
const BELOW = { min: 140, max: 900, initial: 320 };
const clamp = (n, { min, max, initial }) => Math.min(max, Math.max(min, Number(n) || initial));

function stored() {
    try {
        return JSON.parse(localStorage.getItem(STORE) ?? '{}') ?? {};
    } catch {
        return {};
    }
}

export function usePanels(b, { root }) {
    const saved = stored();
    const roomy = typeof window === 'undefined' || window.innerWidth >= 1360;
    const left = ref(['layers', 'add', 'find', ''].includes(saved.left) ? saved.left : roomy ? 'layers' : ''); // '' | layers | add | find
    const right = ref(typeof saved.right === 'boolean' ? saved.right : true);
    const rightWidth = ref(clamp(saved.rightWidth, RIGHT));
    const docked = ref(saved.docked === true); // the block toolbar sits in the bar instead of over the block
    const belowOpen = ref(saved.below === true);
    const belowHeight = ref(clamp(saved.belowHeight, BELOW));
    const dockEl = ref(null); // where the docked toolbar goes (in the bar)

    // a narrower screen fits one side panel beside the page (panels.js)
    const narrowQuery = typeof window !== 'undefined' && window.matchMedia ? window.matchMedia(`(max-width: ${ONE_PANEL_BELOW}px)`) : null;
    const narrow = ref(!!narrowQuery?.matches);
    const fit = (prev) => {
        const next = onePanel(prev, { left: left.value, right: right.value }, narrow.value);
        if (next.left !== left.value) left.value = next.left;
        if (next.right !== right.value) right.value = next.right;
    };
    watch([left, right], ([l, r], [pl, pr]) => fit({ left: pl, right: pr }));
    const onNarrow = (e) => {
        narrow.value = e.matches;
        fit({ left: left.value, right: right.value });
    };
    narrowQuery?.addEventListener('change', onNarrow);
    onBeforeUnmount(() => narrowQuery?.removeEventListener('change', onNarrow));
    fit({ left: left.value, right: right.value });
    watch([left, right, rightWidth, docked, belowOpen, belowHeight], () => {
        try {
            localStorage.setItem(
                STORE,
                JSON.stringify({
                    left: left.value,
                    right: right.value,
                    rightWidth: rightWidth.value,
                    docked: docked.value,
                    below: belowOpen.value,
                    belowHeight: belowHeight.value,
                }),
            );
        } catch {
            /* remembered for this visit */
        }
    });

    function toggleLeft(mode) {
        left.value = left.value === mode ? '' : mode;
        if (left.value === 'add') nextTick(() => root.value?.querySelector('.k-builder__search')?.focus());
    }
    function showRight(tab) {
        right.value = true;
        if (tab) b.rightTab.value = tab;
    }

    // focus mode: both panels away, and back as they were
    let before = null;
    const focusMode = computed(() => !left.value && !right.value);
    function toggleFocus() {
        if (!focusMode.value) {
            before = { left: left.value, right: right.value };
            left.value = '';
            right.value = false;
        } else {
            left.value = before?.left || 'layers';
            right.value = before?.right ?? true;
        }
    }

    /** Drag a panel's edge; the frame would swallow the pointer, so it stops listening meanwhile. */
    function resize(event, what) {
        event.preventDefault();
        const x0 = event.clientX,
            y0 = event.clientY,
            w0 = rightWidth.value,
            h0 = belowHeight.value;
        const move = (e) => {
            if (what === 'right') rightWidth.value = clamp(w0 - (e.clientX - x0), RIGHT);
            else belowHeight.value = Math.round(Math.min(window.innerHeight * 0.75, clamp(h0 - (e.clientY - y0), BELOW)));
        };
        const up = () => {
            window.removeEventListener('pointermove', move);
            window.removeEventListener('pointerup', up);
            document.body.classList.remove('k-resizing');
        };
        window.addEventListener('pointermove', move);
        window.addEventListener('pointerup', up);
        document.body.classList.add('k-resizing');
    }
    function resizeKey(event, what) {
        const d = { ArrowLeft: 1, ArrowRight: -1, ArrowUp: 1, ArrowDown: -1 }[event.key];
        if (!d) return;
        event.preventDefault();
        const step = (event.shiftKey ? 64 : 16) * d;
        if (what === 'right') rightWidth.value = clamp(rightWidth.value + step, RIGHT);
        else belowHeight.value = clamp(belowHeight.value + step, BELOW);
    }

    // ── menus: one at a time; a click elsewhere or Escape closes it
    const menu = ref(''); // turn | level | align | format | options | details | more
    const toggleMenu = (name) => {
        menu.value = menu.value === name ? '' : name;
    };
    const closeMenu = () => {
        menu.value = '';
    };
    function onDocDown(event) {
        if (menu.value && !event.target.closest?.('.k-menu-wrap')) menu.value = '';
    }
    function onMenuKey(event) {
        if (event.key === 'Escape' && menu.value) {
            event.stopPropagation();
            menu.value = '';
        }
    }
    // the bar's own menus stay open while another block is selected; the toolbar's belong to the block
    watch(b.selected, () => {
        if (!['details', 'more'].includes(menu.value)) menu.value = '';
    });
    onMounted(() => document.addEventListener('mousedown', onDocDown));
    onBeforeUnmount(() => document.removeEventListener('mousedown', onDocDown));

    return {
        left,
        right,
        rightWidth,
        docked,
        dockEl,
        belowOpen,
        belowHeight,
        toggleLeft,
        showRight,
        focusMode,
        toggleFocus,
        resize,
        resizeKey,
        menu,
        toggleMenu,
        closeMenu,
        onMenuKey,
    };
}
