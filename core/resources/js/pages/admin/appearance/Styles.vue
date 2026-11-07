<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head } from '@kestello/app';
import { ApiError, clone, confirm, formatBytes, notify, useApi, useLeaveGuard, __ } from '@kestello/sdk';
import { KAlert, KBadge, KButton, KField, KIcon, KInput, KSelect, KSwitch, KTime } from '@kestello/ui';

defineOptions({ layout: 'Admin' });

/**
 * Appearance → Styles: the theme's design tokens with a live preview of the
 * real site. Nothing changes for visitors until Publish.
 */
const api = useApi();
const data = ref(null);
const loadError = ref(null);
const choice = ref({ variation: null, values: {}, custom_css: '' });
const errors = ref({});
const saving = ref(false);

function adopt(d) {
    data.value = d;
    choice.value = clone({ variation: d.published.variation, values: d.published.values, custom_css: d.published.custom_css });
    errors.value = {};
}
async function load() {
    try {
        adopt((await api.get('/design/styles')).data);
    } catch (e) {
        loadError.value = e;
    }
}
load();

const def = computed(() => data.value?.definition);
const dirty = computed(
    () =>
        data.value &&
        JSON.stringify(choice.value) !==
            JSON.stringify({ variation: data.value.published.variation, values: data.value.published.values, custom_css: data.value.published.custom_css }),
);

// defaults, then the variation, then the site's own changes
const resolved = computed(() => {
    if (!def.value) return {};
    const out = Object.fromEntries(Object.entries(def.value.tokens).map(([k, t]) => [k, t.value]));
    Object.assign(out, def.value.variations[choice.value.variation]?.values ?? {});
    return Object.assign(out, choice.value.values);
});
const groups = computed(() => {
    if (!def.value) return [];
    const by = {};
    for (const [name, t] of Object.entries(def.value.tokens)) (by[t.group] ??= []).push({ name, ...t });
    return Object.entries(by).map(([id, tokens]) => ({ id, label: def.value.groups[id] ?? __('Other'), tokens }));
});

const num = (n) => String(Math.round(Number(n) * 10000) / 10000);
function cssValue(t, v) {
    if (t.type === 'font') return def.value.fonts[v]?.stack;
    if (t.type === 'size') return num(v) + t.unit;
    return t.type === 'number' ? num(v) : String(v);
}

function setValue(name, value) {
    const t = def.value.tokens[name];
    const base = def.value.variations[choice.value.variation]?.values?.[name] ?? t.value;
    const values = { ...choice.value.values };
    if (String(value).toLowerCase() === String(base).toLowerCase())
        delete values[name]; // back to the style's own value
    else values[name] = t.type === 'size' || t.type === 'number' ? Number(value) : value;
    choice.value = { ...choice.value, values };
}
function resetValue(name) {
    const values = { ...choice.value.values };
    delete values[name];
    choice.value = { ...choice.value, values };
}
function pickVariation(id) {
    choice.value = { ...choice.value, variation: id };
}

// ── contrast (WCAG 2.1) ──────────────────────────────────────────────
function rgb(hex) {
    let h = String(hex).replace('#', '');
    if (h.length === 3)
        h = h
            .split('')
            .map((c) => c + c)
            .join('');
    return [0, 2, 4].map((i) => parseInt(h.slice(i, i + 2), 16) / 255);
}
const lum = (hex) => {
    const [r, g, b] = rgb(hex).map((c) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4));
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
};
const ratio = (a, b) => {
    const [x, y] = [lum(a), lum(b)].sort((m, n) => n - m);
    return (x + 0.05) / (y + 0.05);
};
const contrast = computed(() =>
    (def.value?.contrast ?? []).map((p) => {
        const r = ratio(resolved.value[p.fg], resolved.value[p.bg]);
        const need = p.large ? 3 : 4.5;
        return { ...p, ratio: r, need, ok: r >= need };
    }),
);
const failing = computed(() => contrast.value.filter((c) => !c.ok));

