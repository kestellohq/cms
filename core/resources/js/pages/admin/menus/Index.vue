<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { Head } from '@kestello/app';
import { ApiError, confirm, notify, useApi, useLeaveGuard, useResource, __ } from '@kestello/sdk';
import { KAlert, KBadge, KButton, KCard, KEmpty, KField, KIcon, KInput, KPageHeader, KPicker, KSelect, KTabs } from '@kestello/ui';

defineOptions({ layout: 'Admin' });

const api = useApi();

// ── languages: on a site in several languages, each can put its own menus in the locations ──
const languageInfo = useResource('/content/languages');
const languages = computed(() => languageInfo.data.value?.languages ?? []);
const multilingual = computed(() => languages.value.length > 1);
const languageOptions = computed(() => languages.value.map((l) => ({ value: l.code, label: l.main ? __('{language} (main)', { language: l.name }) : l.name })));
const mainLanguage = computed(() => languages.value.find((l) => l.main)?.name ?? '');
const locationLanguage = ref(''); // '' is the main language

const overview = useResource('/menus', { query: () => ({ locale: locationLanguage.value || 'main' }) });
const types = useResource('/content/types');
const taxonomies = useResource('/taxonomies');
const MAX_DEPTH = 4;

// ── locations ────────────────────────────────────────────────────────
const locations = computed(() => overview.data.value?.locations ?? []);
const menuName = (id) => (overview.data.value?.menus ?? []).find((m) => m.id === id)?.name ?? null;
// in another language, the empty choice means "the main language's menu"
function menuOptions(location) {
    const inherited = menuName(location.main_menu_id ?? null);
    const empty =
        locationLanguage.value === ''
            ? __('No menu')
            : inherited === null
              ? __('Same as {language} (no menu)', { language: mainLanguage.value })
              : __('Same as {language} ({menu})', { language: mainLanguage.value, menu: inherited });

    return [{ value: '', label: empty }, ...(overview.data.value?.menus ?? []).map((m) => ({ value: String(m.id), label: m.name }))];
}
async function assign(location, value) {
    try {
        await api.put('/menus/locations', { locale: locationLanguage.value || 'main', locations: { [location.name]: value === '' ? null : Number(value) } });
        notify.success(
            value !== ''
                ? __('{location} now shows this menu.', { location: location.label })
                : locationLanguage.value === ''
                  ? __('{location} is now empty.', { location: location.label })
                  : __('{location} now uses the same menu as {language}.', { location: location.label, language: mainLanguage.value }),
        );
        overview.reload({ silent: true });
    } catch (e) {
        notify.error(e);
    }
}

// ── the menu being edited ────────────────────────────────────────────
const selectedId = ref(null);
const name = ref('');
const items = ref([]); // flat: { key, depth, kind, ref, url, label, new_tab, resolved }
const saved = ref('');
const errors = ref({});
const saving = ref(false);
let nextKey = 1;

async function open(id) {
    if (
        dirty.value &&
        !(await confirm({
            title: __('Discard unsaved changes?'),
            message: __('Your changes to “{name}” are not saved.', { name: name.value }),
            confirmLabel: __('Discard'),
        }))
    )
        return;
    try {
        const { data } = await api.get(`/menus/${id}`);
        load(data);
    } catch (e) {
        notify.error(e);
    }
}
function load(menu) {
    selectedId.value = menu.id;
    name.value = menu.name;
    items.value = flatten(menu.items);
    errors.value = {};
    saved.value = snapshot();
}
function flatten(tree, depth = 0, out = []) {
    for (const item of tree) {
        const { children, ...rest } = item;
        out.push({ key: nextKey++, depth, label: '', new_tab: false, ...rest });
        flatten(children ?? [], depth + 1, out);
    }
    return out;
}
function toTree() {
    const root = [];
    const stack = [{ depth: -1, children: root }];
    for (const it of items.value) {
        const node = {
            kind: it.kind,
            ...(it.kind === 'url' ? { url: it.url } : { ref: it.ref }),
            ...(it.label ? { label: it.label } : {}),
            ...(it.new_tab ? { new_tab: true } : {}),
            children: [],
        };
        while (stack[stack.length - 1].depth >= it.depth) stack.pop();
        stack[stack.length - 1].children.push(node);
        stack.push({ depth: it.depth, children: node.children });
    }
    const strip = (list) => list.map(({ children, ...n }) => (children.length ? { ...n, children: strip(children) } : n));
    return strip(root);
}
const snapshot = () => JSON.stringify({ name: name.value, items: toTree() });
const dirty = computed(() => selectedId.value !== null && snapshot() !== saved.value);

