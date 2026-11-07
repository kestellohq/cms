<script setup>
import { KAlert } from '@kestello/ui';
import InstallerLayout from '../../components/InstallerLayout.vue';
import CsrfField from '../../components/CsrfField.vue';

defineProps({ step: Number, error: { type: String, default: null }, site: { type: Object, required: true }, timezones: { type: Array, default: () => [] } });
</script>

<template>
    <InstallerLayout :step="step" title="Your site" description="You can change all of this later under Settings.">
        <form method="POST" action="/install" class="k-auth__form">
            <CsrfField />
            <input type="hidden" name="step" value="3" />
            <KAlert v-if="error" tone="danger">{{ error }}</KAlert>

            <div class="k-field">
                <label class="k-field__label" for="site_name">Site name</label>
                <input id="site_name" type="text" name="site_name" class="k-input" :value="site.name" required />
            </div>
            <div class="k-field">
                <label class="k-field__label" for="site_description">Tagline</label>
                <input
                    id="site_description"
                    type="text"
                    name="site_description"
                    class="k-input"
                    :value="site.description"
                    aria-describedby="site_description_help"
                />
                <p id="site_description_help" class="k-field__help">One short sentence about the site. Optional.</p>
            </div>
            <div class="k-field">
                <label class="k-field__label" for="site_url">Site address</label>
                <input id="site_url" type="url" name="site_url" class="k-input" :value="site.url" required aria-describedby="site_url_help" />
                <p id="site_url_help" class="k-field__help">The full address visitors use, starting with https://</p>
            </div>
            <div class="k-field">
                <label class="k-field__label" for="timezone">Time zone</label>
                <select id="timezone" name="timezone" class="k-input k-select">
                    <option v-for="tz in timezones" :key="tz" :value="tz" :selected="tz === site.timezone">{{ tz }}</option>
                </select>
            </div>

            <div class="k-install__actions">
                <a href="/install?step=2" class="k-btn k-btn--secondary k-btn--md">Back</a>
                <button type="submit" class="k-btn k-btn--primary k-btn--md">Continue</button>
            </div>
        </form>
    </InstallerLayout>
</template>