/** Darken or lighten the text colour, keeping its hue, until it passes. */
function fixContrast(pair) {
    const bg = resolved.value[pair.bg];
    const towardsDark = lum(bg) > 0.18;
    let [r, g, b] = rgb(resolved.value[pair.fg]);
    for (let i = 0; i < 60; i++) {
        const hex =
            '#' +
            [r, g, b]
                .map((c) =>
                    Math.round(Math.min(1, Math.max(0, c)) * 255)
                        .toString(16)
                        .padStart(2, '0'),
                )
                .join('');
        if (ratio(hex, bg) >= pair.need + 0.1) return setValue(pair.fg, hex);
        [r, g, b] = [r, g, b].map((c) => (towardsDark ? c * 0.94 : c + (1 - c) * 0.08));
    }
    setValue(pair.fg, towardsDark ? '#000000' : '#ffffff');
}

// ── live preview ─────────────────────────────────────────────────────
const frame = ref(null);
const device = ref('desktop');
const DEVICES = [
    { id: 'phone', icon: 'device-mobile', label: __('Phone'), width: 390 },
    { id: 'tablet', icon: 'device-tablet', label: __('Tablet'), width: 820 },
    { id: 'desktop', icon: 'monitor', label: __('Desktop'), width: null },
];
const path = ref('/');
const pathInput = ref('/');
const frameSrc = computed(() => path.value);

function send() {
    const win = frame.value?.contentWindow;
    if (!win || !def.value) return;
    const vars = {};
    for (const [name, v] of Object.entries(resolved.value)) {
        const css = cssValue(def.value.tokens[name], v);
        if (css !== undefined) vars[`--${name}`] = css;
    }
    // hosted fonts chosen but not yet published need their @font-face rules in the preview
    const chosen = new Set(
        Object.entries(resolved.value)
            .filter(([n]) => def.value.tokens[n]?.type === 'font')
            .map(([, v]) => v),
    );
    const faces = (data.value.hosted_fonts ?? [])
        .filter((f) => chosen.has(f.slug))
        .map((f) => f.faces)
        .join('\n');
    const custom = data.value.can.custom_css ? choice.value.custom_css : data.value.published.custom_css;
    win.postMessage({ type: 'kestello:styles', vars, css: faces ? `${faces}\n${custom}` : custom }, window.location.origin);
}
let raf = 0;
watch(
    [resolved, () => choice.value.custom_css],
    () => {
        cancelAnimationFrame(raf);
        raf = requestAnimationFrame(send);
    },
    { deep: true },
);

function onMessage(event) {
    if (event.origin !== window.location.origin || event.source !== frame.value?.contentWindow) return;
    if (event.data?.type === 'kestello:preview-ready') {
        pathInput.value = event.data.path;
        send();
    }
}
// leaving with unpublished changes asks first, as the other editors do
useLeaveGuard(dirty);
onMounted(() => window.addEventListener('message', onMessage));
onBeforeUnmount(() => {
    window.removeEventListener('message', onMessage);
    cancelAnimationFrame(raf);
});

function go() {
    let p = pathInput.value.trim() || '/';
    if (!p.startsWith('/') || p.startsWith('//')) p = '/' + p.replace(/^\/+/, '');
    path.value = p;
}

