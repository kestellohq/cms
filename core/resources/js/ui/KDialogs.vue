<script setup>
import { computed, ref, watch } from 'vue';
import { ApiError, dialogs, useApi, __ } from '@kestello/sdk';
import KButton from './KButton.vue';
import KField from './KField.vue';
import KInput from './KInput.vue';
import KModal from './KModal.vue';

/** Hosts confirm() and the password prompt from @kestello/sdk (one at a time). */
const api = useApi();
const current = computed(() => dialogs[0] ?? null);
const open = ref(false);
const password = ref('');
const error = ref('');
const busy = ref(false);

watch(
    current,
    (dialog) => {
        open.value = !!dialog;
        password.value = '';
        error.value = '';
    },
    { immediate: true },
);

function finish(result) {
    const dialog = dialogs.shift();
    dialog?.resolve(result);
}

async function submitPassword() {
    busy.value = true;
    error.value = '';
    try {
        await api.post('/auth/confirm', { password: password.value });
        finish(true);
    } catch (e) {
        error.value = e instanceof ApiError && e.status === 401 ? __('That password is not correct.') : e.message;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <KModal v-if="current" :open="open" :title="current.title" size="sm" @close="finish(false)">
        <p v-if="current.kind === 'confirm'" class="k-dialog__message">{{ current.message }}</p>
        <form v-else id="k-reauth" class="k-form" @submit.prevent="submitPassword">
            <p class="k-dialog__message">{{ __('This action changes sensitive settings. Enter your password to continue.') }}</p>
            <KField v-slot="{ id, describedBy, invalid }" :label="__('Password')" :error="error">
                <KInput
                    :id="id"
                    v-model="password"
                    type="password"
                    autocomplete="current-password"
                    autofocus
                    :invalid="invalid"
                    :aria-describedby="describedBy"
                />
            </KField>
        </form>
        <template #footer>
            <KButton @click="finish(false)">{{ __('Cancel') }}</KButton>
            <KButton v-if="current.kind === 'confirm'" :variant="current.danger ? 'danger' : 'primary'" @click="finish(true)">{{
                current.confirmLabel
            }}</KButton>
            <KButton v-else variant="primary" type="submit" form="k-reauth" :loading="busy" :disabled="!password">{{ __('Confirm') }}</KButton>
        </template>
    </KModal>
</template>
