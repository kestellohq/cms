<script setup>
import { computed, ref } from 'vue';
import { ApiError, confirm, formatDateTime, notify, useApi, useCan, __ } from '@kestello/sdk';
import { KAlert, KBadge, KButton, KEmpty, KField, KIcon, KModal, KPageHeader, KSwitch } from '@kestello/ui';
import StoppedUpdate from '../../../components/updates/StoppedUpdate.vue';
import { rememberSwitchBack, switchBackOutcome, takeSwitchBack } from '../../../components/updates/switch-back.js';

defineOptions({ layout: 'Admin' });

/**
 * Plugins: activate, deactivate, delete, and install from a zip. An upload
 * is reviewed first (who signed it, what it declares it will do); only
 * packages signed by a publisher the server trusts install from here.
 * After an update, Switch back goes to the version before it, with the
 * database from before it.
 */
const api = useApi();
const data = ref(null);
const loadError = ref(null);
const busy = ref('');
const open = ref({});
const can = useCan();

async function load() {
    try {
        data.value = (await api.get('/plugins')).data;
    } catch (e) {
        loadError.value = e;
        return;
    }
    const note = takeSwitchBack();
    if (note?.owner.startsWith('plugin:')) {
        const running = data.value.plugins.find((p) => `plugin:${p.slug}` === note.owner)?.version ?? null;
        const outcome = switchBackOutcome(note, running, data.value.stopped_update);
        if (outcome === 'done') notify.success(__('{plugin} {v} is back, with the database from before the update.', { plugin: note.name, v: note.version }));
        else if (outcome === 'not-done')
            notify.error(__('Switching {plugin} back to {v} did not finish. The audit log says why.', { plugin: note.name, v: note.version }));
    }
}
load();

async function switchBack(plugin) {
    const option = plugin.switch_back;
    if (
        !(await confirm({
            title: __('Switch {plugin} back to {v}?', { plugin: plugin.name, v: option.to }),
            message: __(
                '{plugin} {v} is still on the server. Switching back also puts the database back as it was before the update, on {date}: anything changed on the site since then is lost from the site. A backup of the site as it is now is made first, under Site → Backups. Visitors see a short maintenance page.',
                { plugin: plugin.name, v: option.to, date: formatDateTime(option.backup_at || option.updated_at) },
            ),
            confirmLabel: __('Switch back to {v}', { v: option.to }),
            danger: true,
        }))
    )
        return;
    busy.value = `switch-back:${plugin.slug}`;
    try {
        await api.post('/plugins/switch-back', { owner: `plugin:${plugin.slug}` });
        rememberSwitchBack(`plugin:${plugin.slug}`, plugin.name, option.to);
        // the next request puts the old version and the database back, before plugins load
        window.location.reload();
    } catch (e) {
        notify.error(e);
        busy.value = '';
    }
}

const statusTone = { active: 'success', inactive: 'neutral', suspended: 'danger', failed: 'danger' };
const statusLabel = { active: __('Active'), inactive: __('Inactive'), suspended: __('Suspended'), failed: __('Failed to start') };
const names = computed(() => Object.fromEntries((data.value?.plugins ?? []).map((p) => [p.slug, p.name])));

const permissionLabels = {
    http: __('Connects to'),
    database: __('Database'),
    filesystem: __('Files'),
    mail: __('Sends email'),
    events: __('Listens to'),
    capabilities: __('Adds permissions'),
};
function permissionRows(permissions, replaces = []) {
    const rows = Object.entries(permissions ?? {}).map(([area, value]) => ({
        label: permissionLabels[area] ?? area,
        value: Array.isArray(value)
            ? value.join(', ')
            : typeof value === 'object' && value !== null
              ? Object.entries(value)
                    .map(([k, v]) => `${k}: ${v}`)
                    .join(', ')
              : String(value),
    }));
    if (replaces.length) rows.push({ label: __('Replaces core services'), value: replaces.join(', ') });
    return rows;
}

async function act(plugin, action) {
    busy.value = `${action}:${plugin.slug}`;
    try {
        data.value = (await api.post(`/plugins/${plugin.slug}/${action}`)).data;
        notify.success(
            action === 'activate'
                ? __('{plugin} is active.', { plugin: plugin.name })
                : __('{plugin} was deactivated. Its data is kept.', { plugin: plugin.name }),
        );
    } catch (e) {
        notify.error(e);
    } finally {
        busy.value = '';
    }
}