// ── publishing ───────────────────────────────────────────────────────
async function publish() {
    saving.value = true;
    errors.value = {};
    try {
        const body = { variation: choice.value.variation, values: choice.value.values };
        if (data.value.can.custom_css) body.custom_css = choice.value.custom_css;
        adopt((await api.put('/design/styles', body)).data);
        notify.success(__('Published. Every page uses the new styles now.'));
    } catch (e) {
        if (e instanceof ApiError && e.status === 422) {
            errors.value = e.fieldErrors();
            notify.error(__('Some values need attention.'));
        } else {
            notify.error(e);
        }
    } finally {
        saving.value = false;
    }
}
async function discard() {
    if (!(await confirm({ title: __('Discard your changes?'), message: __('The preview goes back to what visitors see now.'), confirmLabel: __('Discard') })))
        return;
    adopt(data.value);
}
async function resetAll() {
    if (
        !(await confirm({
            title: __("Go back to the theme's styles?"),
            message: __('Every page uses {theme} as it was designed. You can restore the current version from the history below.', {
                theme: data.value.theme.name,
            }),
            confirmLabel: __('Reset'),
        }))
    )
        return;
    try {
        adopt((await api.delete('/design/styles')).data);
        notify.success(__("Back to the theme's styles."));
    } catch (e) {
        notify.error(e);
    }
}
async function restore(rev) {
    if (
        dirty.value &&
        !(await confirm({
            title: __('Restore this version?'),
            message: __('Your unpublished changes are discarded, and the version is published at once.'),
            confirmLabel: __('Restore'),
        }))
    )
        return;
    try {
        adopt((await api.post(`/design/styles/revisions/${rev.id}/restore`)).data);
        notify.success(__('Restored and published.'));
    } catch (e) {
        notify.error(e);
    }
}
const isChanged = (name) => name in choice.value.values;
// the first few colour tokens stand for a style in its button
const swatches = (v) =>
    Object.entries(def.value?.tokens ?? {})
        .filter(([, t]) => t.type === 'color')
        .slice(0, 4)
        .map(([k]) => v.values?.[k])
        .filter(Boolean);
// ── fonts hosted on this site ──────────────────────────────────────────
const WEIGHTS = [300, 400, 500, 600, 700, 800];
const CATEGORIES = [
    { value: 'sans-serif', label: __('Sans-serif') },
    { value: 'serif', label: __('Serif') },
    { value: 'display', label: __('Display') },
    { value: 'handwriting', label: __('Handwriting') },
    { value: 'monospace', label: __('Monospace') },
];
const fontForm = ref({ family: '', weights: [400, 700], italic: false, category: 'sans-serif' });
const fontErrors = ref({});
const addingFont = ref(false);
function toggleWeight(w) {
    const set = new Set(fontForm.value.weights);
    set.has(w) ? set.delete(w) : set.add(w);
    fontForm.value.weights = [...set].sort();
}
async function reloadKeepingChoice() {
    const keep = clone(choice.value);
    const d = (await api.get('/design/styles')).data;
    data.value = d;
    choice.value = keep;
    send();
}
async function addFont() {
    addingFont.value = true;
    fontErrors.value = {};
    try {
        await api.post('/themes/fonts', fontForm.value);
        notify.success(__('{family} is now served from this site.', { family: fontForm.value.family }));
        fontForm.value = { family: '', weights: [400, 700], italic: false, category: 'sans-serif' };
        await reloadKeepingChoice();
    } catch (e) {
        if (e instanceof ApiError && e.status === 422) fontErrors.value = e.fieldErrors();
        else notify.error(e);
    } finally {
        addingFont.value = false;
    }
}
async function removeFont(f) {
    const used = Object.entries(resolved.value).some(([n, v]) => def.value.tokens[n]?.type === 'font' && v === f.slug);
    if (
        !(await confirm({
            title: __('Remove {family}?', { family: f.family }),
            message: used ? __("Text set in it falls back to the theme's font.") : __('Its files are deleted from this site.'),
            confirmLabel: __('Remove'),
            danger: true,
        }))
    )
        return;
    try {
        await api.delete(`/themes/fonts/${f.slug}`);
        for (const [n, v] of Object.entries(choice.value.values)) if (v === f.slug) delete choice.value.values[n];
        await reloadKeepingChoice();
    } catch (e) {
        notify.error(e);
    }
}

const fontOptions = computed(() => Object.entries(def.value?.fonts ?? {}).map(([value, f]) => ({ value, label: f.label })));
</script>

