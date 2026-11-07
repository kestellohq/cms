<script setup>
import { notify, __ } from '@kestello/sdk';
import { KButton, KModal } from '@kestello/ui';

/** Shows a webhook secret once, after it is made or rotated. */
const props = defineProps({ secret: { type: String, default: null }, rotated: Boolean });
const emit = defineEmits(['done']);

function copy() {
    navigator.clipboard?.writeText(props.secret).then(
        () => notify.success(__('Copied.')),
        () => {},
    );
}
</script>

<template>
    <KModal :open="!!secret" :title="__('Copy the signing secret now')" :dismissible="false" @close="emit('done')">
        <template v-if="secret">
            <p>{{ __('Give it to the receiving service so it can check that messages come from this site. This is the only time it is shown.') }}</p>
            <div class="k-secret k-secret--block">
                <code>{{ secret }}</code>
            </div>
            <p v-if="rotated" class="k-muted k-small">
                {{ __('For the next 24 hours, messages are signed with the old secret too, so nothing is missed while you switch.') }}
            </p>
        </template>
        <template #footer>
            <KButton icon="clipboard" @click="copy">{{ __('Copy') }}</KButton>
            <KButton variant="primary" @click="emit('done')">{{ __('I have stored it') }}</KButton>
        </template>
    </KModal>
</template>