// opens the first menu; after dirty and snapshot, which open() reads: with the list already loaded
// (coming back to this screen) it runs as the page opens
watch(
    () => overview.data.value?.menus,
    (menus) => {
        if (!menus) return;
        if (selectedId.value === null && menus.length) open(menus[0].id);
    },
    { immediate: true },
);

async function save() {
    saving.value = true;
    errors.value = {};
    try {
        const { data } = await api.put(`/menus/${selectedId.value}`, { name: name.value, items: toTree() });
        load(data);
        notify.success(__('Menu saved.'));
        overview.reload({ silent: true });
    } catch (e) {
        if (e instanceof ApiError && e.status === 422) {
            errors.value = e.fieldErrors();
            notify.error(__('Some items need attention.'));
        } else notify.error(e);
    } finally {
        saving.value = false;
    }
}

// Server errors are keyed by tree path (items.0.children.1.url); map them to rows.
const rowErrors = computed(() => {
    const out = {};
    const counters = [];
    const path = [];
    items.value.forEach((it) => {
        counters.length = it.depth + 1;
        counters[it.depth] = (counters[it.depth] ?? -1) + 1;
        path.length = it.depth;
        path[it.depth] = counters[it.depth];
        const prefix = 'items.' + path.slice(0, it.depth + 1).join('.children.');
        const msgs = Object.entries(errors.value)
            .filter(([k]) => k === prefix || (k.startsWith(prefix + '.') && !k.slice(prefix.length + 1).startsWith('children')))
            .map(([, v]) => v);
        if (msgs.length) out[it.key] = msgs.join(' ');
    });
    return out;
});

// ── new and deleted menus ────────────────────────────────────────────
const creating = ref(false);
const newName = ref('');
const creatingBusy = ref(false);
const newForm = ref(null);
// opening the card is asking to type a name: the caret goes there
watch(creating, (on) => on && nextTick(() => newForm.value?.querySelector('input')?.focus()));
async function create() {
    if (creatingBusy.value) return; // a double click makes one menu
    // the new menu replaces the open one: ask first, as opening another does
    if (
        dirty.value &&
        !(await confirm({
            title: __('Discard unsaved changes?'),
            message: __('Your changes to “{name}” are not saved.', { name: name.value }),
            confirmLabel: __('Discard'),
        }))
    )
        return;
    creatingBusy.value = true;
    try {
        const { data } = await api.post('/menus', { name: newName.value });
        creating.value = false;
        newName.value = '';
        saved.value = snapshot(); // nothing to discard
        load(data);
        overview.reload({ silent: true });
    } catch (e) {
        notify.error(e);
    } finally {
        creatingBusy.value = false;
    }
}
async function removeMenu() {
    const where = (overview.data.value?.menus ?? []).find((m) => m.id === selectedId.value)?.placements ?? []; // in every language
    const ok = await confirm({
        title: __('Delete “{name}”?', { name: name.value }),
        message: where.length
            ? __('{n, plural, one {It is shown in # theme location, which will be empty.} other {It is shown in # theme locations, which will be empty.}}', {
                  n: where.length,
              })
            : __('It is not shown anywhere.'),
        confirmLabel: __('Delete menu'),
        danger: true,
    });
    if (!ok) return;
    try {
        await api.delete(`/menus/${selectedId.value}`);
        selectedId.value = null;
        items.value = [];
        saved.value = snapshot();
        await overview.reload({ silent: true });
        const first = overview.data.value?.menus?.[0];
        if (first) open(first.id);
    } catch (e) {
        notify.error(e);
    }
}