async function remove(plugin) {
    if (
        !(await confirm({
            title: __('Delete {plugin}?', { plugin: plugin.name }),
            message: __('Its files, its tables and its settings are deleted. Make a backup first if you may want them back.'),
            confirmLabel: __('Delete plugin and data'),
            danger: true,
        }))
    )
        return;
    busy.value = `delete:${plugin.slug}`;
    try {
        data.value = (await api.delete(`/plugins/${plugin.slug}`)).data;
        notify.success(__('{plugin} was deleted.', { plugin: plugin.name }));
    } catch (e) {
        notify.error(e);
    } finally {
        busy.value = '';
    }
}

// ── upload, review, install ──────────────────────────────────────────
const adding = ref(false);
const file = ref(null);
const review = ref(null);
const uploadErrors = ref({});
const replace = ref(false);
const activate = ref(true);
const failed = ref(null);

function openAdd() {
    adding.value = true;
    file.value = null;
    review.value = null;
    uploadErrors.value = {};
    replace.value = false;
    activate.value = true;
    failed.value = null;
}

async function upload() {
    if (!file.value) return;
    busy.value = 'upload';
    uploadErrors.value = {};
    const form = new FormData();
    form.append('file', file.value);
    try {
        review.value = (await api.upload('/plugins/upload', form)).data;
        replace.value = !!review.value.package.installed && !review.value.package.installed.active;
    } catch (e) {
        if (e instanceof ApiError && e.status === 422) uploadErrors.value = e.fieldErrors();
        else notify.error(e);
    } finally {
        busy.value = '';
    }
}

const pkg = computed(() => review.value?.package ?? null);
const warnings = computed(() => (pkg.value?.findings ?? []).filter((f) => f.level !== 'error'));
const errors = computed(() => (pkg.value?.findings ?? []).filter((f) => f.level === 'error'));
const signature = computed(() => {
    const s = pkg.value?.signature;
    if (!s) return null;
    return {
        signed: { icon: 'seal-check', tone: 'success', text: __('Signed by {name}, a publisher this site trusts.', { name: s.name }) },
        untrusted: { icon: 'seal-question', tone: 'warning', text: __('Signed with a key this site does not trust ({key}).', { key: s.key }) },
        unsigned: { icon: 'seal-question', tone: 'warning', text: __('Not signed: nobody vouches for where it came from.') },
        tampered: { icon: 'seal-warning', tone: 'danger', text: __('Changed after it was signed. Do not install it.') },
    }[s.status];
});
const blockedReason = computed(() => {
    if (!review.value || review.value.allowed) return '';
    if (pkg.value.signature.status === 'tampered') return __('This package was changed after it was signed, so it is refused.');
    return data.value?.policy.trusted.length
        ? __('Only packages signed by {publishers} install from here. A developer can install this one on the server: bin/cms plugin:install <zip>.', {
              publishers: data.value.policy.trusted.join(', '),
          })
        : __(
              'No publisher is trusted on this site yet, so plugins install on the server: bin/cms plugin:install <zip>. Trusted publishers are set in config/app.php (packages.trusted_keys).',
          );
});
const replaceBlocked = computed(() => pkg.value?.installed?.active);
const update = computed(() => review.value?.update ?? null);

async function runUpdate() {
    busy.value = 'update';
    failed.value = null;
    try {
        const response = (await api.post('/plugins/update', { token: review.value.token })).data;
        data.value = response;
        const r = response.result;
        if (r.ok) {
            adding.value = false;
            notify.success(__('{plugin} was updated to {v}.', { plugin: pkg.value.name, v: r.to }));
        } else {
            failed.value = r;
        }
    } catch (e) {
        notify.error(e);
    } finally {
        busy.value = '';
    }
}

async function install() {
    busy.value = 'install';
    try {
        const result = (await api.post('/plugins/install', { token: review.value.token, replace: replace.value, activate: activate.value })).data;
        data.value = result;
        adding.value = false;
        if (result.activation_problem)
            notify.error(__('{plugin} was installed but could not be activated: {problem}', { plugin: pkg.value.name, problem: result.activation_problem }));
        else
            notify.success(
                result.activated
                    ? __('{plugin} was installed and is active.', { plugin: pkg.value.name })
                    : __('{plugin} was installed.', { plugin: pkg.value.name }),
            );
    } catch (e) {
        notify.error(e);
    } finally {
        busy.value = '';
    }
}
</script>

