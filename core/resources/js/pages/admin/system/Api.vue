<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { usePage } from '@kestello/app';
import { ApiError, confirm, notify, useApi, __ } from '@kestello/sdk';
import { KAlert, KBadge, KButton, KCard, KEmpty, KField, KInput, KPageHeader, KSpinner } from '@kestello/ui';

defineOptions({ layout: 'Admin' });

/**
 * The API explorer: every endpoint the signed-in person may call (the
 * OpenAPI document is filtered by their role), with a form to try one and
 * the matching TypeScript client and curl calls.
 */
const page = usePage();
const api = useApi();
const base = computed(() => page.props.admin?.api ?? '/api/v1');
const doc = ref(null);
const loading = ref(true);
const search = ref('');
const selected = ref(null);

api.get('/openapi.json')
    .then(
        (d) => {
            doc.value = d;
        },
        (e) => notify.error(e),
    )
    .finally(() => {
        loading.value = false;
    });

const operations = computed(() => {
    const out = [];
    for (const [path, methods] of Object.entries(doc.value?.paths ?? {})) {
        for (const [method, op] of Object.entries(methods)) {
            out.push({ ...op, method: method.toUpperCase(), path, key: `${method} ${path}`, tag: op.tags?.[0] ?? 'api' });
        }
    }
    return out;
});
const groups = computed(() => {
    const q = search.value.trim().toLowerCase();
    const map = new Map();
    for (const op of operations.value) {
        if (q && !`${op.method} ${op.path} ${op.summary ?? ''} ${op.operationId}`.toLowerCase().includes(q)) continue;
        if (!map.has(op.tag)) map.set(op.tag, []);
        map.get(op.tag).push(op);
    }
    return [...map.entries()].sort((a, b) => a[0].localeCompare(b[0]));
});
const shown = computed(() => groups.value.reduce((n, [, ops]) => n + ops.length, 0));

// ── the selected operation ───────────────────────────────────────────
const values = reactive({ path: {}, query: {} });
const bodyText = ref('');
const result = ref(null);
const sending = ref(false);

const pathParams = computed(() => (selected.value?.parameters ?? []).filter((p) => p.in === 'path'));
const queryParams = computed(() => (selected.value?.parameters ?? []).filter((p) => p.in === 'query'));
const bodySchema = computed(() => selected.value?.requestBody?.content?.['application/json']?.schema ?? null);

function pick(op) {
    selected.value = op;
    values.path = Object.fromEntries((op.parameters ?? []).filter((p) => p.in === 'path').map((p) => [p.name, '']));
    values.query = Object.fromEntries(
        (op.parameters ?? []).filter((p) => p.in === 'query').map((p) => [p.name, p.schema?.default !== undefined ? String(p.schema.default) : '']),
    );
    const schema = op.requestBody?.content?.['application/json']?.schema;
    bodyText.value = schema ? JSON.stringify(example(schema), null, 2) : '';
    result.value = null;
}

/** A starting value for a JSON schema, so the body has the right keys. */
function example(s, depth = 0) {
    if (!s || depth > 4) return null;
    if (s.default !== undefined) return s.default;
    if (s.enum) return s.enum[0];
    switch (Array.isArray(s.type) ? s.type[0] : s.type) {
        case 'object':
            return Object.fromEntries(Object.entries(s.properties ?? {}).map(([k, v]) => [k, example(v, depth + 1)]));
        case 'array':
            return [];
        case 'integer':
        case 'number':
            return s.minimum ?? 0;
        case 'boolean':
            return false;
        case 'string':
            return '';
        default:
            return null;
    }
}

const target = computed(() => {
    if (!selected.value) return '';
    const path = selected.value.path.replace(/\{([^}]+)\}/g, (_, name) => (values.path[name] ? encodeURIComponent(values.path[name]) : `{${name}}`));
    const params = new URLSearchParams();
    for (const p of queryParams.value) {
        const v = String(values.query[p.name] ?? '').trim();
        if (v === '') continue;
        if (p.schema?.type === 'array')
            v.split(',')
                .map((x) => x.trim())
                .filter(Boolean)
                .forEach((x) => params.append(`${p.name}[]`, x));
        else params.append(p.name, v);
    }
    const qs = params.toString();
    return path + (qs ? `?${qs}` : '');
});
const missing = computed(() => pathParams.value.some((p) => !String(values.path[p.name] ?? '').trim()));
const unsafe = computed(() => selected.value && selected.value.method !== 'GET');