// ── tree editing (flat list with depth) ──────────────────────────────
const end = (i) => {
    let j = i + 1;
    while (j < items.value.length && items.value[j].depth > items.value[i].depth) j++;
    return j;
};
function prevSibling(i) {
    for (let p = i - 1; p >= 0; p--) {
        if (items.value[p].depth === items.value[i].depth) return p;
        if (items.value[p].depth < items.value[i].depth) return -1;
    }
    return -1;
}
function nextSibling(i) {
    const j = end(i);
    return j < items.value.length && items.value[j].depth === items.value[i].depth ? j : -1;
}
function moveBlock(from, to) {
    const list = [...items.value];
    const block = list.splice(from, end(from) - from);
    list.splice(to, 0, ...block);
    items.value = list;
}
function up(i) {
    const p = prevSibling(i);
    if (p < 0) return; // the first item (Alt+ArrowUp reaches here too)
    moveBlock(i, p);
    focusRow(items.value[p].key);
}
function down(i) {
    const n = nextSibling(i);
    if (n < 0) return;
    const key = items.value[i].key;
    const size = end(i) - i;
    moveBlock(i, end(n) - size);
    focusRow(key);
}
function indent(i) {
    if (prevSibling(i) < 0) return;
    const list = [...items.value];
    for (let j = i; j < end(i); j++) list[j] = { ...list[j], depth: list[j].depth + 1 };
    if (list.slice(i, end(i)).some((x) => x.depth >= MAX_DEPTH)) return notify.warning(__('Menus nest at most {n} levels.', { n: MAX_DEPTH }));
    items.value = list;
}
function outdent(i) {
    const d = items.value[i].depth;
    if (d === 0) return;
    let parent = i - 1;
    while (items.value[parent].depth !== d - 1) parent--;
    const key = items.value[i].key;
    const target = end(parent);
    const size = end(i) - i;
    const list = [...items.value];
    for (let j = i; j < i + size; j++) list[j] = { ...list[j], depth: list[j].depth - 1 };
    items.value = list;
    moveBlock(i, target - size);
    focusRow(key);
}
function remove(i) {
    const list = [...items.value];
    const removed = list.splice(i, end(i) - i);
    items.value = list;
    if (removed.length > 1) notify.info(__('{n, plural, one {Removed with # sub-item.} other {Removed with # sub-items.}}', { n: removed.length - 1 }));
}
const expanded = reactive(new Set());
const toggle = (key) => (expanded.has(key) ? expanded.delete(key) : expanded.add(key));
function focusRow(key) {
    setTimeout(() => document.querySelector(`[data-menu-row="${key}"] .k-menu-row__title`)?.focus());
}

function keydown(e, i) {
    if (!e.altKey) return;
    const map = { ArrowUp: up, ArrowDown: down, ArrowRight: indent, ArrowLeft: outdent };
    if (map[e.key]) {
        e.preventDefault();
        map[e.key](i);
    }
}
function onKeys(e) {
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 's' && dirty.value) {
        e.preventDefault();
        save();
    }
}
useLeaveGuard(dirty);
onMounted(() => window.addEventListener('keydown', onKeys));
onBeforeUnmount(() => window.removeEventListener('keydown', onKeys));

// ── adding items ─────────────────────────────────────────────────────
const source = ref('entries');
const sources = computed(() => [
    { id: 'entries', label: __('Content') },
    ...((taxonomies.data.value ?? []).filter((t) => t.path).length ? [{ id: 'terms', label: __('Terms') }] : []),
    ...(archives.value.length ? [{ id: 'archives', label: __('Archives') }] : []),
    { id: 'url', label: __('Link') },
]);
const archives = computed(() => (types.data.value ?? []).filter((t) => t.archive));
function add(item) {
    items.value = [...items.value, { key: nextKey++, depth: 0, label: '', new_tab: false, ...item }];
    notify.success(__('Added “{label}” at the end.', { label: item.label || item.resolved?.label || '' }));
}

const picked = ref([]);
const entrySearch = async (q) =>
    (await api.get('/content/lookup', { search: q || undefined })).data.map((e) => ({
        id: e.id,
        label: e.title || __('(untitled)'),
        meta: e.type_label + (e.status !== 'published' ? ` · ${__('not published')}` : ''),
        entry: e,
    }));
