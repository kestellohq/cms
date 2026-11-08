<script setup>
import { KAlert } from '@kestello/ui';
import InstallerLayout from '../../components/InstallerLayout.vue';
import CsrfField from '../../components/CsrfField.vue';

defineProps({ step: Number, error: { type: String, default: null }, site: { type: Object, required: true }, timezones: { type: Array, default: () => [] } });
</script>

<template>
    <InstallerLayout :step="step" title="Your site" description="The basics. You can change all of it later under Settings.">
        <form method="POST" action="/install" class="k-setup__form">
            <CsrfField />
            <input type="hidden" name="step" value="3" />
            <KAlert v-if="error" tone="danger">{{ error }}</KAlert>

            <div class="k-field">
                <label class="k-field__label" for="site_name">Site name</label>
                <input id="site_name" type="text" name="site_name" class="k-input" :value="site.name" required />
            </div>
            <div class="k-field">
                <label class="k-field__label" for="site_description">Tagline <span class="k-muted">(optional)</span></label>
                <input
                    id="site_description"
                    type="text"
                    name="site_description"
                    class="k-input"
                    :value="site.description"
                    aria-describedby="site_description_help"
                />
                <p id="site_description_help" class="k-field__help">One short sentence about the site.</p>
            </div>
            <div class="k-setup__row">
                <div class="k-field">
                    <label class="k-field__label" for="site_url">Site address</label>
                    <input id="site_url" type="url" name="site_url" class="k-input" :value="site.url" required aria-describedby="site_url_help" />
                    <p id="site_url_help" class="k-field__help">The full address visitors use, with https://</p>
                </div>
                <div class="k-field">
                    <label class="k-field__label" for="timezone">Time zone</label>
                    <select id="timezone" name="timezone" class="k-input k-select" aria-describedby="timezone_help">
                        <option v-for="tz in timezones" :key="tz" :value="tz" :selected="tz === site.timezone">{{ tz }}</option>
                    </select>
                    <p id="timezone_help" class="k-field__help">For scheduled posts and dates.</p>
                </div>
            </div>

            <fieldset class="k-setup__choices">
                <legend>What is this site for?</legend>
                <label class="k-setup__choice">
                    <input type="radio" name="site_kind" value="site" :checked="!site.demo" />
                    <strong>A website</strong>
                    <span>Your own site. You make the administrator account in the next step.</span>
                </label>
                <label class="k-setup__choice">
                    <input type="radio" name="site_kind" value="demo" :checked="site.demo" />
                    <strong>A public demo</strong>
                    <span
                        >For showing Kestello to others. It starts with a sample bakery site, anyone can sign in as demo with the password demo, and it resets
                        every hour. Accounts, plugins, updates, backups and settings are locked, and nothing is emailed. You manage it on the server with
                        bin/cms.</span
                    >
                </label>
            </fieldset>

            <div class="k-setup__actions">
                <a href="/install?step=2" class="k-btn k-btn--secondary k-btn--md">Back</a>
                <button type="submit" class="k-btn k-btn--primary k-btn--md">Continue</button>
            </div>
        </form>
    </InstallerLayout>
</template>