<template>
    <KPageHeader
        :title="__('Plugins')"
        :description="
            __(
                'Plugins add features to the site. Each one declares what it may do, and one that breaks is switched off on its own instead of taking the site down.',
            )
        "
    >
        <template v-if="data" #actions>
            <KButton variant="primary" icon="upload" @click="openAdd">{{ __('Upload a plugin') }}</KButton>
        </template>
    </KPageHeader>
    <KAlert v-if="loadError" tone="danger">{{ loadError.message }}</KAlert>
    <KAlert v-if="data?.recovery" tone="warning" :title="__('Recovery mode')">{{
        __('No plugins are running in this session, so you can deactivate or delete the one causing trouble.')
    }}</KAlert>
    <KAlert v-for="msg in data?.invalid ?? []" :key="msg" tone="warning">{{ msg }}</KAlert>
    <StoppedUpdate v-if="data?.stopped_update" :stopped="data.stopped_update" :can-retry="can('core.update')" />

    <template v-if="data">
        <KEmpty v-if="!data.plugins.length" icon="plug" :title="__('No plugins installed')">{{ __('Upload a plugin zip to add one.') }}</KEmpty>
        <ul v-else class="k-plugins">
            <li v-for="p in data.plugins" :key="p.slug" class="k-plugin" :class="`is-${p.status}`">
                <div class="k-plugin__main">
                    <div class="k-plugin__title">
                        <h2>{{ p.name }}</h2>
                        <span class="k-num k-muted k-small">{{ p.version }}</span>
                        <KBadge :tone="statusTone[p.status]">{{ statusLabel[p.status] }}</KBadge>
                    </div>
                    <p v-if="p.description" class="k-plugin__desc">{{ p.description }}</p>
                    <p class="k-muted k-small k-plugin__meta">
                        <template v-if="p.author">{{ __('By {author}', { author: p.author }) }}</template>
                        <template v-if="Object.keys(p.requires.plugins).length">
                            ·
                            {{
                                __('Needs {plugins}', {
                                    plugins: Object.keys(p.requires.plugins)
                                        .map((s) => names[s] ?? s)
                                        .join(', '),
                                })
                            }}</template
                        >
                        <template v-if="p.required_by.length">
                            · {{ __('Needed by {plugins}', { plugins: p.required_by.map((s) => names[s] ?? s).join(', ') }) }}</template
                        >
                    </p>
                    <p v-if="p.problem" class="k-plugin__problem"><KIcon name="warning-octagon" /> {{ p.problem }}</p>
                    <p v-if="p.switch_back" class="k-muted k-small k-plugin__back">
                        {{ __('Updated from {v} on {date}.', { v: p.switch_back.to, date: formatDateTime(p.switch_back.updated_at) }) }}
                    </p>
                    <button
                        v-if="permissionRows(p.permissions, p.replaces).length"
                        type="button"
                        class="k-link-btn k-small"
                        :aria-expanded="!!open[p.slug]"
                        @click="open[p.slug] = !open[p.slug]"
                    >
                        {{ open[p.slug] ? __('Hide what it may do') : __('What it may do') }}
                    </button>
                    <dl v-if="open[p.slug]" class="k-plugin__perms">
                        <template v-for="row in permissionRows(p.permissions, p.replaces)" :key="row.label"
                            ><dt>{{ row.label }}</dt>
                            <dd>{{ row.value }}</dd></template
                        >
                    </dl>
                </div>
                <div class="k-plugin__actions">
                    <KButton
                        v-if="p.switch_back"
                        size="sm"
                        icon="clock-counter-clockwise"
                        :loading="busy === `switch-back:${p.slug}`"
                        :disabled="!!busy || !!data.stopped_update"
                        @click="switchBack(p)"
                        >{{ __('Switch back to {v}', { v: p.switch_back.to }) }}</KButton
                    >
                    <KButton v-if="p.status === 'inactive'" size="sm" variant="primary" :loading="busy === `activate:${p.slug}`" @click="act(p, 'activate')">{{
                        __('Activate')
                    }}</KButton>
                    <KButton v-else size="sm" :loading="busy === `deactivate:${p.slug}`" :disabled="p.required_by.length > 0" @click="act(p, 'deactivate')">{{
                        __('Deactivate')
                    }}</KButton>
                    <KButton
                        v-if="p.status === 'inactive'"
                        size="sm"
                        variant="ghost"
                        icon="trash"
                        :loading="busy === `delete:${p.slug}`"
                        :aria-label="__('Delete {plugin}', { plugin: p.name })"
                        @click="remove(p)"
                    />
                </div>
            </li>
        </ul>
    </template>

    <KModal
        v-model:open="adding"
        :title="review ? (update ? __('Update {plugin}?', { plugin: pkg.name }) : __('Install {plugin}?', { plugin: pkg.name })) : __('Upload a plugin')"
        size="lg"
        :dismissible="busy !== 'update'"
    >
        <form v-if="!review" id="k-plugin-upload" class="k-form" @submit.prevent="upload">
            <p class="k-muted k-small k-plugins__note">
                {{ __('Choose a plugin zip. Nothing is installed yet: you see who signed it and what it declares first.') }}
            </p>
            <KField v-slot="{ id }" :label="__('Plugin zip')" :error="uploadErrors.file ?? ''">
                <input :id="id" type="file" accept=".zip,application/zip" class="k-input" required @change="file = $event.target.files?.[0] ?? null" />
            </KField>
        </form>
        <div v-else class="k-review">
            <p class="k-review__head">
                <strong>{{ pkg.name }}</strong> <span class="k-num k-muted">{{ pkg.version }}</span>
                <template v-if="pkg.author"
                    ><span class="k-muted"> · {{ __('By {author}', { author: pkg.author }) }}</span></template
                >
            </p>
            <p v-if="pkg.description" class="k-review__desc">{{ pkg.description }}</p>
            <p class="k-review__sig" :class="`is-${signature.tone}`"><KIcon :name="signature.icon" /> {{ signature.text }}</p>
            <p v-if="update" class="k-muted k-small">
                {{
                    __(
                        'Updates version {from}. The database is backed up and the site is checked after the new files are in place; if anything is wrong, version {from} and the database are put back.',
                        { from: update.from },
                    )
                }}
            </p>
            <p v-else-if="pkg.installed" class="k-muted k-small">{{ __('Version {v} is installed (inactive).', { v: pkg.installed.version }) }}</p>

            <h3 class="k-review__h">{{ __('What it may do') }}</h3>
            <dl v-if="permissionRows(pkg.permissions, pkg.replaces).length" class="k-plugin__perms">
                <template v-for="row in permissionRows(pkg.permissions, pkg.replaces)" :key="row.label"
                    ><dt>{{ row.label }}</dt>
                    <dd>{{ row.value }}</dd></template
                >
            </dl>
            <p v-else class="k-muted k-small">{{ __('It declares nothing beyond its own pages, tables and settings.') }}</p>

            <template v-if="errors.length || warnings.length">
                <h3 class="k-review__h">{{ __('Code check') }}</h3>
                <ul class="k-review__findings">
                    <li v-for="f in [...errors, ...warnings]" :key="f.file + f.line + f.message" :class="`is-${f.level}`">
                        <KIcon :name="f.level === 'error' ? 'x-circle' : 'warning'" /> <code>{{ f.file }}{{ f.line ? `:${f.line}` : '' }}</code> {{ f.message }}
                    </li>
                </ul>
            </template>

            <KAlert v-if="failed" tone="danger" :title="__('The update was not applied')">
                <p v-for="problem in failed.problems" :key="problem" class="k-review__problem">{{ problem }}</p>
                <p v-if="failed.rolled_back">{{ __('Version {v} and the database were put back. The site runs as before.', { v: failed.from }) }}</p>
                <p v-for="problem in failed.rollback_problems" v-else :key="problem">{{ problem }}</p>
            </KAlert>
            <KAlert v-if="blockedReason" tone="warning">{{ blockedReason }}</KAlert>
            <KAlert v-else-if="update?.problems.length" tone="warning" :title="__('This update cannot be applied')">
                <p v-for="problem in update.problems" :key="problem" class="k-review__problem">{{ problem }}</p>
            </KAlert>
            <template v-else-if="!update">
                <KSwitch v-if="pkg.installed" v-model="replace" :label="__('Replace the installed version')" :disabled="replaceBlocked" />
                <KSwitch v-model="activate" :label="__('Activate it after installing')" />
            </template>
        </div>
        <template #footer>
            <KButton :disabled="busy === 'update'" @click="adding = false">{{ failed ? __('Close') : __('Cancel') }}</KButton>
            <KButton v-if="!review" type="submit" form="k-plugin-upload" variant="primary" icon="upload" :loading="busy === 'upload'" :disabled="!file">{{
                __('Upload and review')
            }}</KButton>
            <KButton
                v-else-if="update"
                variant="primary"
                icon="arrows-clockwise"
                :loading="busy === 'update'"
                :disabled="!review.allowed || errors.length > 0 || update.problems.length > 0 || !!failed"
                @click="runUpdate"
                >{{ __('Update to {v}', { v: pkg.version }) }}</KButton
            >
            <KButton
                v-else
                variant="primary"
                icon="check"
                :loading="busy === 'install'"
                :disabled="!review.allowed || errors.length > 0 || (pkg.installed && (!replace || replaceBlocked))"
                @click="install"
                >{{ __('Install') }}</KButton
            >
        </template>
    </KModal>
