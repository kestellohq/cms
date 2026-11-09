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

const NOTES = {
    mysql: 'What most hosting offers.',
    pgsql: 'If your host or server has it.',
    sqlite: 'One file, no database server. For small sites and testing.',
};
const driver = ref(props.db.driver);
const server = computed(() => driver.value !== 'sqlite');
const defaultPort = computed(() => (driver.value === 'pgsql' ? '5432' : '3306'));
</script>

<template>
    <InstallerLayout :step="step" title="Database" description="Choose the kind of database, then enter the details from your hosting control panel.">
        <form method="POST" action="/install" class="k-setup__form">
            <CsrfField />
            <input type="hidden" name="step" value="2" />
            <KAlert v-if="error" tone="danger">{{ error }}</KAlert>
            <KAlert v-if="drivers.length === 0" tone="danger">No supported PDO driver is installed (pdo_mysql, pdo_pgsql or pdo_sqlite).</KAlert>

            <fieldset class="k-setup__choices">
                <legend>Database</legend>
                <label v-for="d in drivers" :key="d.value" class="k-setup__choice">
                    <input v-model="driver" type="radio" name="db_driver" :value="d.value" />
                    <strong>{{ d.label }}</strong>
                    <span>{{ NOTES[d.value] }}</span>
                </label>
            </fieldset>

            <template v-if="server">
                <div class="k-setup__row k-setup__row--port">
                    <div class="k-field">
                        <label class="k-field__label" for="db_host">Host</label>
                        <input id="db_host" type="text" name="db_host" class="k-input" :value="db.host" required aria-describedby="db_host_help" />
                        <p id="db_host_help" class="k-field__help">Usually localhost. Your control panel shows it when it is something else.</p>
                    </div>
                    <div class="k-field">
                        <label class="k-field__label" for="db_port">Port</label>
                        <input id="db_port" :key="defaultPort" type="number" name="db_port" class="k-input" :value="db.port || defaultPort" />
                    </div>
                </div>
                <div class="k-field">
                    <label class="k-field__label" for="db_name">Database name</label>
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
                    <p id="db_name_help" class="k-field__help">Kestello creates it if it does not exist and the user is allowed to.</p>
                </div>
                <div class="k-setup__row">
                    <div class="k-field">
                        <label class="k-field__label" for="db_user">Database user</label>
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
                            :aria-describedby="db.passwordSet ? 'db_pass_help' : undefined"
                        />
                        <p v-if="db.passwordSet" id="db_pass_help" class="k-field__help">Saved. Leave it empty to keep it.</p>
                    </div>
                </div>
            </template>
            <div v-else class="k-field">
                <label class="k-field__label" for="db_name">File name</label>
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
                <p id="db_name_help" class="k-field__help">Letters, numbers and underscores. The file is kept in storage/database, out of reach of visitors.</p>
            </div>

            <div class="k-setup__actions">
                <a href="/install?step=1" class="k-btn k-btn--secondary k-btn--md">Back</a>
                <button type="submit" class="k-btn k-btn--primary k-btn--md" :disabled="drivers.length === 0">Connect and continue</button>
            </div>
        </form>
    </InstallerLayout>
</template>
