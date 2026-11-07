<script setup>
import { computed } from 'vue';
import { Link, useForm, usePage } from '@kestello/app';
import { __ } from '@kestello/sdk';
import { KButton, KField, KInput } from '@kestello/ui';
import AuthCard from '../../components/AuthCard.vue';
import FormErrors from '../../components/FormErrors.vue';

const props = defineProps({
    // Set when sign-up is limited to some email domains.
    domains: { type: Array, default: () => [] },
});

const page = usePage();
const form = useForm({ email: page.props.old?.email ?? '' });
const help = computed(() => (props.domains.length ? __('Use an address at {domains}.', { domains: props.domains.join(', ') }) : ''));

function submit() {
    form.post('/register');
}
</script>

<template>
    <AuthCard :title="__('Create an account')" :description="__('Enter your email address. We will send you a link to choose your password.')">
        <FormErrors :errors="form.errors" :fields="['email']" />

        <form class="k-auth__form" novalidate @submit.prevent="submit">
            <KField v-slot="{ id, describedBy, invalid }" :label="__('Email address')" :help="help" :error="form.errors.email">
                <KInput :id="id" v-model="form.email" type="email" :invalid="invalid" :aria-describedby="describedBy" autocomplete="email" required autofocus />
            </KField>
            <KButton type="submit" variant="primary" icon="paper-plane-tilt" block :loading="form.processing">{{ __('Send link') }}</KButton>
        </form>

        <template #footer
            ><Link href="/login" class="k-link">{{ __('Already have an account? Sign in') }}</Link></template
        >
    </AuthCard>
</template>
