<script setup>
import { computed, reactive, ref } from 'vue';
import { confirm, formatDateTime, notify, useApi, useResource, __ } from '@kestello/sdk';
import { KButton, KCard, KField, KInput, KSelect, KTime } from '@kestello/ui';

/**
 * Share links on the entry editor: a preview for someone without an
 * account. The link is shown once, when made; the list only says who it
 * was for and when it expires.
 */
const props = defineProps({ base: { type: String, required: true }, singular: { type: String, required: true } });
const api = useApi();
const links = useResource(() => `${props.base}/previews`);
const form = reactive({ label: '', days: 7 });
const made = ref(null); // { url, expires_at } just created
const busy = ref(false);
const DAYS = [
    { value: 1, label: __('1 day') },
    { value: 7, label: __('7 days') },
    { value: 14, label: __('14 days') },
    { value: 30, label: __('30 days') },
];
const absolute = computed(() => (made.value ? new URL(made.value.url, window.location.origin).toString() : ''));

async function create() {
    busy.value = true;
    try {
        const { data } = await api.post(`${props.base}/previews`, { kind: 'share', days: form.days, label: form.label.trim() || null });
        made.value = data;
        form.label = '';
        links.reload({ silent: true });
        copy();
    } catch (e) {
        notify.error(e);
    } finally {
        busy.value = false;
    }
}
function copy() {
    navigator.clipboard?.writeText(absolute.value).then(
        () => notify.success(__('Link copied.')),
        () => {},
    );
}
async function revoke(link) {
    const ok = await confirm({
        title: __('Revoke this link?'),
        message: __('Anyone who has it can no longer open the preview.'),
        confirmLabel: __('Revoke'),
        danger: true,
    });
    if (!ok) return;
    try {
        await api.delete(`${props.base}/previews/${link.id}`);
        links.reload({ silent: true });
        notify.success(__('Link revoked.'));
    } catch (e) {
        notify.error(e);
    }
}
// (an ICU plural's closing braces would end a {{ }} in the template)
const openedText = (n) => __('{n, plural, one {opened once} other {opened # times}}', { n });
</script>

<template>
    <KCard :title="__('Share a preview')">
        <div class="k-form">
            <p class="k-muted k-small">
                {{ __('A link that shows this {item} as it is saved, to anyone who has it, even before it is published.', { item: singular.toLowerCase() }) }}
            </p>
            <div v-if="made" class="k-share-made">
                <label class="k-sr-only" for="k-share-url">{{ __('Preview link') }}</label>
                <input id="k-share-url" class="k-input k-share-made__url" :value="absolute" readonly @focus="$event.target.select()" />
                <div class="k-share-made__row">
                    <KButton size="sm" icon="copy" @click="copy">{{ __('Copy') }}</KButton>
                    <span class="k-muted k-small">{{ __('Shown only now. Expires {when}.', { when: formatDateTime(made.expires_at) }) }}</span>
                </div>
            </div>
            <KField v-slot="{ id }" :label="__('Who is it for?')" :help="__('Optional, so you know which link to revoke later.')">
                <KInput :id="id" v-model="form.label" maxlength="100" />
            </KField>
            <KField v-slot="{ id }" :label="__('Works for')">
                <KSelect :id="id" v-model.number="form.days" :options="DAYS" />
            </KField>
            <KButton block :loading="busy" icon="link" @click="create">{{ __('Create link') }}</KButton>
        </div>
        <ul v-if="links.data.value?.length" class="k-share-list">
            <li v-for="link in links.data.value" :key="link.id" class="k-share-list__item">
                <div class="k-share-list__main">
                    <span>{{ link.label || __('Unnamed link') }}</span>
                    <span class="k-muted k-small">
                        {{ __('Expires') }} <KTime :value="link.expires_at" /> ·
                        {{ openedText(link.views) }}
                    </span>
                </div>
                <KButton
                    size="sm"
                    variant="ghost"
                    icon="x"
                    :aria-label="__('Revoke {name}', { name: link.label || __('Unnamed link') })"
                    @click="revoke(link)"
                    >{{ __('Revoke') }}</KButton
                >
            </li>
        </ul>
    </KCard>
</template>

<style scoped>
.k-share-made {
    display: grid;
    gap: 8px;
    padding: 12px;
    border: 1px solid var(--k-rule);
    border-radius: var(--k-radius);
}
.k-share-made__url {
    font-family: var(--k-mono);
    font-size: 12px;
}
.k-share-made__row {
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
}
.k-share-list {
    list-style: none;
    margin: 16px 0 0;
    padding: 0;
    border-top: 1px solid var(--k-rule);
}
.k-share-list__item {
    display: flex;
    gap: 8px;
    align-items: center;
    justify-content: space-between;
    padding: 10px 0;
    border-bottom: 1px solid var(--k-rule);
}
.k-share-list__item:last-child {
    border-bottom: 0;
}
.k-share-list__main {
    display: grid;
    gap: 2px;
    min-width: 0;
}
</style>