async function send() {
    let body;
    if (bodySchema.value) {
        try {
            body = bodyText.value.trim() ? JSON.parse(bodyText.value) : undefined;
        } catch {
            notify.error(__('The body is not valid JSON.'));
            return;
        }
    }
    if (
        unsafe.value &&
        !(await confirm({
            title: __('Send {method} {path}?', { method: selected.value.method, path: target.value }),
            message: __('This runs on your site with your permissions and can change or delete content.'),
            confirmLabel: __('Send'),
            danger: selected.value.method === 'DELETE',
        }))
    )
        return;

    sending.value = true;
    const started = performance.now();
    try {
        const data = await api.request(selected.value.method, target.value, { body });
        result.value = { ok: true, status: data === null ? 204 : 200, ms: Math.round(performance.now() - started), body: data };
    } catch (e) {
        if (!(e instanceof ApiError)) {
            notify.error(e);
            return;
        }
        result.value = { ok: false, status: e.status, ms: Math.round(performance.now() - started), body: e.problem };
    } finally {
        sending.value = false;
    }
}

// ── code to copy ─────────────────────────────────────────────────────
const tsCall = computed(() => {
    const op = selected.value;
    if (!op) return '';
    const parts = pathParams.value.map((p) => `${p.name}: ${JSON.stringify(values.path[p.name] || (p.schema?.type === 'integer' ? 1 : p.name))}`);
    const q = queryParams.value.filter((p) => String(values.query[p.name] ?? '').trim() !== '');
    if (q.length) parts.push(`query: { ${q.map((p) => `${p.name}: ${JSON.stringify(typed(p.schema, values.query[p.name]))}`).join(', ')} }`);
    if (bodySchema.value) parts.push(`body: ${(bodyText.value.trim() || '{}').replace(/\n/g, '\n    ')}`);
    return `const { data } = await kestello.${op.operationId}(${parts.length ? `{ ${parts.join(', ')} }` : ''});`;
});
const curl = computed(() => {
    const op = selected.value;
    if (!op) return '';
    const url = `${window.location.origin}${base.value.replace(/\/$/, '')}${target.value}`;
    const lines = [
        `curl -X ${op.method} '${url}'`,
        `  -H "Authorization: Bearer $KESTELLO_TOKEN"` /* double quotes: the shell fills in the variable */,
        `  -H 'Accept: application/json'`,
    ];
    if (bodySchema.value)
        lines.push(`  -H 'Content-Type: application/json'`, `  -d '${(bodyText.value.trim() || '{}').replace(/'/g, "'\\''").replace(/\s*\n\s*/g, ' ')}'`);
    return lines.join(' \\\n');
});
/** A form value as the type its schema wants, for the TypeScript snippet. */
function typed(schema, value) {
    const v = String(value).trim();
    if (schema?.type === 'integer' || schema?.type === 'number') return Number.isNaN(Number(v)) ? v : Number(v);
    if (schema?.type === 'boolean') return v === 'true' || v === '1';
    if (schema?.type === 'array')
        return v
            .split(',')
            .map((x) => x.trim())
            .filter(Boolean);
    return v;
}
function copy(text) {
    navigator.clipboard?.writeText(text).then(
        () => notify.success(__('Copied.')),
        () => {},
    );
}
watch(search, () => {
    if (selected.value && !groups.value.some(([, ops]) => ops.includes(selected.value))) selected.value = null;
});
// (an ICU plural's closing braces would end a {{ }} in the template)
const endpointsText = (n) => __('{n, plural, one {# endpoint} other {# endpoints}}', { n });
</script>

