<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch, nextTick } from 'vue';
import { Head, Link, usePage, useRouter } from '@kestello/app';
import { ApiError, clone, confirm, formatDateTime, formatRelative, notify, useApi, useLeaveGuard, useResource, visibleFields, __ } from '@kestello/sdk';
import { KAlert, KBadge, KBuilder, KButton, KCard, KContentField, KField, KIcon, KInput, KModal, KPicker, KSelect, KTermSelect, KTime } from '@kestello/ui';
import RevisionDiff from '../../../components/content/RevisionDiff.vue';
import LinkedFrom from '../../../components/content/LinkedFrom.vue';
import ReviewPanel from '../../../components/content/ReviewPanel.vue';
import PublishChecklist from '../../../components/content/PublishChecklist.vue';
import SharePreviews from '../../../components/content/SharePreviews.vue';
import StudioPublish from '../../../components/content/StudioPublish.vue';
import { isNewEntry } from '../../../components/content/entry-state.js';
import TranslationLinks from '../../../components/languages/TranslationLinks.vue';
import { localPath } from '../../../components/languages/language-list.js';
import { checkSeo } from '../../../components/content/seo-checks.js';

defineOptions({ layout: 'Admin' });

const page = usePage();
const api = useApi();
const router = useRouter();
const typeName = computed(() => String(page.props.params?.type ?? ''));
const routeId = computed(() => String(page.props.params?.id ?? 'new'));
// new until the first save creates it (entry-state.js)
const isNew = computed(() => isNewEntry(routeId.value, entry.value));
const types = useResource('/content/types');
const type = computed(() => (types.data.value ?? []).find((t) => t.name === typeName.value) ?? null);
const fields = computed(() => type.value?.fields ?? []);
// fields whose conditions aren't met are hidden (their values stay, the server doesn't require them)
const shown = computed(() => visibleFields(fields.value, form.data ?? {}));
const mainFields = computed(() =>
    fields.value.filter((f) => f.width !== 'sidebar' && shown.value[f.name] !== false && !(studio.value && f === visualField.value)),
);
const sideFields = computed(() => fields.value.filter((f) => f.width === 'sidebar' && shown.value[f.name] !== false));
const base = `/content/${typeName.value}/entries`;

// ── the visual editor: the page itself, full screen, for types with a blocks field ──
const visualField = computed(() => fields.value.find((f) => f.type === 'blocks' && f.width !== 'sidebar') ?? null);
// search checks on the unsaved values, shown in the editor's Checks tab with the page's
const seoIssues = computed(() =>
    checkSeo({ title: form.title, excerpt: form.excerpt, seo: form.data?.seo, hasSeo: fields.value.some((f) => f.name === 'seo' && f.type === 'group') }, __),
);
const MODE = 'kestello.editor.mode';
const readMode = () => {
    try {
        return localStorage.getItem(MODE);
    } catch {
        return null;
    }
};
const preferForm = ref(readMode() === 'form');
const studio = computed(() => !!visualField.value && !preferForm.value);
function setMode(form) {
    preferForm.value = form;
    try {
        localStorage.setItem(MODE, form ? 'form' : 'visual');
    } catch {
        /* remembered for this visit only */
    }
}
// The side panel and the notices move into the studio once its places exist (a teleport
// needs its target when it mounts, so it remounts there: keyed by where it goes).
const docTarget = ref(null);
const alertsTarget = ref(null);
const belowTarget = ref(null);
function studioMounted() {
    docTarget.value = document.getElementById('k-studio-doc');
    belowTarget.value = document.getElementById('k-studio-below');
}
// the title was clicked on the canvas: it is typed in the bar above
function focusTitle() {
    const input = document.getElementById('k-studio-title');
    input?.focus();
    input?.select();
}
// the page's template hides its content: the Page tab is open now; bring the template choice into view
function chooseTemplate() {
    setTimeout(() => {
        const el = document.querySelector('.k-studio__template');
        el?.scrollIntoView({ block: 'center', behavior: 'smooth' });
        el?.querySelector('select, button, input')?.focus({ preventScroll: true });
    }, 60);
}
watch(studio, (on) => {
    if (!on) {
        docTarget.value = null;
        alertsTarget.value = null;
        belowTarget.value = null;
    }
});
const canvasSrc = computed(() => {
    const q = new URLSearchParams({ kind: 'entry', type: typeName.value, field: visualField.value?.name ?? '' });
    if (!isNew.value && entry.value?.id) q.set('entry', String(entry.value.id));
    q.set('template', form.template || ''); // the template chosen here, saved or not: the canvas follows it
    return `/system/design/canvas?${q}`;
});
async function prepareBlocks(blocks) {
    const body = { blocks, field: visualField.value.name };
    if (entry.value?.id) body.entry = entry.value.id;
    return (await api.post(`/content/${typeName.value}/preview-blocks`, body)).data.blocks;
}
// A body saved as HTML (before the field held blocks) opens as one text block; the same one each time.
let upgraded = null;
const visualTree = computed({
    get: () => {
        const v = form.data[visualField.value?.name];
        if (Array.isArray(v)) return v;
        if (typeof v === 'string' && v.trim() !== '') {
            if (upgraded?.html !== v)
                upgraded = {
                    html: v,
                    tree: [
                        {
                            type: 'core/text',
                            id: Array.from(crypto.getRandomValues(new Uint8Array(4)), (b) => b.toString(16).padStart(2, '0')).join(''),
                            props: { text: v },
                            children: [],
                        },
                    ],
                };
            return upgraded.tree;
        }
        return [];
    },
    set: (v) => {
        form.data[visualField.value.name] = v.length ? v : null;
    },
});
// the server names problems "data.body.2.props.text"; the builder lists those under "blocks"
const visualErrors = computed(() => {
    const prefix = `data.${visualField.value?.name}`;
    return Object.fromEntries(
        Object.entries(errors.value)
            .filter(([k]) => k === prefix || k.startsWith(`${prefix}.`))
            .map(([k, v]) => [`blocks${k.slice(prefix.length)}`, v]),
    );
});

// ── state ────────────────────────────────────────────────────────────
const entry = ref(null); // as last saved
const loadError = ref(null);
const form = reactive(blank());
const errors = ref({});
const saving = ref(false);
const conflict = ref(false);
const autosave = ref(null); // { saved_at, snapshot } newer than the entry
const autosavedAt = ref(null);