</template>

<style scoped>
.k-plugins {
    list-style: none;
    margin: 14px 0 0;
    padding: 0;
    border: 1px solid var(--k-rule);
    background: var(--k-sheet);
}
.k-plugin {
    display: flex;
    gap: 16px;
    align-items: flex-start;
    padding: 14px 16px;
}
.k-plugin + .k-plugin {
    border-top: 1px solid var(--k-rule);
}
.k-plugin__main {
    flex: 1;
    min-width: 0;
    display: grid;
    gap: 4px;
    justify-items: start;
}
.k-plugin__title {
    display: flex;
    align-items: baseline;
    gap: 8px;
    flex-wrap: wrap;
}
.k-plugin__title h2 {
    margin: 0;
    font-size: 15px;
    font-weight: 650;
}
.k-plugin__desc,
.k-plugin__meta {
    margin: 0;
}
.k-plugin__desc {
    font-size: 13px;
    color: var(--k-ink-2);
    max-width: 70ch;
}
.k-plugin__back {
    margin: 2px 0 0;
}
.k-plugin__problem {
    margin: 2px 0 0;
    font-size: 13px;
    color: var(--k-danger);
    display: flex;
    gap: 6px;
    align-items: baseline;
}
.k-plugin__perms {
    display: grid;
    grid-template-columns: max-content 1fr;
    gap: 4px 14px;
    margin: 4px 0 0;
    font-size: 13px;
}
.k-plugin__perms dt {
    color: var(--k-ink-3);
}
.k-plugin__perms dd {
    margin: 0;
    overflow-wrap: anywhere;
}
.k-plugin__actions {
    display: flex;
    gap: 6px;
    align-items: center;
    flex-shrink: 0;
}
.k-plugins__note {
    margin: -4px 0 0;
}
.k-review {
    display: grid;
    gap: 10px;
}
.k-review p {
    margin: 0;
}
.k-review__head {
    font-size: 15px;
}
.k-review__desc {
    color: var(--k-ink-2);
    font-size: 13px;
}
.k-review__sig {
    display: flex;
    gap: 8px;
    align-items: baseline;
    font-size: 13px;
    padding: 8px 10px;
    border: 1px solid var(--k-rule);
}
.k-review__sig.is-success {
    color: var(--k-success);
}
.k-review__sig.is-warning {
    color: var(--k-warning);
}
.k-review__sig.is-danger {
    color: var(--k-danger);
}
.k-review__h {
    margin: 6px 0 0;
    font-size: 13px;
    font-weight: 650;
}
.k-review__findings {
    list-style: none;
    margin: 0;
    padding: 0;
    display: grid;
    gap: 4px;
    font-size: 13px;
}
.k-review__findings .is-error {
    color: var(--k-danger);
}
.k-review__problem {
    overflow-wrap: anywhere;
}
@media (max-width: 640px) {
    .k-plugin {
        flex-direction: column;
    }
}
</style>
