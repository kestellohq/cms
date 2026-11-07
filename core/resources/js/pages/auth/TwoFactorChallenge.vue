<script setup>
import { ref } from 'vue';
import { Link, useForm } from '@kestello/app';
import { __ } from '@kestello/sdk';
import { KButton, KField } from '@kestello/ui';
import AuthCard from '../../components/AuthCard.vue';
import FormErrors from '../../components/FormErrors.vue';

const recovery = ref(false);
const form = useForm({ code: '' });

function submit() {
    form.post('/login/two-factor', { onFinish: () => form.reset('code') });
}

function toggle() {
    recovery.value = !recovery.value;
    form.reset('code');
    form.clearErrors();
}
</script>

<template>
    <AuthCard
        :title="__('Two-step sign-in')"
        :description="recovery ? __('Enter one of your recovery codes. Each code works once.') : __('Enter the six-digit code from your authenticator app.')"
        code="K-04"
    >
        <FormErrors :errors="form.errors" :fields="['code']" />

        <form class="k-auth__form" novalidate @submit.prevent="submit">
            <KField v-slot="{ id, describedBy, invalid }" :label="recovery ? __('Recovery code') : __('Code')" :error="form.errors.code">
                <input
                    v-if="!recovery"
                    :id="id"
                    v-model="form.code"
                    class="k-input k-auth__code"
                    :aria-invalid="invalid ? 'true' : undefined"
                    :aria-describedby="describedBy"
                    inputmode="numeric"
                    pattern="[0-9 ]*"
                    maxlength="7"
                    autocomplete="one-time-code"
                    required
                    autofocus
                />
                <input
                    v-else
                    :id="id"
                    v-model="form.code"
                    class="k-input k-auth__code is-recovery"
                    :aria-invalid="invalid ? 'true' : undefined"
                    :aria-describedby="describedBy"
                    maxlength="11"
                    autocomplete="off"
                    autocapitalize="characters"
                    spellcheck="false"
                    required
                    autofocus
                />
            </KField>
            <KButton type="submit" variant="primary" icon="shield-check" block :loading="form.processing">{{ __('Verify') }}</KButton>
            <button type="button" class="k-link-btn k-auth__aside" @click="toggle">
                {{ recovery ? __('Use the authenticator app instead') : __('Use a recovery code') }}
            </button>
        </form>

        <template #footer
            ><Link href="/login" class="k-link">{{ __('Back to sign in') }}</Link></template
        >
    </AuthCard>
</template>