<template>
    <KPageHeader
        :title="__('API')"
        :description="
            __('Every endpoint you may call with your role, to read and try. Programs use the same endpoints with an access token from your profile.')
        "
    >
        <template #actions>
            <a class="k-btn" :href="`${base}/openapi.ts`" download="kestello.ts">{{ __('Download TypeScript client') }}</a>
            <a class="k-btn k-btn--ghost" :href="`${base}/openapi.json`" target="_blank" rel="noopener">{{ __('OpenAPI document') }}</a>
        </template>
    </KPageHeader>

    <div v-if="loading" class="k-api__loading"><KSpinner /></div>
    <div v-else class="k-api">
        <KCard flush class="k-api__index">
            <div class="k-api__search">
                <label class="k-field__label" for="k-api-search">{{ __('Find an endpoint') }}</label>
                <KInput id="k-api-search" v-model="search" type="search" autocomplete="off" />
                <p class="k-muted k-small">{{ endpointsText(shown) }}</p>
            </div>
            <nav :aria-label="__('Endpoints')" class="k-api__list">
                <section v-for="[tag, ops] in groups" :key="tag">
                    <h2 class="k-api__tag">{{ tag }}</h2>
                    <button
                        v-for="op in ops"
                        :key="op.key"
                        type="button"
                        class="k-api__op"
                        :aria-current="selected === op ? 'true' : undefined"
                        @click="pick(op)"
                    >
                        <span class="k-api__method" :data-method="op.method">{{ op.method }}</span>
                        <span class="k-api__op-text">
                            <span class="k-api__path">{{ op.path }}</span>
                            <span v-if="op.summary" class="k-muted k-small">{{ op.summary }}</span>
                        </span>
                    </button>
                </section>
                <p v-if="!shown" class="k-muted k-small k-api__none">{{ __('No endpoint matches.') }}</p>
            </nav>
        </KCard>

        <div v-if="!selected" class="k-api__detail">
            <KEmpty icon="plugs-connected" :title="__('Pick an endpoint')">
                {{ __('Its parameters, a form to try it and the code to call it appear here.') }}
            </KEmpty>
        </div>
        <div v-else class="k-api__detail">
            <KCard>
                <template #title
                    ><span class="k-api__method" :data-method="selected.method">{{ selected.method }}</span>
                    <code class="k-api__title-path">{{ selected.path }}</code></template
                >
                <p v-if="selected.summary" class="k-api__summary">{{ selected.summary }}</p>
                <ul class="k-api__facts k-small">
                    <li v-if="selected['x-kestello-capability']">
                        {{ __('Needs the permission') }} <code>{{ selected['x-kestello-capability'] }}</code
                        ><template v-if="selected.security?.[1]?.bearer?.length"
                            >; {{ __('tokens need the scope') }} <code v-for="s in selected.security[1].bearer" :key="s">{{ s }}</code></template
                        >
                    </li>
                    <li v-else>{{ __('Public: anyone can call it.') }}</li>
                    <li v-if="selected['x-kestello-recent-auth']">{{ __('Asks for your password again if you have not entered it recently.') }}</li>
                    <li v-if="selected['x-kestello-owner']">{{ __('Added by {owner}.', { owner: selected['x-kestello-owner'] }) }}</li>
                </ul>

                <form class="k-form" @submit.prevent="send">
                    <KField v-for="p in pathParams" :key="`p-${p.name}`" v-slot="{ id }" :label="p.name" required>
                        <KInput :id="id" v-model="values.path[p.name]" :inputmode="p.schema?.type === 'integer' ? 'numeric' : undefined" autocomplete="off" />
                    </KField>
                    <KField
                        v-for="p in queryParams"
                        :key="`q-${p.name}`"
                        v-slot="{ id }"
                        :label="p.name"
                        :required="p.required && p.schema?.default === undefined"
                        :help="
                            [
                                p.schema?.description,
                                p.schema?.enum ? __('One of: {values}', { values: p.schema.enum.join(', ') }) : '',
                                p.schema?.type === 'array' ? __('Separate several with commas.') : '',
                            ]
                                .filter(Boolean)
                                .join(' ')
                        "
                    >
                        <KInput :id="id" v-model="values.query[p.name]" autocomplete="off" />
                    </KField>
                    <KField v-if="bodySchema" v-slot="{ id }" :label="__('Body (JSON)')">
                        <textarea :id="id" v-model="bodyText" class="k-input k-textarea k-api__body" rows="8" spellcheck="false"></textarea>
                    </KField>
                    <div class="k-api__send">
                        <code class="k-api__target k-truncate">{{ selected.method }} {{ target }}</code>
                        <KButton type="submit" :variant="unsafe ? 'danger' : 'primary'" :loading="sending" :disabled="missing">{{ __('Send') }}</KButton>
                    </div>
                </form>
            </KCard>

            <KCard v-if="result" :title="__('Answer')">
                <template #actions
                    ><KBadge :tone="result.ok ? 'success' : 'danger'" dot>{{ result.status }}</KBadge>
                    <span class="k-muted k-small">{{ result.ms }} ms</span></template
                >
                <KAlert v-if="!result.ok && result.body?.errors" tone="danger" :title="result.body.detail ?? result.body.title">
                    <ul class="k-api__errors">
                        <li v-for="(messages, field) in result.body.errors" :key="field">
                            <code>{{ field }}</code
                            >: {{ messages.join(' ') }}
                        </li>
                    </ul>
                </KAlert>
                <pre class="k-pre k-api__pre">{{ result.body === null ? __('(no content)') : JSON.stringify(result.body, null, 2) }}</pre>
            </KCard>

            <KCard :title="__('Call it from code')">
                <h3 class="k-api__h">{{ __('TypeScript client') }}</h3>
                <div class="k-api__code">
                    <pre class="k-pre">{{ tsCall }}</pre>
                    <KButton size="sm" variant="ghost" icon="clipboard" :aria-label="__('Copy')" @click="copy(tsCall)" />
                </div>
                <h3 class="k-api__h">curl</h3>
                <div class="k-api__code">
                    <pre class="k-pre">{{ curl }}</pre>
                    <KButton size="sm" variant="ghost" icon="clipboard" :aria-label="__('Copy')" @click="copy(curl)" />
                </div>
                <p class="k-muted k-small">{{ __('Create the access token under Your profile, with the scopes listed above.') }}</p>
            </KCard>
        </div>
    </div>