function blank() {
    return {
        title: '',
        slug: '',
        excerpt: '',
        data: {},
        terms: {},
        parent_id: null,
        author_id: null,
        menu_order: 0,
        published_at: null,
        status: 'draft',
        template: '',
    };
}
function fill(e) {
    Object.assign(form, {
        title: e.title,
        slug: e.slug,
        excerpt: e.excerpt ?? '',
        data: clone(e.data ?? {}),
        terms: termIds(e),
        parent_id: e.parent_id,
        author_id: e.author?.id ?? null,
        menu_order: e.menu_order,
        published_at: e.published_at,
        status: e.status,
        template: e.template ?? '',
    });
}
// Custom templates this type may use; a chosen one that went away still shows, so saving doesn't drop it silently.
const templateOptions = computed(() => {
    const list = type.value?.templates ?? [];
    const opts = [{ value: '', label: __('Default') }, ...list.map((t) => ({ value: t.key, label: t.title }))];
    if (form.template && !list.some((t) => t.key === form.template))
        opts.push({ value: form.template, label: __('{name} (no longer available)', { name: form.template }) });
    return list.length || form.template ? opts : [];
});
const taxonomies = computed(() => (type.value?.taxonomies ?? []).filter((t) => t.can.assign));
function termIds(e) {
    const out = {};
    for (const t of type.value?.taxonomies ?? []) out[t.name] = (e?.terms?.[t.name] ?? []).map((x) => x.id);
    return out;
}
const sameList = (a, b) => JSON.stringify(a ?? []) === JSON.stringify(b ?? []);
// a new entry is untouched while every value is still its default (an emptied field counts as untouched)
const filled = (data) =>
    Object.fromEntries(
        Object.entries(data).filter(
            ([, v]) =>
                v !== null &&
                v !== '' &&
                v !== false &&
                !(Array.isArray(v) && !v.length) &&
                !(v && typeof v === 'object' && !Array.isArray(v) && !Object.keys(filled(v)).length),
        ),
    );
function defaults() {
    const data = {};
    for (const f of fields.value) if (f.default !== null && f.default !== undefined) data[f.name] = clone(f.default);
    return data;
}

async function load() {
    if (routeId.value === 'new') {
        entry.value = null;
        Object.assign(form, blank(), { data: defaults(), terms: termIds(null) });
        return;
    }
    try {
        const { data } = await api.get(`${base}/${routeId.value}`);
        entry.value = data;
        fill(data);
        autosave.value = data.autosave;
        loadError.value = null;
    } catch (e) {
        loadError.value = e;
    }
}
watch(
    [routeId, () => !!type.value],
    ([, ready]) => {
        if (ready) load();
    },
    { immediate: true },
);

// What would be sent: only keys that differ from the saved entry.
const changes = computed(() => {
    const e = entry.value;
    if (!e) {
        // New entry: leave out what the server fills in (author = you, date = now).
        const out = { ...form, excerpt: form.excerpt || null };
        out.terms = Object.fromEntries(Object.entries(form.terms).filter(([name, ids]) => ids.length && taxonomies.value.some((t) => t.name === name)));
        for (const key of ['author_id', 'parent_id', 'published_at']) if (out[key] === null) delete out[key];
        if (!out.template) delete out.template;
        delete out.status;
        return out;
    }
    const out = {};
    if (form.title !== e.title) out.title = form.title;
    if (form.slug !== e.slug) out.slug = form.slug;
    if ((form.excerpt || null) !== (e.excerpt || null)) out.excerpt = form.excerpt || null;
    if (JSON.stringify(form.data) !== JSON.stringify(e.data ?? {})) out.data = form.data;
    const before = termIds(e);
    const terms = Object.fromEntries(Object.entries(form.terms).filter(([name, ids]) => !sameList(ids, before[name])));
    if (Object.keys(terms).length) out.terms = terms;
    if (form.parent_id !== e.parent_id) out.parent_id = form.parent_id;
    if (form.author_id !== (e.author?.id ?? null)) out.author_id = form.author_id;
    if (form.menu_order !== e.menu_order) out.menu_order = form.menu_order;
    if ((form.template || null) !== (e.template || null)) out.template = form.template || null;
    if (form.published_at !== e.published_at) out.published_at = form.published_at;
    return out;
});
const dirty = computed(() =>
    isNew.value
        ? form.title.trim() !== '' ||
          JSON.stringify(filled(form.data)) !== JSON.stringify(filled(defaults())) ||
          Object.values(form.terms).some((ids) => ids.length)
        : Object.keys(changes.value).length > 0,
);

// ── permissions and status ───────────────────────────────────────────
const can = computed(() => entry.value?.can ?? { edit: !!type.value?.can.create, publish: !!type.value?.can.publish, delete: false });
const readOnly = computed(() => !can.value.edit || !!entry.value?.trashed_at);
const status = computed(() => entry.value?.status ?? 'draft');
const live = computed(() => status.value === 'published' || status.value === 'private');
const STATUS = {
    draft: { label: __('Draft'), tone: 'neutral' },
    pending: { label: __('In review'), tone: 'accent' },
    scheduled: { label: __('Scheduled'), tone: 'info' },
    published: { label: __('Published'), tone: 'success' },
    private: { label: __('Private'), tone: 'warning' },
};
const future = computed(() => !!form.published_at && Date.parse(form.published_at) > Date.now());
// the studio bar's buttons; every choice stays in the side panel's publishing card
// in the visual editor, what the publishing card keeps besides the bar's actions (barActions)
const studioExtras = computed(() => ((live.value || status.value === 'scheduled') && can.value.publish) || status.value === 'pending' || !can.value.publish);
const barActions = computed(() => {
    if (readOnly.value) return [];
    if (live.value || status.value === 'scheduled')
        return [{ label: status.value === 'scheduled' ? __('Update schedule') : __('Update'), primary: true, disabled: !dirty.value, run: () => save() }];
    if (status.value === 'pending') {
        return can.value.publish
            ? [
                  { label: __('Save'), disabled: !dirty.value, run: () => save() },
                  { label: future.value ? __('Approve and schedule') : __('Approve and publish'), primary: true, run: () => save('published') },
              ]
            : [{ label: __('Save changes'), primary: true, disabled: !dirty.value, run: () => save() }];
    }
    return [
        { label: __('Save draft'), disabled: !dirty.value && !isNew.value, run: () => save('draft') },
        can.value.publish
            ? { label: future.value ? __('Schedule') : __('Publish'), primary: true, run: () => save('published') }
            : { label: __('Submit for review'), primary: true, run: () => save('pending') },
    ];
});

// ── languages ────────────────────────────────────────────────────────
// A site in several languages: a new entry is written in the language picked
// here (or ?locale= from "New" on a filtered list); afterwards it keeps it,
// and the Translations card links it to its other languages.
const languageInfo = useResource('/content/languages');
const languages = computed(() => languageInfo.data.value?.languages ?? []);
const multilingual = computed(() => languages.value.length > 1);
const newLanguage = ref(typeof window !== 'undefined' ? (new URLSearchParams(window.location.search).get('locale') ?? '') : '');
const language = computed(() => entry.value?.locale ?? newLanguage.value);
const languageName = computed(() => languages.value.find((l) => l.code === language.value)?.name ?? language.value);
const languageOptions = computed(() => languages.value.map((l) => ({ value: l.code, label: l.name })));