const entryResolve = async (ids) => (await api.get('/content/lookup', { ids: ids.join(',') })).data.map((e) => ({ id: e.id, label: e.title, entry: e }));
watch(picked, async (ids) => {
    if (!ids.length) return;
    const list = (await api.get('/content/lookup', { ids: ids.join(',') })).data;
    for (const e of list)
        add({ kind: 'entry', ref: e.id, resolved: { label: e.title, url: e.path, live: e.status === 'published' && !!e.path, type_label: e.type_label } });
    picked.value = [];
});

const termTax = ref('');
watch(
    () => taxonomies.data.value,
    (list) => {
        if (!termTax.value && list?.length) termTax.value = list.find((t) => t.path)?.name ?? '';
    },
);
const termOptions = computed(() => (taxonomies.data.value ?? []).filter((t) => t.path).map((t) => ({ value: t.name, label: t.plural })));
const pickedTerms = ref([]);
const termSearch = async (q) =>
    (await api.get(`/taxonomies/${termTax.value}/terms`, { search: q || undefined, limit: 20 })).data.map((t) => ({
        id: t.id,
        label: t.name,
        meta: t.path,
        term: t,
    }));
const termResolve = async (ids) => (await api.get(`/taxonomies/${termTax.value}/terms`, { ids: ids.join(',') })).data.map((t) => ({ id: t.id, label: t.name }));
watch(pickedTerms, async (ids) => {
    if (!ids.length) return;
    const list = (await api.get(`/taxonomies/${termTax.value}/terms`, { ids: ids.join(',') })).data;
    const tax = (taxonomies.data.value ?? []).find((x) => x.name === termTax.value);
    for (const t of list) add({ kind: 'term', ref: t.id, resolved: { label: t.name, url: t.path, live: !!t.path, type_label: tax?.singular } });
    pickedTerms.value = [];
});

const link = reactive({ url: '', label: '' });
function addLink() {
    add({ kind: 'url', url: link.url.trim(), label: link.label.trim(), resolved: { label: '', url: link.url.trim(), live: true } });
    Object.assign(link, { url: '', label: '' });
}

const KIND = { entry: __('Page or post'), term: __('Term'), archive: __('Archive'), url: __('Link') };
const title = (it) => it.label || it.resolved?.label || (it.kind === 'url' ? it.url : __('(untitled)'));
const hidden = (it) => it.resolved && !it.resolved.live;
</script>

