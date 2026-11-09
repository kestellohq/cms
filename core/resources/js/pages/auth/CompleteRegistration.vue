<script setup>
import { Link, useForm } from '@kestello/app';
import { __ } from '@kestello/sdk';
import { KAlert, KButton, KField, KInput } from '@kestello/ui';
import AuthCard from '../../components/AuthCard.vue';
import FormErrors from '../../components/FormErrors.vue';
import PasswordInput from '../../components/PasswordInput.vue';

const props = defineProps({
    token: { type: String, default: '' },
    email: { type: String, default: '' },
    valid: { type: Boolean, default: false },
    minLength: { type: Number, default: 12 },
});

const form = useForm({ token: props.token, password: '', password_confirmation: '' });

function submit() {
    form.post('/register/complete', { onFinish: () => form.reset('password', 'password_confirmation') });
}
</script>

<template>
    <AuthCard :title="__('Choose your password')">
        <template v-if="valid">
            <FormErrors :errors="form.errors" :fields="['password', 'password_confirmation']" />
            <form class="k-auth__form" novalidate @submit.prevent="submit">
                <!-- Shown, and named "username", so password managers save the password for this address. -->
                <KField v-slot="{ id }" :label="__('Email address')">
                    <KInput :id="id" :model-value="email" type="email" autocomplete="username" readonly />
                </KField>
                <KField
                    v-slot="{ id, describedBy, invalid }"
                    :label="__('Password')"
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
                <KField v-slot="{ id, describedBy, invalid }" :label="__('Repeat password')" :error="form.errors.password_confirmation">
                    <PasswordInput
                        :id="id"
                        v-model="form.password_confirmation"
                        :invalid="invalid"
                        :aria-describedby="describedBy"
                        autocomplete="new-password"
                        required
                    />
                </KField>
                <KButton type="submit" variant="primary" icon="check" block :loading="form.processing">{{ __('Create account') }}</KButton>
            </form>
        </template>
        <template v-else>
            <FormErrors :errors="form.errors" />
            <KAlert tone="warning">{{ __('This link is invalid or has expired.') }}</KAlert>
            <KButton href="/register" variant="primary" icon="paper-plane-tilt" block>{{ __('Get a new link') }}</KButton>
        </template>

        <template #footer
            ><Link href="/login" class="k-link">{{ __('Back to sign in') }}</Link></template
        >
    </AuthCard>
</template>
