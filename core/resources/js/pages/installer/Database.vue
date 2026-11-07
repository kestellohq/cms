<script setup>
import { computed, ref } from 'vue';
import { KAlert } from '@kestello/ui';
import InstallerLayout from '../../components/InstallerLayout.vue';
import CsrfField from '../../components/CsrfField.vue';

const props = defineProps({
    step: Number,
    db: { type: Object, required: true },
    drivers: { type: Array, default: () => [] },
    error: { type: String, default: null },
});

const driver = ref(props.db.driver);
const server = computed(() => driver.value !== 'sqlite');
const defaultPort = computed(() => (driver.value === 'pgsql' ? '5432' : '3306'));
</script>

<template>
    <InstallerLayout :step="step" title="Database" description="Choose a database and enter the details your host gave you.">
        <form method="POST" action="/install" class="k-auth__form">
            <CsrfField />
            <input type="hidden" name="step" value="2" />
            <KAlert v-if="error" tone="danger">{{ error }}</KAlert>
            <KAlert v-if="drivers.length === 0" tone="danger">No supported PDO driver is installed (pdo_mysql, pdo_pgsql or pdo_sqlite).</KAlert>

            <div class="k-field">
                <label class="k-field__label" for="db_driver">Database</label>
                <select id="db_driver" v-model="driver" name="db_driver" class="k-input k-select">
                    <option v-for="d in drivers" :key="d.value" :value="d.value">{{ d.label }}</option>
                </select>
                <p v-if="!server" class="k-field__help">A single file in storage/database/, outside the web root. Best for small sites and testing.</p>
            </div>

            <div v-if="server" class="k-install__row">
                <div class="k-field">
                    <label class="k-field__label" for="db_host">Host</label>
                    <input id="db_host" type="text" name="db_host" class="k-input" :value="db.host" required aria-describedby="db_host_help" />
                    <p id="db_host_help" class="k-field__help">Usually "localhost". Your host's control panel shows it if it is different.</p>
                </div>
                <div class="k-field k-install__narrow">
                    <label class="k-field__label" for="db_port">Port</label>
                    <input id="db_port" type="number" name="db_port" class="k-input" :value="db.port || defaultPort" :placeholder="defaultPort" />
                </div>
            </div>
            <div class="k-field">
                <label class="k-field__label" for="db_name">{{ server ? 'Database name' : 'File name' }}</label>
                <input
                    id="db_name"
                    type="text"
                    name="db_name"
                    class="k-input"
                    :value="db.name"
                    required
                    pattern="[A-Za-z0-9_]{1,64}"
                    aria-describedby="db_name_help"
                />
                <p id="db_name_help" class="k-field__help">
                    {{
                        server
                            ? 'Created if it does not exist and the user is allowed to create it.'
                            : 'Letters, numbers and underscores. The file is stored in storage/database.'
                    }}
                </p>
            </div>
            <div v-if="server" class="k-install__row">
                <div class="k-field">
                    <label class="k-field__label" for="db_user">Username</label>
                    <input id="db_user" type="text" name="db_user" class="k-input" :value="db.user" required autocomplete="off" />
                </div>
                <div class="k-field">
                    <label class="k-field__label" for="db_pass">Password</label>
                    <input
                        id="db_pass"
                        type="password"
                        name="db_pass"
                        class="k-input"
                        autocomplete="new-password"
                        :placeholder="db.passwordSet ? 'Saved (leave blank to keep)' : ''"
                    />
                </div>
            </div>

            <div class="k-install__actions">
                <a href="/install?step=1" class="k-btn k-btn--secondary k-btn--md">Back</a>
                <button type="submit" class="k-btn k-btn--primary k-btn--md" :disabled="drivers.length === 0">Test and continue</button>
            </div>
        </form>
    </InstallerLayout>
</template>