// ── saving ───────────────────────────────────────────────────────────
async function save(nextStatus = null, { force = false } = {}) {
    if (saving.value || readOnly.value) return;
    const body = { ...changes.value };
    if (nextStatus && nextStatus !== status.value) body.status = nextStatus;
    if (nextStatus === 'published' && future.value) body.status = 'published'; // the server turns it into scheduled
    if (!isNew.value && !Object.keys(body).length) return;
    if (!isNew.value && !force) body.version = entry.value.version;
    saving.value = true;
    errors.value = {};
    try {
        if (isNew.value) {
            const { data } = await api.post(base, { ...body, status: nextStatus ?? 'draft', ...(multilingual.value ? { locale: newLanguage.value } : {}) });
            entry.value = data;
            fill(data);
            notify.success(message(data));
            // saved: the new address is the same work, not a way out of it
            router.visit(`/admin/content/${typeName.value}/${data.id}`, { replace: true, preserveScroll: true, discardUnsaved: true });
        } else {
            const { data } = await api.patch(`${base}/${entry.value.id}`, body);
            const was = entry.value.status;
            entry.value = data;
            fill(data);
            autosave.value = null;
            conflict.value = false;
            revisions.reload({ silent: true });
            if (was !== data.status) reviewPanel.value?.reload();
            notify.success(was !== data.status ? message(data) : __('Saved.'));
        }
    } catch (e) {
        if (e instanceof ApiError && e.status === 422) {
            errors.value = e.errors ?? {};
            // one key can hold several problems (a block field lists each block's)
            const n = Object.values(errors.value).reduce((sum, m) => sum + (Array.isArray(m) ? m.length : 1), 0);
            notify.error(__('{n, plural, one {One problem needs attention.} other {# problems need attention.}}', { n }));
            await nextTick(); // the fields show their errors on the next render
            document.querySelector('.has-error')?.scrollIntoView({ block: 'center', behavior: 'smooth' });
        } else if (e instanceof ApiError && e.status === 409) {
            conflict.value = true;
        } else {
            console.error('[kestello] save failed', e);
            notify.error(e);
        }
    } finally {
        saving.value = false;
    }
}
function message(e) {
    return {
        draft: __('Saved as a draft.'),
        pending: __('Submitted for review.'),
        scheduled: __('Scheduled for {when}.', { when: formatDateTime(e.published_at) }),
        published: __('Published.'),
        private: __('Published privately.'),
    }[e.status];
}
async function reloadTheirs() {
    conflict.value = false;
    await load();
    notify.info(__('Loaded the latest version.'));
}

// ── autosave (every 30 s while there are unsaved changes) ────────────
let timer = null;
let lastSnapshot = '';
async function autosaveNow() {
    if (isNew.value || readOnly.value || !dirty.value || saving.value) return;
    const snapshot = { title: form.title, slug: form.slug, excerpt: form.excerpt || null, data: form.data };
    const json = JSON.stringify(snapshot);
    if (json === lastSnapshot) return;
    try {
        const res = await api.put(`${base}/${entry.value.id}/autosave`, snapshot);
        lastSnapshot = json;
        autosavedAt.value = res.data?.saved_at ?? null;
    } catch {
        /* the next tick tries again */
    }
}

// ── preview: an hour-long link for you, with your unsaved changes (kept as an autosave first) ──
const canPreview = computed(() => !!entry.value && !entry.value.trashed_at && can.value.edit && type.value?.path !== null && type.value?.path !== undefined);
const previewing = ref(false);
async function preview() {
    // Open the tab now: a window opened after an await is blocked as a pop-up.
    const tab = window.open('about:blank', '_blank');
    previewing.value = true;
    try {
        await autosaveNow();
        const { data } = await api.post(`${base}/${entry.value.id}/previews`, { kind: 'editor' });
        if (tab) {
            tab.opener = null;
            tab.location.href = data.url;
        } else {
            window.location.assign(data.url);
        }
    } catch (e) {
        tab?.close();
        notify.error(e);
    } finally {
        previewing.value = false;
    }
}
function restoreAutosave() {
    const s = autosave.value.snapshot;
    Object.assign(form, { title: s.title ?? form.title, slug: s.slug ?? form.slug, excerpt: s.excerpt ?? '', data: { ...form.data, ...(s.data ?? {}) } });
    autosave.value = null;
}

useLeaveGuard(dirty);
function shortcut(e) {
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 's') {
        e.preventDefault();
        save(isNew.value ? 'draft' : null);
    }
}
onMounted(() => {
    timer = setInterval(autosaveNow, 30000);
    window.addEventListener('keydown', shortcut);
});
onBeforeUnmount(() => {
    clearInterval(timer);
    window.removeEventListener('keydown', shortcut);
});

// ── slug ─────────────────────────────────────────────────────────────
const editingSlug = ref(false);
const prefix = computed(() => {
    const p = type.value?.path;
    if (p === null || p === undefined) return null;
    // an entry in another language lives under its prefix: /es/about
    // (and its own word for the type's prefix, when it has one: /es/noticias)
    const lang = languages.value.find((l) => l.code === language.value);
    const parentPath =
        entry.value?.parent && form.parent_id === entry.value.parent_id
            ? (entry.value.path ?? '').replace(/[^/]+$/, '')
            : `${lang?.prefix ?? ''}${localPath(`${p}/`, lang?.paths)}`;
    return parentPath || '/';
});
const shownSlug = computed(
    () =>
        form.slug ||
        (form.title
            ? form.title
                  .toLowerCase()
                  .normalize('NFKD')
                  .replace(/[^\w\s-]/g, '')
                  .trim()
                  .replace(/[\s_-]+/g, '-')
            : ''),
);

// ── parent, author ───────────────────────────────────────────────────
const lookupItem = (e) => ({ id: e.id, label: e.title || __('(untitled)'), meta: e.path ?? '' });
const searchParents = async (q) =>
    (
        await api.get('/content/lookup', { search: q || undefined, types: typeName.value, locale: multilingual.value ? language.value || 'main' : undefined })
    ).data
        .filter((e) => e.id !== entry.value?.id)
        .map(lookupItem);
