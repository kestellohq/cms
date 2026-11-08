<script setup>
import { computed, nextTick, onMounted, reactive, ref, useId } from 'vue';
import { usePage } from '@kestello/app';
import { visibleFields } from '@kestello/sdk';
import FormField from '../../shared/FormField.vue';
import { fill } from '../../shared/file-hint.js';

/**
 * core/form: one of the site's forms. Pages are cached, so the form has
 * no session: it fetches a short-lived token when someone starts filling
 * it in, and the server checks that, a honeypot, the visitor's rate and
 * (when switched on) a captcha. Fields hidden by their conditions are not
 * rendered, so they are never sent.
 */
const props = defineProps({
    form: { type: [String, Number], default: '' },
    definition: { type: Object, default: null },
});

const uid = useId();
const el = ref(null);
const values = reactive({});
const errors = ref({});
const state = ref('idle'); // idle | sending | sent
const message = ref('');
const formError = ref('');
let token = null;
let tokenRequest = null;

const def = computed(() => props.definition);
// on a public demo everyone shares one account, so what a visitor sends here is readable by the next one
const page = usePage();
const demo = computed(() => !!page.props?.demo);
const fields = computed(() => def.value?.fields ?? []);
for (const f of props.definition?.fields ?? []) {
    values[f.name] = f.type === 'multi_select' ? [] : f.type === 'boolean' ? f.default === true : (f.default ?? '');
}
const shown = computed(() => visibleFields(fields.value, values));
const visible = computed(() => fields.value.filter((f) => shown.value[f.name] !== false));
const id = (f) => `k-b-form-${uid}-${f.name}`;