<template>
    <Head :title="__('Styles')" />
    <KAlert v-if="loadError" tone="danger">{{ loadError.message }}</KAlert>

    <div v-if="data" class="k-styles">
        <header class="k-styles__head">
            <div>
                <h1 class="k-styles__title">{{ __('Styles') }}</h1>
                <p class="k-muted k-small">
                    {{ __('Colours, type and shapes of {theme}. Try anything: visitors see it only after you publish.', { theme: data.theme.name }) }}
                </p>
            </div>
            <div class="k-styles__actions">
                <KBadge v-if="dirty" tone="warning">{{ __('Not published') }}</KBadge>
                <KButton v-if="dirty" variant="ghost" @click="discard">{{ __('Discard') }}</KButton>
                <KButton variant="primary" icon="check" :disabled="!dirty" :loading="saving" @click="publish">{{ __('Publish') }}</KButton>
            </div>
        </header>

        <div class="k-styles__body">
            <aside class="k-styles__panel" :aria-label="__('Style settings')">
                <p v-if="!Object.keys(def.tokens).length" class="k-muted k-small">{{ __('This theme declares no styles to change (no styles.json).') }}</p>

                <section v-if="Object.keys(def.variations).length" class="k-styles__section">
                    <h2>{{ __('Style') }}</h2>
                    <div class="k-variations" role="radiogroup" :aria-label="__('Style')">
                        <button
                            type="button"
                            role="radio"
                            class="k-variation"
                            :class="{ 'is-on': !choice.variation }"
                            :aria-checked="!choice.variation"
                            @click="pickVariation(null)"
                        >
                            <span class="k-variation__chips"
                                ><span
                                    v-for="c in swatches({ values: Object.fromEntries(Object.entries(def.tokens).map(([k, t]) => [k, t.value])) })"
                                    :key="c"
                                    class="k-variation__chip"
                                    :style="{ background: c }"
                                ></span
                            ></span>
                            {{ __('As designed') }}
                        </button>
                        <button
                            v-for="(v, id) in def.variations"
                            :key="id"
                            type="button"
                            role="radio"
                            class="k-variation"
                            :class="{ 'is-on': choice.variation === id }"
                            :aria-checked="choice.variation === id"
                            @click="pickVariation(id)"
                        >
                            <span class="k-variation__chips"
                                ><span
                                    v-for="c in swatches({
                                        values: { ...Object.fromEntries(Object.entries(def.tokens).map(([k, t]) => [k, t.value])), ...v.values },
                                    })"
                                    :key="c"
                                    class="k-variation__chip"
                                    :style="{ background: c }"
                                ></span
                            ></span>
                            {{ v.label }}
                        </button>
                    </div>
                </section>

                <section v-for="g in groups" :key="g.id" class="k-styles__section">
                    <h2>{{ g.label }}</h2>
                    <div v-for="t in g.tokens" :key="t.name" class="k-token" :class="{ 'is-changed': isChanged(t.name) }">
                        <label class="k-token__label" :for="`tok-${t.name}`">{{ t.label }}</label>
                        <div class="k-token__control">
                            <template v-if="t.type === 'color'">
                                <input
                                    :id="`tok-${t.name}`"
                                    type="color"
                                    class="k-token__swatch"
                                    :value="String(resolved[t.name]).slice(0, 7)"
                                    @input="setValue(t.name, $event.target.value)"
                                />
                                <input
                                    type="text"
                                    class="k-input k-token__hex"
                                    :value="resolved[t.name]"
                                    maxlength="9"
                                    spellcheck="false"
                                    :aria-label="__('{name}, as a hex code', { name: t.label })"
                                    @change="
                                        /^#([0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i.test($event.target.value)
                                            ? setValue(t.name, $event.target.value.toLowerCase())
                                            : ($event.target.value = resolved[t.name])
                                    "
                                />
                            </template>
                            <template v-else-if="t.type === 'font'">
                                <KSelect
                                    :id="`tok-${t.name}`"
                                    :model-value="resolved[t.name]"
                                    :options="fontOptions"
                                    @update:model-value="(v) => setValue(t.name, v)"
                                />
                            </template>
                            <template v-else>
                                <input
                                    :id="`tok-${t.name}`"
                                    type="range"
                                    class="k-token__range"
                                    :min="t.min"
                                    :max="t.max"
                                    :step="t.step"
                                    :value="resolved[t.name]"
                                    @input="setValue(t.name, $event.target.value)"
                                />
                                <output class="k-token__out" :for="`tok-${t.name}`">{{ num(resolved[t.name]) }}{{ t.unit ?? '' }}</output>
                            </template>
                            <button
                                v-if="isChanged(t.name)"
                                type="button"
                                class="k-token__reset"
                                :aria-label="__('Reset {name}', { name: t.label })"
                                :title="__('Back to the style\'s value')"
                                @click="resetValue(t.name)"
                            >
                                <KIcon name="arrow-counter-clockwise" />
                            </button>
                        </div>
                        <p v-if="t.help" class="k-muted k-small k-token__help">{{ t.help }}</p>
                        <p v-if="errors[`values.${t.name}`]" class="k-field__error" role="alert">{{ errors[`values.${t.name}`] }}</p>
                    </div>
                </section>

                <section v-if="Object.values(def.tokens).some((t) => t.type === 'font')" class="k-styles__section">
                    <h2>{{ __('Fonts on this site') }}</h2>
                    <p class="k-muted k-small">
                        {{ __('Fonts from Google Fonts are downloaded once and served from this site, so visitors never contact Google.') }}
                    </p>
                    <ul v-if="data.hosted_fonts.length" class="k-fonts">
                        <li v-for="f in data.hosted_fonts" :key="f.slug">
                            <span class="k-fonts__name">{{ f.family }}</span>
                            <span class="k-muted k-small k-num">{{ formatBytes(f.bytes) }}</span>
                            <button
                                type="button"
                                class="k-token__reset"
                                :aria-label="__('Remove {family}', { family: f.family })"
                                :title="__('Remove')"
                                @click="removeFont(f)"
                            >
                                <KIcon name="trash" />
                            </button>
                        </li>
                    </ul>
                    <form class="k-font-add" @submit.prevent="addFont">
                        <KField
                            v-slot="{ id }"
                            :label="__('Google font')"
                            :help="__('Its name on Google Fonts, such as Inter or Playfair Display.')"
                            :error="fontErrors.family ?? ''"
                        >
                            <KInput :id="id" v-model="fontForm.family" maxlength="61" required />
                        </KField>
                        <fieldset class="k-font-add__weights">
                            <legend class="k-field__label">{{ __('Weights') }}</legend>
                            <label v-for="w in WEIGHTS" :key="w" class="k-font-add__weight">
                                <input type="checkbox" :checked="fontForm.weights.includes(w)" @change="toggleWeight(w)" /> <span class="k-num">{{ w }}</span>
                            </label>
                            <p v-if="fontErrors.weights" class="k-field__error" role="alert">{{ fontErrors.weights }}</p>
                        </fieldset>
                        <div class="k-font-add__row">
                            <KSelect v-model="fontForm.category" :options="CATEGORIES" :aria-label="__('Kind of font')" />
                            <KSwitch v-model="fontForm.italic" :label="__('Italics')" />
                        </div>
                        <KButton
                            type="submit"
                            size="sm"
                            icon="download"
                            :loading="addingFont"
                            :disabled="!fontForm.family.trim() || !fontForm.weights.length"
                            >{{ __('Add font') }}</KButton
                        >
                    </form>
                </section>

                <section v-if="contrast.length" class="k-styles__section">
                    <h2>{{ __('Readability') }}</h2>
                    <p class="k-muted k-small">
                        {{
                            failing.length
                                ? __('Some text may be hard to read. WCAG AA asks for 4.5:1, or 3:1 for large lettering.')
                                : __('Every pairing meets WCAG AA.')
                        }}
                    </p>
                    <ul class="k-contrast">
                        <li v-for="c in contrast" :key="c.fg + c.bg" :class="{ 'is-bad': !c.ok }">
                            <span class="k-contrast__sample" :style="{ color: resolved[c.fg], background: resolved[c.bg] }" aria-hidden="true">Aa</span>
                            <span class="k-contrast__label">{{ c.label }}</span>
                            <span class="k-contrast__ratio k-num">{{ c.ratio.toFixed(1) }}:1</span>
                            <KIcon v-if="c.ok" name="check" :label="__('Readable')" />
                            <KButton v-else size="sm" variant="ghost" @click="fixContrast(c)">{{ __('Fix') }}</KButton>
                        </li>
                    </ul>
                </section>

                <section v-if="data.can.custom_css || data.published.custom_css" class="k-styles__section">
                    <h2>
                        <label for="k-custom-css">{{ __('Custom CSS') }}</label>
                    </h2>
                    <p class="k-muted k-small">{{ __("Added after the theme's styles. Images must come from this site; scripts and imports are refused.") }}</p>
                    <textarea
                        id="k-custom-css"
                        v-model="choice.custom_css"
                        class="k-input k-custom-css"
                        rows="8"
                        spellcheck="false"
                        :readonly="!data.can.custom_css"
                        :aria-invalid="errors.custom_css ? 'true' : undefined"
                    ></textarea>
                    <p v-if="errors.custom_css" class="k-field__error" role="alert">{{ errors.custom_css }}</p>
                </section>

                <section class="k-styles__section">
                    <h2>{{ __('History') }}</h2>
                    <p v-if="!data.revisions.length" class="k-muted k-small">{{ __('Nothing published yet: the site uses the theme as designed.') }}</p>
                    <ol class="k-history">
                        <li v-for="(r, i) in data.revisions" :key="r.id">
                            <span>
                                <KTime :value="r.created_at" />
                                <span class="k-muted k-small"> · {{ r.reset ? __("Theme's styles") : (r.author_name ?? __('Someone')) }}</span>
                            </span>
                            <KBadge v-if="i === 0" tone="success">{{ __('Live') }}</KBadge>
                            <KButton v-else size="sm" variant="ghost" @click="restore(r)">{{ __('Restore') }}</KButton>
                        </li>
                    </ol>
                    <KButton v-if="data.info" size="sm" variant="ghost" icon="arrow-counter-clockwise" @click="resetAll">{{
                        __("Back to the theme's styles")
                    }}</KButton>
                </section>
            </aside>

            <section class="k-styles__preview" :aria-label="__('Preview')">
                <div class="k-preview-bar">
                    <div class="k-preview-bar__devices" role="radiogroup" :aria-label="__('Screen size')">
                        <button
                            v-for="d in DEVICES"
                            :key="d.id"
                            type="button"
                            role="radio"
                            :aria-checked="device === d.id"
                            :class="{ 'is-on': device === d.id }"
                            :title="d.label"
                            @click="device = d.id"
                        >
                            <KIcon :name="d.icon" :label="d.label" />
                        </button>
                    </div>
                    <form class="k-preview-bar__path" @submit.prevent="go">
                        <label class="k-sr-only" for="k-preview-path">{{ __('Page to preview') }}</label>
                        <input id="k-preview-path" v-model="pathInput" class="k-input k-mono-input" spellcheck="false" autocomplete="off" />
                        <KButton size="sm" type="submit">{{ __('Show') }}</KButton>
                    </form>
                </div>
                <div class="k-preview-stage" :class="`is-${device}`">
                    <iframe ref="frame" :src="frameSrc" :title="__('Preview of the site')" class="k-preview-frame"></iframe>
                </div>
            </section>
        </div>
    </div>
