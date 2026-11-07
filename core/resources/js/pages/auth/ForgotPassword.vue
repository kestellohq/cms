<script setup>
import { Link, useForm, usePage } from '@kestello/app';
import { __ } from '@kestello/sdk';
import { KButton, KField, KInput } from '@kestello/ui';
import AuthCard from '../../components/AuthCard.vue';
import FormErrors from '../../components/FormErrors.vue';

const page = usePage();
const form = useForm({ email: page.props.old?.email ?? '' });

function submit() {
    form.post('/forgot-password');
}
</script>

<template>
    <AuthCard
        :title="__('Reset your password')"
        :description="__('Enter the email address of your account. We will send you a link to choose a new password.')"
        code="K-02"
    >
        <FormErrors :errors="form.errors" :fields="['email']" />

        <form class="k-auth__form" novalidate @submit.prevent="submit">
            <KField v-slot="{ id, describedBy, invalid }" :label="__('Email address')" :error="form.errors.email">
                <KInput :id="id" v-model="form.email" type="email" :invalid="invalid" :aria-describedby="describedBy" autocomplete="email" required autofocus />
            </KField>
            <KButton type="submit" variant="primary" icon="paper-plane-tilt" block :loading="form.processing">{{ __('Send reset link') }}</KButton>
        </form>

        <template #footer
            ><Link href="/login" class="k-link">{{ __('Back to sign in') }}</Link></template
        >
    </AuthCard>
</template>
