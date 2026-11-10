<script setup>
import { computed, ref, watch } from 'vue';
import { notify, useApi, __ } from '@kestello/sdk';
import { KAlert, KButton, KIcon, KModal, KSwitch } from '@kestello/ui';
import { permissionRows } from './permissions.js';

/**
 * The step before a plugin is installed or updated, for an uploaded zip and for one downloaded
 * from the registry alike: who vouches for it, what it declares it may do, what the code check
 * found, and whether it may be installed here. Install and Update call the same endpoints either
 * way (POST /plugins/install, /plugins/update) with the review's token.
 *
 *   <PackageReviewModal v-model:open="reviewing" :review="review" :policy="data.policy" @done="data = $event" />
 *
 * Emits done with the plugins overview after an install or a successful update.
 */
const open = defineModel('open', { type: Boolean, default: false });
const props = defineProps({
    review: { type: Object, default: null }, // POST /plugins/upload or /plugins/registry/{slug}/review
    policy: { type: Object, default: () => ({ trusted: [] }) }, // GET /plugins policy
});
const emit = defineEmits(['done']);
const api = useApi();

const busy = ref('');
const replace = ref(false);
const activate = ref(true);
const failed = ref(null);
watch(
    () => props.review,
    (review) => {
        replace.value = !!review?.package.installed && !review.package.installed.active;
        activate.value = true;
        failed.value = null;
    },
    { immediate: true },
);

const pkg = computed(() => props.review?.package ?? null);
const update = computed(() => props.review?.update ?? null);
const rows = computed(() => (pkg.value ? permissionRows(pkg.value.permissions, pkg.value.replaces) : []));
const errors = computed(() => (pkg.value?.findings ?? []).filter((f) => f.level === 'error'));
const warnings = computed(() => (pkg.value?.findings ?? []).filter((f) => f.level !== 'error'));
const advisories = computed(() => pkg.value?.registry?.advisories ?? []);
const vouch = computed(() => {
    const p = pkg.value;
    if (!p) return null;
    if (p.registry?.status === 'vouched')
        return {
            icon: 'seal-check',
            tone: 'success',
            text: __('Published by {organisation} in the Kestello registry, which checked this exact zip and its signature.', {
                organisation: p.registry.organisation_name,
            }),
        };
    const s = p.signature;
    return {
        signed: { icon: 'seal-check', tone: 'success', text: __('Signed by {name}, a publisher this site trusts.', { name: s.name }) },
        untrusted: { icon: 'seal-question', tone: 'warning', text: __('Signed with a key this site does not trust ({key}).', { key: s.key }) },
        unsigned: { icon: 'seal-question', tone: 'warning', text: __('Not signed: nobody vouches for where it came from.') },
        tampered: { icon: 'seal-warning', tone: 'danger', text: __('Changed after it was signed. Do not install it.') },
    }[s.status];
});
const blockedReason = computed(() => {
    if (!props.review || props.review.allowed) return '';
    if (pkg.value.registry?.status === 'refused') return pkg.value.registry.reason;
    if (pkg.value.signature.status === 'tampered') return __('This package was changed after it was signed, so it is refused.');
    return props.policy.trusted.length
        ? __('Only packages signed by {publishers} install from here. A developer can install this one on the server: bin/cms plugin:install <zip>.', {
              publishers: props.policy.trusted.join(', '),
          })
        : __(
              'No publisher is trusted on this site yet, so plugins install on the server: bin/cms plugin:install <zip>. Trusted publishers are set in config/app.php (packages.trusted_keys).',
          );
});
const replaceBlocked = computed(() => !!pkg.value?.installed?.active);
const title = computed(() =>
    pkg.value ? (update.value ? __('Update {plugin}?', { plugin: pkg.value.name }) : __('Install {plugin}?', { plugin: pkg.value.name })) : '',
);

async function runUpdate() {
    busy.value = 'update';
    failed.value = null;
    try {
        const response = (await api.post('/plugins/update', { token: props.review.token })).data;
        emit('done', response);
        const r = response.result;
        if (r.ok) {
            open.value = false;
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
        const result = (await api.post('/plugins/install', { token: props.review.token, replace: replace.value, activate: activate.value })).data;
        emit('done', result);
        open.value = false;
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
    <KModal v-if="pkg" v-model:open="open" :title="title" size="lg" :dismissible="busy !== 'update'">
        <div class="k-review">
            <p class="k-review__head">
                <strong>{{ pkg.name }}</strong> <span class="k-num k-muted">{{ pkg.version }}</span>
                <template v-if="pkg.author"
                    ><span class="k-muted"> · {{ __('By {author}', { author: pkg.author }) }}</span></template
                >
            </p>
            <p v-if="pkg.description" class="k-review__desc">{{ pkg.description }}</p>
            <p class="k-review__sig" :class="`is-${vouch.tone}`"><KIcon :name="vouch.icon" /> {{ vouch.text }}</p>
            <KAlert v-if="advisories.length" tone="danger" :title="__('Security advisories for this version')">
                <p v-for="a in advisories" :key="a.id" class="k-review__problem">
                    <strong>{{ a.severity }}</strong> · {{ a.title }}
                    <a v-if="a.url" :href="a.url" target="_blank" rel="noopener noreferrer">{{ a.id }}</a>
                </p>
            </KAlert>
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
            <dl v-if="rows.length" class="k-perms">
                <template v-for="row in rows" :key="row.label"
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
            <KButton :disabled="busy === 'update'" @click="open = false">{{ failed ? __('Close') : __('Cancel') }}</KButton>
            <KButton
                v-if="update"
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
.k-perms {
    display: grid;
    grid-template-columns: max-content 1fr;
    gap: 4px 14px;
    margin: 4px 0 0;
    font-size: 13px;
}
.k-perms dt {
    color: var(--k-ink-3);
}
.k-perms dd {
    margin: 0;
    overflow-wrap: anywhere;
}
</style>