<template>
    <Head :title="__('Menus')" />
    <KPageHeader :title="__('Menus')" :description="__('How visitors find their way around. Build a menu here; your theme decides where each one appears.')">
        <template #actions>
            <KButton icon="plus" @click="creating = true">{{ __('New menu') }}</KButton>
        </template>
    </KPageHeader>

    <KAlert v-if="overview.error.value" tone="danger">{{ overview.error.value.message }}</KAlert>

    <KCard
        v-if="locations.length"
        coord="A1"
        :title="__('Theme locations')"
        :description="
            multilingual
                ? __(
                      'Which menu shows where, in each language. A language without a menu of its own uses the main language\'s, with links to its translations. Changes apply right away.',
                  )
                : __('Which menu shows where. Changes apply right away.')
        "
    >
        <template v-if="multilingual" #actions>
            <KSelect v-model="locationLanguage" :options="languageOptions" :aria-label="__('Language')" />
        </template>
        <div class="k-locations">
            <KField v-for="loc in locations" :key="loc.name" v-slot="{ id }" :label="loc.label" :help="loc.description">
                <KSelect
                    :id="id"
                    :model-value="loc.menu_id === null ? '' : String(loc.menu_id)"
                    :options="menuOptions(loc)"
                    @update:model-value="assign(loc, $event)"
                />
            </KField>
        </div>
    </KCard>
    <KAlert v-else-if="overview.data.value" tone="info">{{ __('The active theme declares no menu locations, so menus built here are not shown yet.') }}</KAlert>

    <KCard v-if="creating" :title="__('New menu')">
        <form ref="newForm" class="k-input-row" @submit.prevent="create">
            <KField v-slot="{ id }" :label="__('Name')" :help="__('Only editors see it. Such as “Main” or “Footer links”.')" class="k-menus__new-name">
                <KInput :id="id" v-model="newName" maxlength="100" autocomplete="off" />
            </KField>
            <KButton type="submit" variant="primary" :loading="creatingBusy" :disabled="!newName.trim()">{{ __('Create') }}</KButton>
            <KButton variant="ghost" @click="creating = false">{{ __('Cancel') }}</KButton>
        </form>
    </KCard>

    <KEmpty v-if="overview.data.value && !overview.data.value.menus.length && !creating" icon="tree-view" :title="__('No menus yet')">
        <p>{{ __('Create one, add pages and links, then put it in a theme location.') }}</p>
        <KButton variant="primary" icon="plus" @click="creating = true">{{ __('New menu') }}</KButton>
    </KEmpty>

    <div v-if="selectedId !== null" class="k-cells k-menu-editor" style="--k-cols: 3">
        <KCard coord="A2" :title="__('Add items')">
            <KTabs v-model="source" :tabs="sources" :label="__('What to add')" />
            <div class="k-menu-source">
                <template v-if="source === 'entries'">
                    <KField v-slot="{ id }" :label="__('Find a page or post')" :help="__('Titles and addresses stay in sync when the page changes.')">
                        <KPicker :id="id" v-model="picked" multiple :search="entrySearch" :resolve="entryResolve" />
                    </KField>
                </template>
                <template v-else-if="source === 'terms'">
                    <KField v-slot="{ id }" :label="__('Taxonomy')"><KSelect :id="id" v-model="termTax" :options="termOptions" /></KField>
                    <KField v-slot="{ id }" :label="__('Find a term')">
                        <KPicker :id="id" :key="termTax" v-model="pickedTerms" multiple :search="termSearch" :resolve="termResolve" />
                    </KField>
                </template>
                <template v-else-if="source === 'archives'">
                    <ul class="k-menu-archives">
                        <li v-for="t in archives" :key="t.name">
                            <span
                                ><strong>{{ t.plural }}</strong> <span class="k-muted k-mono-input">{{ t.path }}</span></span
                            >
                            <KButton
                                size="sm"
                                icon="plus"
                                @click="add({ kind: 'archive', ref: t.name, resolved: { label: t.plural, url: t.path, live: true } })"
                                >{{ __('Add') }}</KButton
                            >
                        </li>
                    </ul>
                </template>
                <form v-else class="k-form" @submit.prevent="addLink">
                    <KField v-slot="{ id }" :label="__('Address')" :help="__('https://…, /a-page, #section, mailto: or tel:')">
                        <KInput :id="id" v-model="link.url" inputmode="url" spellcheck="false" autocomplete="off" class="k-mono-input" />
                    </KField>
                    <KField v-slot="{ id }" :label="__('Label')"><KInput :id="id" v-model="link.label" maxlength="100" autocomplete="off" /></KField>
                    <div>
                        <KButton type="submit" icon="plus" :disabled="!link.url.trim() || !link.label.trim()">{{ __('Add link') }}</KButton>
                    </div>
                </form>
            </div>
        </KCard>

        <KCard coord="B2" class="k-span-2" flush>
            <template #title>
                <label class="k-sr-only" for="k-menu-name">{{ __('Menu name') }}</label>
                <input id="k-menu-name" v-model="name" class="k-menu-name" maxlength="100" autocomplete="off" />
            </template>
            <template #actions>
                <KSelect
                    v-if="(overview.data.value?.menus ?? []).length > 1"
                    :model-value="String(selectedId)"
                    :options="menuOptions.slice(1)"
                    :aria-label="__('Edit another menu')"
                    class="k-menu-switch"
                    @update:model-value="open(Number($event))"
                />
            </template>
            <p v-if="errors.name" class="k-field__error k-menu-error">{{ errors.name }}</p>
            <p v-if="errors.items" class="k-field__error k-menu-error">{{ errors.items }}</p>

            <ol v-if="items.length" class="k-menu-tree" :aria-label="__('Menu items')">
                <li
                    v-for="(it, i) in items"
                    :key="it.key"
                    class="k-menu-row"
                    :class="{ 'is-hidden': hidden(it), 'has-error': rowErrors[it.key], 'is-nested': it.depth > 0 }"
                    :style="{ '--k-depth': it.depth }"
                    :data-menu-row="it.key"
                >
                    <div class="k-menu-row__head">
                        <button
                            type="button"
                            class="k-menu-row__title"
                            :aria-expanded="expanded.has(it.key) ? 'true' : 'false'"
                            :aria-label="__('{title}, level {level}. Alt and arrow keys move it.', { title: title(it), level: it.depth + 1 })"
                            @click="toggle(it.key)"
                            @keydown="keydown($event, i)"
                        >
                            <KIcon :name="expanded.has(it.key) ? 'caret-down' : 'caret-right'" />
                            <span class="k-menu-row__label">{{ title(it) }}</span>
                        </button>
                        <span class="k-menu-row__meta">
                            <KBadge v-if="hidden(it)" tone="warning">{{ __('Hidden: not published') }}</KBadge>
                            <span class="k-muted k-small">{{ it.resolved?.type_label || KIND[it.kind] }}</span>
                        </span>
                        <span class="k-menu-row__tools">
                            <KButton size="sm" variant="ghost" icon="arrow-up" :disabled="prevSibling(i) < 0" :aria-label="__('Move up')" @click="up(i)" />
                            <KButton
                                size="sm"
                                variant="ghost"
                                icon="arrow-down"
                                :disabled="nextSibling(i) < 0"
                                :aria-label="__('Move down')"
                                @click="down(i)"
                            />
                            <KButton
                                size="sm"
                                variant="ghost"
                                icon="arrow-line-left"
                                :disabled="it.depth === 0"
                                :aria-label="__('Move out a level')"
                                @click="outdent(i)"
                            />
                            <KButton
                                size="sm"
                                variant="ghost"
                                icon="arrow-line-right"
                                :disabled="prevSibling(i) < 0"
                                :aria-label="__('Nest under the item above')"
                                @click="indent(i)"
                            />
                            <KButton size="sm" variant="ghost" icon="x" :aria-label="__('Remove {title}', { title: title(it) })" @click="remove(i)" />
                        </span>
                    </div>
                    <p v-if="rowErrors[it.key]" class="k-field__error k-menu-row__error">{{ rowErrors[it.key] }}</p>
                    <div v-if="expanded.has(it.key)" class="k-menu-row__body">
                        <KField
                            v-slot="{ id }"
                            :label="__('Label')"
                            :help="it.kind === 'url' ? '' : __('Leave empty to use “{title}”.', { title: it.resolved?.label ?? '' })"
                        >
                            <KInput :id="id" v-model="it.label" maxlength="100" autocomplete="off" />
                        </KField>
                        <KField v-if="it.kind === 'url'" v-slot="{ id }" :label="__('Address')">
                            <KInput :id="id" v-model="it.url" spellcheck="false" autocomplete="off" class="k-mono-input" />
                        </KField>
                        <p v-else class="k-muted k-small">
                            {{ __('Links to') }} <span class="k-mono-input">{{ it.resolved?.url ?? '—' }}</span>
                        </p>
                        <label class="k-choice"
                            ><input v-model="it.new_tab" type="checkbox" /> <span>{{ __('Open in a new tab') }}</span></label
                        >
                    </div>
                </li>
            </ol>
            <p v-else class="k-card__body k-muted">{{ __('This menu is empty. Add pages, posts or links from the left.') }}</p>

            <template #footer>
                <div class="k-form__actions">
                    <KButton variant="ghost" icon="trash" class="k-menu-delete" @click="removeMenu">{{ __('Delete menu') }}</KButton>
                    <span v-if="dirty" class="k-muted k-small">{{ __('Unsaved changes') }}</span>
                    <KButton variant="primary" :loading="saving" :disabled="!dirty" @click="save">{{ __('Save menu') }}</KButton>
                </div>
            </template>
        </KCard>
    </div>
</template>

<style scoped>
/* a menu's name is short: the box needn't cross the screen */
.k-menus__new-name {
    flex: 0 1 28rem;
}
</style>
