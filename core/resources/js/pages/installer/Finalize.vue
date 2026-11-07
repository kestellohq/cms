<script setup>
import { KAlert } from '@kestello/ui';
import InstallerLayout from '../../components/InstallerLayout.vue';
import CsrfField from '../../components/CsrfField.vue';

defineProps({ step: Number, summary: { type: Object, required: true }, error: { type: String, default: null } });
</script>

<template>
    <InstallerLayout
        :step="step"
        title="Ready to install"
        description="Check the details below. Installing creates the database tables and your administrator account."
    >
        <form method="POST" action="/install" class="k-auth__form">
            <CsrfField />
            <input type="hidden" name="step" value="5" />
            <KAlert v-if="error" tone="danger">{{ error }}</KAlert>

            <dl class="k-install__summary">
                <div>
                    <dt>Site name</dt>
                    <dd>{{ summary.site_name }}</dd>
                </div>
                <div>
                    <dt>Database</dt>
                    <dd>{{ summary.db_driver }}</dd>
                </div>
                <div>
                    <dt>Location</dt>
                    <dd>
                        <code>{{ summary.db_host }}</code>
                    </dd>
                </div>
                <div>
                    <dt>Database name</dt>
                    <dd>
                        <code>{{ summary.db_name }}</code>
                    </dd>
                </div>
                <div>
                    <dt>Administrator</dt>
                    <dd>{{ summary.admin_username }}</dd>
                </div>
                <div>
                    <dt>Administrator email</dt>
                    <dd>{{ summary.admin_email }}</dd>
                </div>
            </dl>

            <div class="k-install__actions">
                <a href="/install?step=4" class="k-btn k-btn--secondary k-btn--md">Back</a>
                <button type="submit" class="k-btn k-btn--primary k-btn--md">Install Kestello</button>
            </div>
        </form>
    </InstallerLayout>
</template>
