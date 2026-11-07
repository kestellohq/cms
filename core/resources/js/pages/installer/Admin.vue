<script setup>
import { KAlert } from '@kestello/ui';
import InstallerLayout from '../../components/InstallerLayout.vue';
import CsrfField from '../../components/CsrfField.vue';
import PasswordInput from '../../components/PasswordInput.vue';

defineProps({ step: Number, error: { type: String, default: null }, admin: { type: Object, default: () => ({ username: '', email: '' }) } });
</script>

<template>
    <InstallerLayout
        :step="step"
        title="Administrator account"
        description="This account can do everything on the site. You can invite other people with smaller roles later."
    >
        <form method="POST" action="/install" class="k-setup__form">
            <CsrfField />
            <input type="hidden" name="step" value="4" />
            <KAlert v-if="error" tone="danger">{{ error }}</KAlert>

            <div class="k-setup__row">
                <div class="k-field">
                    <label class="k-field__label" for="admin_username">Username</label>
                    <input
                        id="admin_username"
                        type="text"
                        name="admin_username"
                        :value="admin.username"
                        class="k-input"
                        required
                        minlength="3"
                        autocomplete="username"
                        autocapitalize="off"
                        spellcheck="false"
                    />
                </div>
                <div class="k-field">
                    <label class="k-field__label" for="admin_email">Email address</label>
                    <input id="admin_email" type="email" name="admin_email" class="k-input" :value="admin.email" required autocomplete="email" />
                </div>
            </div>
            <div class="k-field">
                <label class="k-field__label" for="admin_password">Password</label>
                <PasswordInput
                    id="admin_password"
                    name="admin_password"
                    required
                    minlength="12"
                    autocomplete="new-password"
                    aria-describedby="admin_password_help"
                />
                <p id="admin_password_help" class="k-field__help">
                    At least 12 characters. Common passwords are refused. A few unrelated words make a strong password you can remember.
                </p>
            </div>
            <div class="k-field">
                <label class="k-field__label" for="admin_password_confirm">Repeat the password</label>
                <PasswordInput id="admin_password_confirm" name="admin_password_confirm" required autocomplete="new-password" />
            </div>

            <div class="k-setup__actions">
                <a href="/install?step=3" class="k-btn k-btn--secondary k-btn--md">Back</a>
                <button type="submit" class="k-btn k-btn--primary k-btn--md">Continue</button>
            </div>
        </form>
    </InstallerLayout>
</template>