const resolveParents = async (ids) => (await api.get('/content/lookup', { ids: ids.join(','), types: typeName.value })).data.map(lookupItem);
const userItem = (u) => ({ id: u.id, label: u.username });
const searchUsers = async (q) => (await api.get('/content/users', { search: q || undefined })).data.map(userItem);
const resolveUsers = async (ids) => (await api.get('/content/users', { ids: ids.join(',') })).data.map(userItem);
const canReassign = computed(() => !!type.value?.can.edit_others);

// Schedule: a local datetime input bound to the UTC published_at.
const pad = (n) => String(n).padStart(2, '0');
const publishAt = computed({
    get() {
        if (!form.published_at) return '';
        const d = new Date(form.published_at);
        return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
    },
    set(v) {
        form.published_at = v ? new Date(v).toISOString().replace(/\.\d{3}Z$/, 'Z') : null;
    },
});
const zone = Intl.DateTimeFormat().resolvedOptions().timeZone;

// ── revisions ────────────────────────────────────────────────────────
const revisions = useResource(() => `${base}/${entry.value?.id ?? 0}/revisions`, { immediate: false });
// useResource refetches when the path changes (i.e. once the entry has an id).
const fieldLabel = (key) => {
    if (key.startsWith('data.')) return fields.value.find((f) => f.name === key.slice(5))?.label ?? key.slice(5);
    return (
        {
            terms: __('categories and tags'),
            title: __('title'),
            slug: __('slug'),
            status: __('status'),
            excerpt: __('excerpt'),
            parent_id: __('parent'),
            author_id: __('author'),
            published_at: __('date'),
            menu_order: __('order'),
            template: __('template'),
        }[key] ?? key
    );
};
async function restoreRevision(rev) {
    if (
        dirty.value &&
        !(await confirm({
            title: __('Discard your unsaved changes?'),
            message: __('Restoring replaces what is in the editor.'),
            confirmLabel: __('Restore revision'),
        }))
    )
        return;
    try {
        const { data } = await api.post(`${base}/${entry.value.id}/revisions/${rev.id}/restore`);
        entry.value = data;
        fill(data);
        revisions.reload({ silent: true });
        notify.success(__('Restored the revision from {when}.', { when: formatDateTime(rev.created_at) }));
    } catch (e) {
        notify.error(e);
    }
}

// Review: send an entry in review back with a note.
const reviewPanel = ref(null);
const asking = ref(false);
const note = ref('');
const sending = ref(false);
async function requestChanges() {
    if (!note.value.trim()) return;
    if (dirty.value) {
        await save();
        if (dirty.value) return; // the save failed; its message says why
    }
    sending.value = true;
    try {
        const { data } = await api.post(`${base}/${entry.value.id}/request-changes`, { note: note.value });
        entry.value = data;
        fill(data);
        asking.value = false;
        note.value = '';
        revisions.reload({ silent: true });
        reviewPanel.value?.reload();
        notify.success(__('Sent back with your note.'));
    } catch (e) {
        notify.error(e);
    } finally {
        sending.value = false;
    }
}
function onAssigned(data) {
    entry.value = { ...entry.value, assignee: data.assignee };
}

// Compare: what a revision changed, against the one before it or against the current version.
const comparing = ref(null); // the revision row
const compareWith = ref('previous');
const diff = ref(null);
const diffError = ref(null);
async function loadDiff() {
    diff.value = null;
    diffError.value = null;
    try {
        diff.value = (await api.get(`${base}/${entry.value.id}/revisions/${comparing.value.id}/diff`, { against: compareWith.value })).data;
    } catch (e) {
        diffError.value = e;
    }
}
function compare(rev) {
    comparing.value = rev;
    compareWith.value = 'previous';
    loadDiff();
}
watch(compareWith, () => {
    if (comparing.value) loadDiff();
});
const compareOptions = computed(() => [
    { value: 'previous', label: __('The version before it') },
    { value: 'current', label: __('The current version') },
]);
async function restoreCompared() {
    const rev = comparing.value;
    comparing.value = null;
    await restoreRevision(rev);
}

// ── trash ────────────────────────────────────────────────────────────
async function moveToTrash() {
    try {
        await api.delete(`${base}/${entry.value.id}`);
        notify.success(__('Moved to the trash.'));
        router.visit(`/admin/content/${typeName.value}`, { discardUnsaved: true });
    } catch (e) {
        notify.error(e);
    }
}
async function restoreEntry() {
    try {
        const { data } = await api.post(`${base}/${entry.value.id}/restore`);
        entry.value = data;
        fill(data);
        notify.success(__('Restored from the trash.'));
    } catch (e) {
        notify.error(e);
    }
}

const belowLabel = computed(
    () => [...mainFields.value.map((f) => f.label), type.value?.excerpt ? __('Excerpt') : null].filter(Boolean).join(', ') || __('More fields'),
);
const title = computed(() => (isNew.value ? __('New {item}', { item: (type.value?.singular ?? '').toLowerCase() }) : entry.value?.title || __('(untitled)')));
const err = (key) => (Array.isArray(errors.value[key]) ? errors.value[key][0] : (errors.value[key] ?? ''));
</script>

