<script setup>
import { ref } from 'vue';
import { KAlert } from '@kestello/ui';
import InstallerLayout from '../../components/InstallerLayout.vue';
import CsrfField from '../../components/CsrfField.vue';

defineProps({ step: Number, summary: { type: Object, required: true }, error: { type: String, default: null } });

const installing = ref(false);
</script>

<template>
    <InstallerLayout
        :step="step"
        title="Ready to install"
        description="Check the details. Installing creates the database tables and your administrator account, which takes a few seconds."
    >
        <form method="POST" action="/install" class="k-setup__form" @submit="installing = true">
            <CsrfField />
            <input type="hidden" name="step" value="5" />
            <KAlert v-if="error" tone="danger">{{ error }}</KAlert>

            <dl class="k-setup__summary">
                <div>
                    <dt>Site</dt>
                    <dd>{{ summary.site_name }} <a href="/install?step=3" class="k-link k-small">Change</a></dd>
                </div>
                <div>
                    <dt>Database</dt>
                    <dd>{{ summary.db_driver }} <a href="/install?step=2" class="k-link k-small">Change</a></dd>
                </div>
                <div>
                    <dt>{{ summary.db_driver === 'SQLite' ? 'File' : 'Host' }}</dt>
                    <dd>
                        <code>{{ summary.db_host }}</code>
                    </dd>
                </div>
                <div v-if="summary.db_driver !== 'SQLite'">
                    <dt>Database name</dt>
                    <dd>
                        <code>{{ summary.db_name }}</code>
                    </dd>
                </div>
                <div v-if="summary.demo">
                    <dt>Kind</dt>
                    <dd>A public demo: everyone signs in as demo, and it resets every hour <a href="/install?step=3" class="k-link k-small">Change</a></dd>
                </div>
                <template v-else>
                    <div>
                        <dt>Administrator</dt>
                        <dd>{{ summary.admin_username }} <a href="/install?step=4" class="k-link k-small">Change</a></dd>
                    </div>
                    <div>
                        <dt>Administrator email</dt>
                        <dd>{{ summary.admin_email }}</dd>
                    </div>
                </template>
            </dl>

            <div class="k-setup__actions">
                <a :href="summary.demo ? '/install?step=3' : '/install?step=4'" class="k-btn k-btn--secondary k-btn--md">Back</a>
                <button type="submit" class="k-btn k-btn--primary k-btn--md" :disabled="installing" :aria-busy="installing">
                    {{ installing ? 'Installing…' : 'Install Kestello' }}
                </button>
            </div>
        </form>
    </InstallerLayout>
</template>
