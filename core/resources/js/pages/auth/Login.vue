<script setup>
import { Link, useForm, usePage } from '@kestello/app';
import { __ } from '@kestello/sdk';
import { KButton, KField, KIcon, KInput } from '@kestello/ui';
import AuthCard from '../../components/AuthCard.vue';
import FormErrors from '../../components/FormErrors.vue';
import PasswordInput from '../../components/PasswordInput.vue';

const page = usePage();
const form = useForm({ email: page.props.old?.email ?? '', password: '' });
const providers = page.props.providers ?? [];
// A provider icon is a Phosphor icon name ("google-logo") or a local asset path.
const iconPath = (icon) => (typeof icon === 'string' && /^\/assets\/[\w./-]+\.(svg|png)$/.test(icon) ? icon : null);
const iconName = (icon) => (typeof icon === 'string' && /^[a-z0-9-]+$/.test(icon) ? icon : null);

function submit() {
    form.post('/login', { onFinish: () => form.reset('password') });
}
</script>

<template>
    <AuthCard :title="__('Sign in')" :description="__('Use your email address or username.')">
        <FormErrors :errors="form.errors" :fields="['email', 'password']" />

        <form class="k-auth__form" novalidate @submit.prevent="submit">
            <KField v-slot="{ id, describedBy, invalid }" :label="__('Email or username')" :error="form.errors.email">
                <KInput
                    :id="id"
                    v-model="form.email"
                    :invalid="invalid"
                    :aria-describedby="describedBy"
                    autocomplete="username"
                    autocapitalize="off"
                    spellcheck="false"
                    required
                    autofocus
                />
            </KField>
            <KField v-slot="{ id, describedBy, invalid }" :label="__('Password')" :error="form.errors.password">
                <PasswordInput :id="id" v-model="form.password" :invalid="invalid" :aria-describedby="describedBy" autocomplete="current-password" required />
            </KField>
            <KButton type="submit" variant="primary" icon="sign-in" block :loading="form.processing">{{ __('Sign in') }}</KButton>
            <Link href="/forgot-password" class="k-link k-auth__aside">{{ __('Forgot your password?') }}</Link>
        </form>

        <template v-if="providers.length">
            <p class="k-auth__or">
                <span>{{ __('or') }}</span>
            </p>
            <div class="k-auth__providers">
                <!-- Plain links: the provider redirect is a full page navigation. -->
                <a v-for="p in providers" :key="p.id" :href="p.url" class="k-btn k-btn--secondary k-btn--md k-btn--block">
                    <img v-if="iconPath(p.icon)" :src="iconPath(p.icon)" alt="" width="18" height="18" />
                    <KIcon v-else-if="iconName(p.icon)" :name="iconName(p.icon)" />
                    <span>{{ __('Continue with {provider}', { provider: p.label }) }}</span>
                </a>
            </div>
        </template>
    </AuthCard>
</template>