</template>

<style scoped>
.k-styles {
    display: flex;
    flex-direction: column;
    gap: 14px;
    height: calc(100vh - 96px);
    min-height: 34rem;
}
.k-styles__head {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    gap: 12px 24px;
    flex-wrap: wrap;
}
.k-styles__title {
    margin: 0 0 2px;
    font-size: 24px;
    font-weight: 650;
    letter-spacing: -0.01em;
}
.k-styles__head p {
    margin: 0;
}
.k-styles__actions {
    display: flex;
    align-items: center;
    gap: 8px;
}
.k-styles__body {
    flex: 1;
    min-height: 0;
    display: grid;
    grid-template-columns: 22rem minmax(0, 1fr);
    border: 1px solid var(--k-rule);
    background: var(--k-sheet);
}
.k-styles__panel {
    overflow: auto;
    border-right: 1px solid var(--k-rule);
    padding: 4px 18px 24px;
}
.k-styles__section {
    padding: 16px 0;
    border-bottom: 1px solid var(--k-rule-soft);
}
.k-styles__section:last-child {
    border-bottom: 0;
}
.k-styles__section h2 {
    margin: 0 0 10px;
    font-size: 13px;
    font-weight: 650;
}
.k-styles__section > p {
    margin: 0 0 10px;
}