<template>
    <Head :title="title" />

    <div v-if="studio && type && (isNew || entry)" class="k-studio" role="region" :aria-label="__('Visual editor')">
        <p v-if="err('title')" id="k-studio-title-error" class="k-studio__error" role="alert">{{ __('Title') }}: {{ err('title') }}</p>
        <div
            :ref="
                (el) => {
                    alertsTarget = el ?? null;
                }
            "
            class="k-studio__alerts"
        ></div>
        <KBuilder
            v-model="visualTree"
            class="k-studio__builder"
            kind="entry"
            :doc-key="visualField.name"
            :reset-key="`${entry?.id ?? 'new'}:${entry?.version ?? 0}`"
            :canvas-src="canvasSrc"
            :previewer="prepareBlocks"
            :canvas-data="{ title: form.title || __('Add a title') }"
            :allowed="visualField.options?.allowed ?? null"
            :document-label="type.singular"
            :start-tab="isNew ? 'block' : 'document'"
            :below-label="belowLabel"
            :extra-issues="seoIssues"
            :disabled="readOnly"
            :errors="visualErrors"
            @save="save(isNew ? 'draft' : null)"
            @ready="studioMounted"
            @choose-template="chooseTemplate"
            @title="focusTitle"
        >
            <template #start>
                <Link :href="`/admin/content/${typeName}`" class="k-studio__back" :title="__('Back to {list}', { list: type.plural })"
                    ><KIcon name="arrow-left" /><span class="k-sr-only">{{ __('Back to {list}', { list: type.plural }) }}</span></Link
                >
                <label class="k-sr-only" for="k-studio-title">{{ __('Title') }}</label>
                <input
                    id="k-studio-title"
                    v-model="form.title"
                    class="k-studio__title"
                    :placeholder="__('Add a title')"
                    maxlength="255"
                    autocomplete="off"
                    data-lpignore="true"
                    data-1p-ignore
                    :readonly="readOnly"
                    :aria-invalid="err('title') ? 'true' : undefined"
                    :aria-describedby="err('title') ? 'k-studio-title-error' : undefined"
                />
            </template>
            <template #end>
                <!-- one quiet line: "● Draft · Saved 4 minutes ago"; the dot carries the status colour -->
                <span class="k-studio__state" aria-live="polite">
                    <span v-if="entry" class="k-studio__status" :class="`is-${status}`">{{ STATUS[status]?.label }}</span>
                    <span v-if="saving" class="k-studio__note">{{ __('Saving…') }}</span>
                    <span v-else-if="dirty" class="k-studio__note is-dirty">{{ __('Unsaved') }}</span>
                    <span v-else-if="entry" class="k-studio__note">{{ __('Saved') }}<KTime class="k-studio__wide" :value="entry.updated_at" /></span>
                </span>
                <KButton size="sm" variant="ghost" icon="rows" :title="__('Edit as a form instead')" @click="setMode(true)">{{ __('Form') }}</KButton>
                <KButton v-if="canPreview" size="sm" variant="ghost" icon="eye" :loading="previewing" :title="__('Preview')" @click="preview"
                    ><span class="k-studio__wide">{{ __('Preview') }}</span></KButton
                >
                <KButton
                    v-for="a in barActions"
                    :key="a.label"
                    size="sm"
                    :variant="a.primary ? 'primary' : 'secondary'"
                    :loading="a.primary && saving"
                    :disabled="a.disabled || saving"
                    @click="a.run"
                    >{{ a.label }}</KButton
                >
            </template>
            <template v-if="mainFields.length || type.excerpt" #below><div id="k-studio-below" class="k-studio__below"></div></template>
            <template #document><div id="k-studio-doc" class="k-studio__doc"></div></template>
        </KBuilder>
    </div>

    <div v-show="!studio" class="k-editor-head">
        <Link :href="`/admin/content/${typeName}`" class="k-link k-editor-head__back"><KIcon name="arrow-left" />{{ type?.plural ?? __('Content') }}</Link>
        <span class="k-editor-head__state" aria-live="polite">
            <KBadge v-if="entry" :tone="STATUS[status]?.tone">{{ STATUS[status]?.label }}</KBadge>
            <span v-if="dirty" class="k-muted">{{ __('Unsaved changes') }}</span>
            <span v-else-if="entry" class="k-muted">{{ __('Saved') }} <KTime :value="entry.updated_at" /></span>
            <span v-if="dirty && autosavedAt" class="k-muted">· {{ __('backup kept {when}', { when: formatRelative(autosavedAt) }) }}</span>
        </span>
        <KButton v-if="visualField" size="sm" variant="ghost" icon="layout" @click="setMode(false)">{{ __('Visual editor') }}</KButton>
        <KButton v-if="canPreview" size="sm" variant="ghost" icon="eye" :loading="previewing" @click="preview">{{ __('Preview') }}</KButton>
        <a v-if="entry?.path && status === 'published'" :href="entry.path" class="k-link" target="_blank" rel="noopener">{{ __('View on site') }}</a>
    </div>

    <KAlert v-if="types.data.value && !type" tone="danger">{{ __('This content type does not exist, or you cannot edit it.') }}</KAlert>
    <KAlert v-else-if="loadError" tone="danger">{{
        loadError.status === 404 ? __('This entry does not exist, or you cannot see it.') : loadError.message
    }}</KAlert>

    <template v-if="type && (isNew || entry)">
        <Teleport :key="alertsTarget ? 'studio' : 'form'" :to="alertsTarget ?? 'body'" :disabled="!alertsTarget">
            <KAlert v-if="entry?.trashed_at" tone="warning" :title="__('In the trash')">
                {{ __('Restore it to edit or publish it again.') }}
                <div class="k-alert-actions">
                    <KButton size="sm" icon="arrow-counter-clockwise" :disabled="!can.delete" @click="restoreEntry">{{ __('Restore') }}</KButton>
                </div>
            </KAlert>
            <KAlert v-else-if="!can.edit" tone="info">{{ __('You can read this {item} but not change it.', { item: type.singular.toLowerCase() }) }}</KAlert>
            <KAlert v-if="conflict" tone="danger" :title="__('Someone else saved this while you were editing')">
                {{ __('Load their version (your changes here are lost), or save yours over it.') }}
                <div class="k-alert-actions">
                    <KButton size="sm" @click="reloadTheirs">{{ __('Load their version') }}</KButton>
                    <KButton size="sm" variant="danger" @click="save(null, { force: true })">{{ __('Save mine anyway') }}</KButton>
                </div>
            </KAlert>
            <KAlert v-if="autosave && !readOnly" tone="info" :title="__('You have unsaved changes from {when}', { when: formatDateTime(autosave.saved_at) })">
                {{ __('They were kept when you left the editor without saving.') }}
                <div class="k-alert-actions">
                    <KButton size="sm" variant="primary" @click="restoreAutosave">{{ __('Bring them back') }}</KButton>
                    <KButton size="sm" variant="ghost" @click="autosave = null">{{ __('Dismiss') }}</KButton>
                </div>
            </KAlert>
        </Teleport>

        <div v-show="!studio" class="k-editor">
            <div class="k-editor__main">
                <div class="k-editor__title" :class="{ 'has-error': err('title') }">
                    <label class="k-sr-only" for="k-entry-title">{{ __('Title') }}</label>
                    <textarea
                        id="k-entry-title"
                        v-model="form.title"
                        rows="1"
                        class="k-editor__title-input"
                        maxlength="255"
                        autocomplete="off"
                        data-lpignore="true"
                        data-1p-ignore
                        :placeholder="__('Title')"
                        :readonly="readOnly"
                        :aria-invalid="err('title') ? 'true' : undefined"
                        @input="
                            $event.target.style.height = 'auto';
                            $event.target.style.height = $event.target.scrollHeight + 'px';
                        "
                        @keydown.enter.prevent
                    ></textarea>
                    <p v-if="err('title')" class="k-field__error">{{ err('title') }}</p>
                    <div v-if="prefix !== null" class="k-editor__slug">
                        <span class="k-muted">{{ prefix }}</span>
                        <template v-if="editingSlug">
                            <label class="k-sr-only" for="k-entry-slug">{{ __('Slug') }}</label>
                            <input
                                id="k-entry-slug"
                                v-model="form.slug"
                                class="k-input k-editor__slug-input"
                                maxlength="191"
                                spellcheck="false"
                                autocomplete="off"
                                data-lpignore="true"
                                data-1p-ignore
                                @keydown.enter.prevent="editingSlug = false"
                                @blur="editingSlug = false"
                            />
                        </template>
                        <template v-else>
                            <span class="k-editor__slug-value">{{ shownSlug || '…' }}</span>
                            <button v-if="!readOnly" type="button" class="k-link-btn k-editor__slug-edit" @click="editingSlug = true">{{ __('Edit') }}</button>
                        </template>
                        <span v-if="!live && !editingSlug" class="k-muted k-small">· {{ __('follows the title until published') }}</span>
                    </div>
                    <p v-if="err('slug')" class="k-field__error">{{ err('slug') }}</p>
                </div>

                <div class="k-fields k-editor__fields">
                    <KContentField
                        v-for="f in mainFields"
                        :key="f.name"
                        v-model="form.data[f.name]"
                        :field="f"
                        :errors="errors"
                        path="data"
                        :disabled="readOnly"
                    />
                </div>

                <KField
                    v-if="type.excerpt"
                    v-slot="{ id, describedBy, invalid }"
                    :label="__('Excerpt')"
                    :help="__('A sentence or two for listings and search results. Leave empty to use the start of the text.')"
                    :error="err('excerpt')"
                    class="k-editor__excerpt"
                >
                    <textarea
                        :id="id"
                        v-model="form.excerpt"
                        class="k-input k-textarea"
                        rows="3"
                        maxlength="1000"
                        :readonly="readOnly"
                        :aria-invalid="invalid ? 'true' : undefined"
                        :aria-describedby="describedBy"
                    ></textarea>
                </KField>
            </div>

            <Teleport v-if="belowTarget" :to="belowTarget">
                <div class="k-fields k-studio__belowfields">
                    <KContentField
                        v-for="f in mainFields"
                        :key="`studio-${f.name}`"
                        v-model="form.data[f.name]"
                        :field="f"
                        :errors="errors"
                        path="data"
                        :disabled="readOnly"
                    />
                    <KField
                        v-if="type.excerpt"
                        v-slot="{ id, describedBy, invalid }"
                        :label="__('Excerpt')"
                        :help="__('A sentence or two for listings and search results. Leave empty to use the start of the text.')"
                        :error="err('excerpt')"
                    >
                        <textarea
                            :id="id"
                            v-model="form.excerpt"
                            class="k-input k-textarea"
                            rows="3"
                            maxlength="1000"
                            :readonly="readOnly"
                            :aria-invalid="invalid ? 'true' : undefined"
                            :aria-describedby="describedBy"
                        ></textarea>
                    </KField>
                </div>
            </Teleport>
            <Teleport :key="docTarget ? 'studio' : 'form'" :to="docTarget ?? 'body'" :disabled="!docTarget">
                <StudioPublish
                    v-if="docTarget"
                    v-model:publish-at="publishAt"
                    v-model:slug="form.slug"
                    class="k-studio__when"
                    :live="live"
                    :future="future"
                    :published-at="form.published_at"
                    :zone="zone"
                    :can-publish="can.publish"
                    :read-only="readOnly"
                    :prefix="prefix"
                    :shown-slug="shownSlug"
                    :slug-error="err('slug')"
                    :created-at="entry?.created_at ?? null"
                    :version="entry?.version ?? null"
                />
                <aside class="k-editor__side" :aria-label="__('Publishing')">
                    <KCard
                        v-if="!docTarget || (studioExtras && !readOnly)"
                        :title="live ? __('Published') : status === 'scheduled' ? __('Scheduled') : status === 'pending' ? __('In review') : __('Publish')"
                    >
                        <!-- in the visual editor the time and facts are in the timetable above (StudioPublish) -->
                        <div v-if="!docTarget" class="k-form">
                            <KField
                                v-slot="{ id, describedBy }"
                                :label="live ? __('Published on') : __('Publish on')"
                                :help="
                                    future
                                        ? __('Goes live automatically at this time ({zone}).', { zone })
                                        : __('Leave empty to publish now. Times are in {zone}.', { zone })
                                "
                                :error="err('published_at')"
                            >
                                <input
                                    :id="id"
                                    v-model="publishAt"
                                    type="datetime-local"
                                    class="k-input"
                                    :disabled="readOnly || !can.publish"
                                    :aria-describedby="describedBy"
                                />
                            </KField>
                            <dl v-if="entry" class="k-facts">
                                <div>
                                    <dt>{{ __('Created') }}</dt>
                                    <dd><KTime :value="entry.created_at" /></dd>
                                </div>
                                <div>
                                    <dt>{{ __('Version') }}</dt>
                                    <dd class="k-num">{{ entry.version }}</dd>
                                </div>
                            </dl>
                        </div>
                        <template v-if="!readOnly && (!docTarget || studioExtras)" #footer>
                            <!-- in the visual editor the bar has the main actions: only the others stay here -->
                            <div class="k-publish-actions">
                                <template v-if="live || status === 'scheduled'">
                                    <KButton v-if="!docTarget" variant="primary" block :loading="saving" :disabled="!dirty" @click="save()">{{
                                        status === 'scheduled' ? __('Update schedule') : __('Update')
                                    }}</KButton>
                                    <KButton v-if="can.publish" variant="ghost" size="sm" :disabled="saving" @click="save('draft')">{{
                                        __('Unpublish (back to draft)')
                                    }}</KButton>
                                </template>
                                <template v-else-if="status === 'pending'">
                                    <template v-if="can.publish">
                                        <KButton v-if="!docTarget" variant="primary" block :loading="saving" @click="save('published')">{{
                                            future ? __('Approve and schedule') : __('Approve and publish')
                                        }}</KButton>
                                        <KButton variant="secondary" block :disabled="saving" @click="asking = true">{{ __('Request changes') }}</KButton>
                                        <KButton v-if="!docTarget" variant="ghost" size="sm" :disabled="saving || !dirty" @click="save()">{{
                                            __('Save without deciding')
                                        }}</KButton>
                                    </template>
                                    <template v-else>
                                        <KButton v-if="!docTarget" variant="primary" block :loading="saving" :disabled="!dirty" @click="save()">{{
                                            __('Save changes')
                                        }}</KButton>
                                        <KButton variant="ghost" size="sm" :disabled="saving" @click="save('draft')">{{
                                            __('Withdraw (back to draft)')
                                        }}</KButton>
                                        <p class="k-muted k-small">
                                            {{ __('Waiting for review. An editor publishes it or asks for changes, and you are told either way.') }}
                                        </p>
                                    </template>
                                </template>
                                <template v-else>
                                    <template v-if="!docTarget">
                                        <KButton v-if="can.publish" variant="primary" block :loading="saving" @click="save('published')">{{
                                            future ? __('Schedule') : __('Publish')
                                        }}</KButton>
                                        <KButton v-else variant="primary" block :loading="saving" @click="save('pending')">{{
                                            __('Submit for review')
                                        }}</KButton>
                                        <KButton variant="secondary" block :disabled="saving || (!dirty && !isNew)" @click="save('draft')">{{
                                            __('Save draft')
                                        }}</KButton>
                                    </template>
                                    <p v-if="!can.publish" class="k-muted k-small">
                                        {{ __('Submit it when it is ready. An editor reviews it and publishes it.') }}
                                    </p>
                                </template>
                            </div>
                        </template>
                    </KCard>

                    <KCard v-if="type.hierarchical || canReassign || entry || templateOptions.length > 1 || multilingual" :title="__('Details')">
                        <div class="k-form">
                            <KField
                                v-if="multilingual && isNew"
                                v-slot="{ id }"
                                :label="__('Language')"
                                :help="__('The language this is written in. It stays once saved; translate it into the others afterwards.')"
                                :error="err('locale')"
                            >
                                <KSelect :id="id" v-model="newLanguage" :options="languageOptions" :disabled="readOnly" />
                            </KField>
                            <dl v-else-if="multilingual" class="k-facts">
                                <div>
                                    <dt>{{ __('Language') }}</dt>
                                    <dd>{{ languageName }}</dd>
                                </div>
                            </dl>
                            <KField
                                v-if="templateOptions.length > 1"
                                v-slot="{ id }"
                                class="k-studio__template"
                                :label="__('Template')"
                                :help="__('How this page is laid out. Templates come from Appearance → Templates.')"
                                :error="err('template')"
                            >
                                <KSelect :id="id" v-model="form.template" :options="templateOptions" :disabled="readOnly" />
                            </KField>
                            <KField
                                v-if="type.hierarchical"
                                v-slot="{ id }"
                                :label="__('Parent')"
                                :help="__('Nests this page under another one, and its address under theirs. Empty: a top-level page.')"
                                :error="err('parent_id')"
                            >
                                <KPicker :id="id" v-model="form.parent_id" :search="searchParents" :resolve="resolveParents" :disabled="readOnly" />
                            </KField>
                            <KField
                                v-if="type.hierarchical"
                                v-slot="{ id }"
                                :label="__('Page order')"
                                :help="__('Where this page comes when pages are listed in order: lower numbers first.')"
                                :error="err('menu_order')"
                            >
                                <KInput :id="id" v-model.number="form.menu_order" type="number" :disabled="readOnly" />
                            </KField>
                            <KField v-if="canReassign" v-slot="{ id }" :label="__('Author')" :error="err('author_id')">
                                <KPicker :id="id" v-model="form.author_id" :search="searchUsers" :resolve="resolveUsers" :disabled="readOnly" />
                            </KField>
                            <dl v-else-if="entry" class="k-facts">
                                <div>
                                    <dt>{{ __('Author') }}</dt>
                                    <dd>{{ entry.author?.username ?? __('Nobody') }}</dd>
                                </div>
                            </dl>
                        </div>
                    </KCard>

                    <PublishChecklist
                        v-if="type.checklist && !readOnly && !live"
                        :type-name="typeName"
                        :entry-id="entry?.id ?? null"
                        :form="form"
                        :error="err('checklist')"
                    />

                    <KCard v-for="f in sideFields" :key="`side-${f.name}`" class="k-editor__side-field">
                        <KContentField v-model="form.data[f.name]" :field="f" :errors="errors" path="data" :disabled="readOnly" />
                    </KCard>

                    <KCard v-for="tax in taxonomies" :key="tax.name" :title="tax.plural">
                        <KTermSelect v-model="form.terms[tax.name]" :taxonomy="tax" :disabled="readOnly" :locale="multilingual ? language : null" />
                        <p v-if="err(`terms.${tax.name}`)" class="k-field__error">{{ err(`terms.${tax.name}`) }}</p>
                    </KCard>

                    <ReviewPanel
                        v-if="entry && !entry.trashed_at"
                        ref="reviewPanel"
                        :base="`${base}/${entry.id}`"
                        :type-base="`/content/${typeName}`"
                        :entry="entry"
                        :can-edit="can.edit"
                        :can-moderate="!!type.can?.edit_others"
                        @assigned="onAssigned"
                    />
                    <SharePreviews v-if="canPreview" :base="`${base}/${entry.id}`" :singular="type.singular" />
                    <KCard
                        v-if="entry && multilingual"
                        :title="__('Translations')"
                        :description="
                            __('This {item} in the site\'s other languages. A new translation starts as a draft with these fields.', {
                                item: type.singular.toLowerCase(),
                            })
                        "
                    >
                        <TranslationLinks
                            :key="entry.id"
                            :type="typeName"
                            :entry="entry"
                            :languages="languages"
                            :main="languageInfo.data.value?.main ?? ''"
                            :can-translate="can.edit && !dirty"
                        />
                        <p v-if="can.edit && dirty" class="k-muted k-small">{{ __('Save your changes first, so the translation starts from them.') }}</p>
                    </KCard>
                    <LinkedFrom v-if="entry" :key="entry.id" :base="`${base}/${entry.id}`" />

                    <KCard v-if="entry && type.revisions" :title="__('Revisions')" flush>
                        <ol v-if="revisions.data.value?.length" class="k-revisions">
                            <li v-for="rev in revisions.data.value" :key="rev.id" class="k-revisions__item">
                                <div class="k-revisions__head">
                                    <KTime :value="rev.created_at" />
                                    <span class="k-muted">{{ rev.author?.username ?? __('someone') }}</span>
                                </div>
                                <p class="k-revisions__what k-small">
                                    <template v-if="rev.current">{{ __('Current version') }}</template>
                                    <template v-else-if="rev.changed === null">{{ __('First saved') }}</template>
                                    <template v-else-if="rev.changed.length">{{
                                        __('Changed {list}', { list: rev.changed.map(fieldLabel).join(', ') })
                                    }}</template>
                                    <template v-else>{{ __('No content changes') }}</template>
                                </p>
                                <div class="k-revisions__actions">
                                    <KButton size="sm" variant="ghost" icon="git-diff" @click="compare(rev)">{{ __('Compare') }}</KButton>
                                    <KButton
                                        v-if="!rev.current && !readOnly"
                                        size="sm"
                                        variant="ghost"
                                        icon="arrow-counter-clockwise"
                                        @click="restoreRevision(rev)"
                                        >{{ __('Restore') }}</KButton
                                    >
                                </div>
                            </li>
                        </ol>
                        <p v-else class="k-card__body k-muted k-small">
                            {{ revisions.loading.value ? __('Loading…') : __('Revisions appear after the first save.') }}
                        </p>
                    </KCard>

                    <KModal :open="asking" :title="__('Request changes')" @close="asking = false">
                        <KField
                            v-slot="{ id, describedBy }"
                            :label="__('What should change?')"
                            :help="
                                __('Sent to whoever submitted it, and kept with the comments. The {item} goes back to draft.', {
                                    item: type.singular.toLowerCase(),
                                })
                            "
                        >
                            <textarea :id="id" v-model="note" class="k-input k-textarea" rows="5" maxlength="5000" :aria-describedby="describedBy"></textarea>
                        </KField>
                        <template #footer>
                            <KButton variant="ghost" @click="asking = false">{{ __('Cancel') }}</KButton>
                            <KButton variant="primary" :loading="sending" :disabled="!note.trim()" @click="requestChanges">{{ __('Send back') }}</KButton>
                        </template>
                    </KModal>

                    <KModal
                        :open="!!comparing"
                        :title="comparing ? __('Changes saved {when}', { when: formatDateTime(comparing.created_at) }) : ''"
                        size="lg"
                        @close="comparing = null"
                    >
                        <div v-if="comparing" class="k-compare">
                            <KField v-if="!comparing.current" v-slot="{ id }" :label="__('Compare with')">
                                <KSelect :id="id" v-model="compareWith" :options="compareOptions" />
                            </KField>
                            <p class="k-muted k-small">
                                <template v-if="compareWith === 'current' && !comparing.current">{{
                                    __('What changed between this version, saved by {who}, and the current version.', {
                                        who: comparing.author?.username ?? __('someone'),
                                    })
                                }}</template>
                                <template v-else-if="diff && !diff.against">{{
                                    __('The first saved version, saved by {who}.', { who: comparing.author?.username ?? __('someone') })
                                }}</template>
                                <template v-else>{{ __('What {who} changed in this save.', { who: comparing.author?.username ?? __('someone') }) }}</template>
                            </p>
                            <KAlert v-if="diffError" tone="danger">{{ diffError.message }}</KAlert>
                            <RevisionDiff v-else-if="diff" :changes="diff.changes" />
                            <p v-else class="k-muted">{{ __('Comparing…') }}</p>
                        </div>
                        <template v-if="comparing && !comparing.current && !readOnly" #footer>
                            <KButton variant="ghost" @click="comparing = null">{{ __('Close') }}</KButton>
                            <KButton icon="arrow-counter-clockwise" @click="restoreCompared">{{ __('Restore this version') }}</KButton>
                        </template>
                    </KModal>

                    <div v-if="entry && !entry.trashed_at && can.delete" class="k-editor__trash">
                        <KButton variant="ghost" size="sm" icon="trash" @click="moveToTrash">{{ __('Move to trash') }}</KButton>
                    </div>
                </aside>
            </Teleport>
        </div>
    </template>
