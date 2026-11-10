<script setup>
import { computed, ref } from 'vue';
import { ApiError, confirm, formatDateTime, notify, useApi, useCan, __ } from '@kestello/sdk';
import { KAlert, KBadge, KButton, KEmpty, KField, KFileInput, KIcon, KModal, KPageHeader } from '@kestello/ui';
import PackageReviewModal from '../../../components/packages/PackageReviewModal.vue';
import { permissionRows } from '../../../components/packages/permissions.js';
import StoppedUpdate from '../../../components/updates/StoppedUpdate.vue';
import { rememberSwitchBack, switchBackOutcome, takeSwitchBack } from '../../../components/updates/switch-back.js';

defineOptions({ layout: 'Admin' });

/**
 * Plugins: activate, deactivate, delete, and install from a zip. An upload
 * is reviewed first (who signed it, what it declares it will do); only
 * packages signed by a publisher the server trusts, or vouched for by the
 * registry, install from here. Add new (AddPlugins) browses the registry.
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

// ── upload, then the review shared with Add new ─────────────────────
const adding = ref(false);
const file = ref(null);
const uploadErrors = ref({});
const review = ref(null);
const reviewing = ref(false);

function openAdd() {
    adding.value = true;
    file.value = null;
    uploadErrors.value = {};
}

async function upload() {
    if (!file.value) return;
    busy.value = 'upload';
    uploadErrors.value = {};
    const form = new FormData();
    form.append('file', file.value);
    try {
        review.value = (await api.upload('/plugins/upload', form)).data;
        adding.value = false;
        reviewing.value = true;
    } catch (e) {
        if (e instanceof ApiError && e.status === 422) uploadErrors.value = e.fieldErrors();
        else notify.error(e);
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
            <KButton icon="upload" @click="openAdd">{{ __('Upload a plugin') }}</KButton>
            <KButton variant="primary" icon="plus" href="/admin/system/plugins/add">{{ __('Add new') }}</KButton>
        </template>
    </KPageHeader>
    <KAlert v-if="loadError" tone="danger">{{ loadError.message }}</KAlert>
    <KAlert v-if="data?.recovery" tone="warning" :title="__('Recovery mode')">{{
        __('No plugins are running in this session, so you can deactivate or delete the one causing trouble.')
    }}</KAlert>
    <KAlert v-for="msg in data?.invalid ?? []" :key="msg" tone="warning">{{ msg }}</KAlert>
    <StoppedUpdate v-if="data?.stopped_update" :stopped="data.stopped_update" :can-retry="can('core.update')" />

    <template v-if="data">
        <KEmpty v-if="!data.plugins.length" icon="plug" :title="__('No plugins installed')">{{ __('Find one under Add new, or upload a plugin zip.') }}</KEmpty>
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

    <KModal v-model:open="adding" :title="__('Upload a plugin')" size="lg">
        <form id="k-plugin-upload" class="k-form" @submit.prevent="upload">
            <p class="k-muted k-small k-plugins__note">
                {{ __('Choose a plugin zip. Nothing is installed yet: you see who signed it and what it declares first.') }}
            </p>
            <KField v-slot="{ id, describedBy }" :label="__('Plugin zip')" :error="uploadErrors.file ?? ''">
                <KFileInput :id="id" v-model="file" accept=".zip,application/zip" :described-by="describedBy" required />
            </KField>
        </form>
        <template #footer>
            <KButton @click="adding = false">{{ __('Cancel') }}</KButton>
            <KButton type="submit" form="k-plugin-upload" variant="primary" icon="upload" :loading="busy === 'upload'" :disabled="!file">{{
                __('Upload and review')
            }}</KButton>
        </template>
    </KModal>
    <PackageReviewModal v-model:open="reviewing" :review="review" :policy="data?.policy" @done="data = $event" />
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
@media (max-width: 640px) {
    .k-plugin {
        flex-direction: column;
    }
}
</style>