function fetchToken() {
    if (token || tokenRequest || !def.value?.open) return tokenRequest;
    tokenRequest = fetch(`/api/v1/forms/${def.value.id}/token`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
        .then((r) => (r.ok ? r.json() : null))
        .then((body) => {
            token = body?.data?.token ?? null;
        })
        .catch(() => {})
        .finally(() => {
            tokenRequest = null;
        });
    return tokenRequest;
}

onMounted(() => {
    const c = def.value?.captcha;
    if (c?.script && !document.querySelector(`script[src="${c.script}"]`)) {
        document.head.appendChild(Object.assign(document.createElement('script'), { src: c.script, async: true, defer: true }));
    }
});

// the block's own words, translated by the server (English when an older definition has none)
const ENGLISH = {
    sending: 'Sending…',
    honeypot: 'Leave this empty',
    choose: 'Choose…',
    check: 'Please check the form and try again.',
    too_many: 'Too many tries from here. Please wait a while and send it again.',
    failed: 'Something went wrong. Please reload the page and try again.',
    offline: 'The form could not be sent. Check your connection and try again.',
    file_one: 'Up to {size} MB.',
    file_many: 'Up to {count} files, {size} MB each.',
};
const text = (key) => def.value?.texts?.[key] ?? ENGLISH[key];
const fileWords = { one: (p) => fill(text('file_one'), p), many: (p) => fill(text('file_many'), p) };

async function submit() {
    if (state.value === 'sending') return;
    formError.value = '';
    errors.value = {};
    state.value = 'sending';
    try {
        if (!token) await fetchToken();
        const data = new FormData(el.value);
        data.set('_token', token ?? '');
        const response = await fetch(`/api/v1/forms/${def.value.id}/submissions`, {
            method: 'POST',
            body: data,
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        const body = await response.json().catch(() => ({}));
        if (response.ok) {
            if (body.data?.redirect) {
                window.location.assign(body.data.redirect);
                return;
            }
            message.value = body.data?.message ?? '';
            state.value = 'sent';
            await nextTick();
            el.value?.parentElement?.querySelector('.k-b-form__done')?.focus();
            return;
        }
        if (response.status === 422) {
            const all = Object.fromEntries(Object.entries(body.errors ?? {}).map(([k, v]) => [k, Array.isArray(v) ? v[0] : String(v)]));
            formError.value = all._form ?? '';
            if (all._token) token = null; // expired: fetch a new one (a token that was too quick becomes fine by itself)
            delete all._form;
            delete all._token;
            errors.value = all;
            await nextTick();
            const first = visible.value.find((f) => errors.value[f.name]);
            if (first) document.getElementById(id(first))?.focus();
            else if (!Object.keys(all).length && !formError.value) formError.value = text('check');
        } else if (response.status === 429) {
            formError.value = text('too_many');
        } else if (response.status === 409) {
            formError.value = body.detail ?? def.value.closed_message;
        } else {
            token = null;
            formError.value = text('failed');
        }
        state.value = 'idle';
    } catch {
        formError.value = text('offline');
        state.value = 'idle';
    }
}
</script>

<template>
    <div v-if="def" class="k-b-form">
        <p v-if="!def.open" class="k-b-form__closed">{{ def.closed_message }}</p>
        <div v-else-if="state === 'sent'" class="k-b-form__done" tabindex="-1" role="status">{{ message }}</div>
        <form v-else ref="el" class="k-b-form__form" novalidate :aria-label="def.title" @focusin.once="fetchToken" @submit.prevent="submit">
            <p v-if="formError" class="k-b-form__alert" role="alert">{{ formError }}</p>

            <!-- people never see this; bots that fill every field do -->
            <div class="k-b-form__hp" aria-hidden="true">
                <label :for="`k-b-form-${uid}-hp`">{{ text('honeypot') }}</label>
                <input :id="`k-b-form-${uid}-hp`" type="text" name="_hp" tabindex="-1" autocomplete="off" />
            </div>

            <div class="k-b-form__grid">
                <div v-for="f in visible" :key="f.name" class="k-b-form__field" :class="[`k-b-form__field--${f.width}`, { 'has-error': errors[f.name] }]">
                    <FormField
                        v-model="values[f.name]"
                        :field="f"
                        :input-id="id(f)"
                        :error="errors[f.name]"
                        :choose-text="text('choose')"
                        :file-words="fileWords"
                    />
                </div>
            </div>

            <div v-if="def.captcha" class="k-b-form__captcha" :data-sitekey="def.captcha.site_key"></div>

            <button type="submit" class="k-b-form__submit" :disabled="state === 'sending'" :aria-busy="state === 'sending' ? 'true' : undefined">
                {{ state === 'sending' ? text('sending') : def.submit_label }}
            </button>
            <p v-if="demo" class="k-b-form__demo">
                This is a demo: anyone who signs in to it can read what you send here, until it resets within the hour. Use made-up details.
            </p>
        </form>
    </div>
</template>

<style scoped>
.k-b-form__demo {
    margin: 0.75rem 0 0;
    font-size: 0.875em;
    opacity: 0.8;
}
.k-b-form__alert {
    margin: 0 0 1.1rem;
    padding: 0.75rem 1rem;
    border: 1px solid var(--block-danger, #b42318);
    border-radius: var(--block-radius, 6px);
    color: var(--block-danger, #b42318);
    font-weight: 600;
}
.k-b-form__captcha {
    margin-top: 1.1rem;
}
.k-b-form__submit {
    margin-top: 1.4rem;
    min-height: 2.75rem;
    padding: 0.55rem 1.4rem;
    border: 0;
    border-radius: var(--block-button-radius, 999px);
    background: var(--block-accent, #2448c9);
    color: var(--block-accent-ink, #fff);
    font: inherit;
    font-weight: 700;
    cursor: pointer;
}
.k-b-form__submit:hover:not(:disabled) {
    background: var(--block-accent-deep, #1a379e);
}
.k-b-form__submit:focus-visible {
    outline: 3px solid var(--block-accent, #2448c9);
    outline-offset: 3px;
}
.k-b-form__submit:disabled {
    opacity: 0.7;
    cursor: progress;
}
.k-b-form__done {
    padding: 1rem 1.2rem;
    border: 1px solid color-mix(in srgb, currentColor 25%, transparent);
    border-radius: var(--block-radius, 6px);
    font-weight: 600;
}
.k-b-form__done:focus {
    outline: none;
}
.k-b-form__closed {
    margin: 0;
    opacity: 0.8;
}
.k-b-form__hp {
    position: absolute;
    left: -10000px;
    width: 1px;
    height: 1px;
    overflow: hidden;
}
</style>