</template>

<style scoped>
.k-studio {
    position: fixed;
    inset: 0;
    margin: 0 !important;
    z-index: 60;
    display: flex;
    flex-direction: column;
    background: var(--k-sheet);
}
.k-studio__back {
    flex: none;
    display: inline-grid;
    place-items: center;
    width: 32px;
    height: 32px;
    border-radius: var(--k-radius);
    color: var(--k-ink);
    font-size: 18px;
}
.k-studio__back:hover {
    background: var(--k-sheet-2);
}
.k-studio__back:focus-visible {
    outline: 2px solid var(--k-focus);
    outline-offset: 1px;
}
.k-studio__title {
    width: clamp(9rem, 22vw, 26rem);
    min-width: 0;
    height: 32px;
    padding: 0 8px;
    border: 1px solid transparent;
    border-radius: var(--k-radius);
    background: none;
    color: var(--k-ink);
    font: inherit;
    font-size: 14px;
    font-weight: 700;
    text-overflow: ellipsis;
}
.k-studio__title:hover {
    border-color: var(--k-rule);
}
.k-studio__title:focus {
    outline: none;
    border-color: var(--k-ink);
}
.k-studio__title::placeholder {
    color: var(--k-ink-3);
    font-weight: 600;
}
.k-studio__title[aria-invalid='true'] {
    border-color: var(--k-danger);
}
.k-studio__state {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin-right: 6px;
    font-size: 12.5px;
    white-space: nowrap;
    color: var(--k-ink-3);
}
.k-studio__status {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: var(--k-ink-2);
    font-weight: 600;
}
.k-studio__status::before {
    content: '';
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: var(--k-ink-3);
}
.k-studio__status.is-published::before {
    background: var(--k-success);
}
.k-studio__status.is-scheduled::before {
    background: var(--k-sched);
}
.k-studio__status.is-pending::before,
.k-studio__status.is-private::before {
    background: var(--k-warning);
}
.k-studio__note {
    display: inline-flex;
    gap: 0.3em;
}
.k-studio__status + .k-studio__note::before {
    content: '·';
    margin-right: 0.3em;
}
.k-studio__note.is-dirty {
    color: var(--k-ink-2);
}
.k-studio__error {
    margin: 0;
    padding: 6px 16px;
    border-bottom: 1px solid var(--k-rule);
    color: var(--k-danger);
    font-size: 12.5px;
}
.k-studio__alerts:empty {
    display: none;
}
.k-studio__alerts {
    padding: 8px 12px 0;
}
.k-studio .k-studio__builder {
    flex: 1;
    height: auto;
    min-height: 0;
    border: 0;
}
.k-studio__doc :deep(.k-editor__side) {
    display: grid;
    gap: 12px;
}
/* inside the settings panel the cards are sections, not boxes within a box */
.k-studio__doc :deep(.k-card) {
    border: 0;
    border-top: 1px solid var(--k-rule);
    border-radius: 0;
    background: none;
}
.k-studio__doc :deep(.k-card__header) {
    min-height: 0;
    padding: 12px 0 4px;
    border-bottom: 0;
}
.k-studio__doc :deep(.k-card__body) {
    padding: 6px 0 12px;
}
.k-studio__doc :deep(.k-card__footer) {
    justify-content: flex-start;
    padding: 8px 0 12px;
    border-top: 0;
}
.k-studio__when {
    margin-bottom: 16px;
}
.k-studio__belowfields {
    max-width: 60rem;
    margin: 0 auto;
    padding-top: 12px;
}
/* narrower: the save state stays, shorter (no time); buttons keep their icons */
@media (max-width: 1280px) {
    .k-studio__wide {
        display: none;
    }
}
@media (max-width: 760px) {
    .k-studio__state {
        display: none;
    }
}
</style>