.k-variations {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 6px;
}
.k-variation {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 6px;
    padding: 8px 10px;
    border: 1px solid var(--k-rule);
    border-radius: var(--k-radius);
    background: var(--k-sheet);
    color: inherit;
    font: inherit;
    font-size: 13px;
    text-align: left;
    cursor: pointer;
}
.k-variation:hover {
    background: var(--k-sheet-2);
}
.k-variation.is-on {
    border-color: var(--k-ink);
    box-shadow: inset 0 0 0 1px var(--k-ink);
}
.k-variation:focus-visible {
    outline: 2px solid var(--k-focus);
    outline-offset: 2px;
}
.k-variation__chips {
    display: flex;
    gap: 3px;
    min-height: 14px;
}
.k-variation__chip {
    width: 14px;
    height: 14px;
    border-radius: 50%;
    box-shadow: inset 0 0 0 1px rgb(0 0 0 / 0.15);
}

.k-token {
    padding: 7px 0;
}
.k-token__label {
    display: block;
    font-size: 13px;
    margin-bottom: 4px;
}
.k-token.is-changed .k-token__label {
    font-weight: 600;
}
.k-token__control {
    display: flex;
    align-items: center;
    gap: 8px;
}
.k-token__control :deep(.k-select),
.k-token__control select {
    flex: 1;
}
.k-token__swatch {
    width: 34px;
    height: 30px;
    padding: 2px;
    border: 1px solid var(--k-rule);
    border-radius: var(--k-radius);
    background: var(--k-sheet);
    cursor: pointer;
    flex-shrink: 0;
}
.k-token__hex {
    width: 7.5rem;
    font-family: var(--k-mono);
    font-size: 13px;
    padding: 5px 8px;
}
.k-token__range {
    flex: 1;
    accent-color: var(--k-mark);
}
.k-token__out {
    min-width: 3.6rem;
    text-align: right;
    font-family: var(--k-mono);
    font-size: 12.5px;
    color: var(--k-ink-2);
}
.k-token__reset {
    border: 0;
    background: none;
    padding: 4px;
    color: var(--k-ink-3);
    cursor: pointer;
    border-radius: var(--k-radius);
}
.k-token__reset:hover {
    color: var(--k-ink);
    background: var(--k-sheet-2);
}
.k-token__help {
    margin: 4px 0 0;
}

