<script setup>
import { Link, useForm } from '@kestello/app';
import { __ } from '@kestello/sdk';
import { KAlert, KButton, KField } from '@kestello/ui';
import AuthCard from '../../components/AuthCard.vue';
import FormErrors from '../../components/FormErrors.vue';
import PasswordInput from '../../components/PasswordInput.vue';

const props = defineProps({
    token: { type: String, default: '' },
    valid: { type: Boolean, default: false },
    minLength: { type: Number, default: 12 },
});

const form = useForm({ token: props.token, password: '', password_confirmation: '' });

function submit() {
    form.post('/reset-password', { onFinish: () => form.reset('password', 'password_confirmation') });
}
</script>

<template>
    <AuthCard :title="__('Choose a new password')" code="K-03">
        <template v-if="valid">
            <FormErrors :errors="form.errors" :fields="['password', 'password_confirmation']" />
            <form class="k-auth__form" novalidate @submit.prevent="submit">
                <KField
                    v-slot="{ id, describedBy, invalid }"
                    :label="__('New password')"
                    :help="__('At least {n} characters. A few unrelated words make a strong password you can remember.', { n: minLength })"
                    :error="form.errors.password"
                >
                    <PasswordInput
                        :id="id"
                        v-model="form.password"
                        :invalid="invalid"
                        :aria-describedby="describedBy"
                        :minlength="minLength"
                        autocomplete="new-password"
                        required
                        autofocus
                    />
                </KField>
                <KField v-slot="{ id, describedBy, invalid }" :label="__('Repeat new password')" :error="form.errors.password_confirmation">
                    <PasswordInput
                        :id="id"
                        v-model="form.password_confirmation"
                        :invalid="invalid"
                        :aria-describedby="describedBy"
                        autocomplete="new-password"
                        required
                    />
                </KField>
                <KButton type="submit" variant="primary" icon="check" block :loading="form.processing">{{ __('Change password') }}</KButton>
            </form>
        </template>
        <template v-else>
            <FormErrors :errors="form.errors" />
            <KAlert tone="warning">{{ __('This reset link is invalid or has expired.') }}</KAlert>
            <KButton href="/forgot-password" variant="primary" icon="paper-plane-tilt" block>{{ __('Request a new link') }}</KButton>
        </template>

        <template #footer
            ><Link href="/login" class="k-link">{{ __('Back to sign in') }}</Link></template
        >
    </AuthCard>
</template>