</template>

<style scoped>
.k-api__loading {
    display: grid;
    place-items: center;
    padding: 48px;
}
.k-api {
    display: grid;
    grid-template-columns: minmax(260px, 340px) minmax(0, 1fr);
    gap: 16px;
    align-items: start;
}
@media (max-width: 900px) {
    .k-api {
        grid-template-columns: 1fr;
    }
}
.k-api__index {
    position: sticky;
    top: 16px;
}
@media (max-width: 900px) {
    .k-api__index {
        position: static;
    }
}
.k-api__search {
    padding: 12px;
    border-bottom: 1px solid var(--k-rule);
    display: grid;
    gap: 6px;
}
.k-api__search p {
    margin: 0;
}
.k-api__list {
    max-height: calc(100vh - 220px);
    overflow: auto;
    padding: 4px 0 8px;
}
@media (max-width: 900px) {
    .k-api__list {
        max-height: 360px;
    }
}
.k-api__tag {
    margin: 12px 12px 4px;
    font-size: 12px;
    font-weight: 600;
    color: var(--k-ink-2);
}
.k-api__op {
    display: flex;
    gap: 8px;
    align-items: flex-start;
    width: 100%;
    padding: 6px 12px;
    border: 0;
    background: none;
    text-align: left;
    font: inherit;
    color: inherit;
    cursor: pointer;
}
.k-api__op:hover {
    background: var(--k-sheet-2);
}
.k-api__op:focus-visible {
    outline: 2px solid var(--k-mark);
    outline-offset: -2px;
}
.k-api__op[aria-current='true'] {
    background: var(--k-sheet-2);
    box-shadow: inset 1px 0 0 var(--k-ink);
}
.k-api__op-text {
    display: grid;
    min-width: 0;
}
.k-api__path {
    font-family: var(--k-mono);
    font-size: 12.5px;
    overflow-wrap: anywhere;
}
.k-api__method {
    flex: none;
    display: inline-block;
    min-width: 48px;
    font-family: var(--k-mono);
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.02em;
    color: var(--k-ink-2);
    padding-top: 2px;
}
.k-api__method[data-method='POST'] {
    color: var(--k-info);
}
.k-api__method[data-method='PUT'],
.k-api__method[data-method='PATCH'] {
    color: var(--k-warning);
}
.k-api__method[data-method='DELETE'] {
    color: var(--k-danger);
}
.k-api__none {
    padding: 0 12px;
}
.k-api__detail {
    display: grid;
    gap: 16px;
    min-width: 0;
}
.k-api__title-path {
    font-family: var(--k-mono);
    font-size: 14px;
    overflow-wrap: anywhere;
}
.k-api__summary {
    margin: 0 0 8px;
}
.k-api__facts {
    margin: 0 0 16px;
    padding-left: 18px;
    color: var(--k-ink-2);
}
.k-api__facts code {
    margin-left: 4px;
}
.k-api__body {
    font-family: var(--k-mono);
    font-size: 12.5px;
    min-height: 140px;
}
.k-api__send {
    display: flex;
    gap: 12px;
    align-items: center;
    justify-content: space-between;
    padding-top: 4px;
}
.k-api__target {
    min-width: 0;
    font-family: var(--k-mono);
    font-size: 12px;
    color: var(--k-ink-2);
}
.k-api__pre {
    max-height: 420px;
}
.k-api__errors {
    margin: 0;
    padding-left: 18px;
}
.k-api__h {
    margin: 0 0 6px;
    font-size: 13px;
    font-weight: 600;
}
.k-api__code {
    position: relative;
    margin-bottom: 14px;
}
.k-api__code .k-pre {
    padding-right: 44px;
}
.k-api__code > :deep(.k-btn) {
    position: absolute;
    top: 6px;
    right: 6px;
}
</style>