.k-contrast {
    list-style: none;
    margin: 0;
    padding: 0;
    display: grid;
    gap: 2px;
}
.k-contrast li {
    display: grid;
    grid-template-columns: 2.2rem minmax(0, 1fr) auto auto;
    align-items: center;
    gap: 8px;
    padding: 5px 0;
    font-size: 13px;
}
.k-contrast li.is-bad .k-contrast__ratio {
    color: var(--k-danger, #b42318);
    font-weight: 650;
}
.k-contrast__sample {
    display: grid;
    place-items: center;
    height: 24px;
    border-radius: 4px;
    font-weight: 700;
    font-size: 12px;
    box-shadow: inset 0 0 0 1px rgb(0 0 0 / 0.12);
}
.k-contrast__ratio {
    font-family: var(--k-mono);
    font-size: 12px;
}
.k-custom-css {
    width: 100%;
    font-family: var(--k-mono);
    font-size: 12.5px;
    line-height: 1.5;
    resize: vertical;
    padding: 8px 10px;
}

.k-fonts {
    list-style: none;
    margin: 0 0 12px;
    padding: 0;
}
.k-fonts li {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 5px 0;
    border-bottom: 1px solid var(--k-rule-soft);
    font-size: 13px;
}
.k-fonts__name {
    flex: 1;
    font-weight: 600;
}
.k-font-add {
    display: grid;
    gap: 10px;
}
.k-font-add__weights {
    border: 0;
    margin: 0;
    padding: 0;
    display: flex;
    flex-wrap: wrap;
    gap: 4px 12px;
}
.k-font-add__weights legend {
    width: 100%;
    padding: 0;
    margin-bottom: 4px;
}
.k-font-add__weight {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 13px;
}
.k-font-add__row {
    display: flex;
    align-items: center;
    gap: 12px;
}
.k-font-add__row :deep(.k-select) {
    flex: 1;
}
.k-font-add .k-button {
    justify-self: start;
}

.k-history {
    list-style: none;
    margin: 0 0 10px;
    padding: 0;
}
.k-history li {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 8px;
    padding: 6px 0;
    border-bottom: 1px solid var(--k-rule-soft);
    font-size: 13px;
}

.k-styles__preview {
    display: flex;
    flex-direction: column;
    min-width: 0;
    min-height: 0;
    background: var(--k-sheet-2);
}
.k-preview-bar {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 8px 12px;
    border-bottom: 1px solid var(--k-rule);
    background: var(--k-sheet);
}
.k-preview-bar__devices {
    display: flex;
    border: 1px solid var(--k-rule);
    border-radius: var(--k-radius);
    overflow: hidden;
}
.k-preview-bar__devices button {
    border: 0;
    background: none;
    padding: 5px 10px;
    color: var(--k-ink-2);
    cursor: pointer;
}
.k-preview-bar__devices button + button {
    border-left: 1px solid var(--k-rule);
}
.k-preview-bar__devices button.is-on {
    background: var(--k-ink);
    color: var(--k-sheet);
}
.k-preview-bar__devices button:focus-visible {
    outline: 2px solid var(--k-focus);
    outline-offset: -2px;
}
.k-preview-bar__path {
    flex: 1;
    display: flex;
    gap: 6px;
    max-width: 30rem;
}
.k-preview-bar__path input {
    flex: 1;
    font-size: 13px;
    padding: 5px 8px;
}
.k-preview-stage {
    flex: 1;
    min-height: 0;
    display: flex;
    justify-content: center;
    padding: 16px;
    overflow: auto;
}
.k-preview-stage.is-desktop {
    padding: 0;
}
.k-preview-frame {
    width: 100%;
    height: 100%;
    border: 0;
    background: #fff;
}
.k-preview-stage.is-phone .k-preview-frame {
    width: 390px;
    flex-shrink: 0;
    box-shadow: 0 0 0 1px var(--k-rule);
}
.k-preview-stage.is-tablet .k-preview-frame {
    width: 820px;
    flex-shrink: 0;
    box-shadow: 0 0 0 1px var(--k-rule);
}

@media (max-width: 1000px) {
    .k-styles {
        height: auto;
    }
    .k-styles__body {
        grid-template-columns: 1fr;
    }
    .k-styles__panel {
        border-right: 0;
        border-bottom: 1px solid var(--k-rule);
        max-height: none;
    }
    .k-styles__preview {
        height: 70vh;
    }
}
</style>
