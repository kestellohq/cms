<script setup>
import { ref } from 'vue';
import { KIcon } from '@kestello/ui';
import InstallerLayout from '../../components/InstallerLayout.vue';

const props = defineProps({ site: { type: Object, default: () => ({ name: '', url: '' }) }, cron: { type: String, default: '' } });

const copied = ref(false);
async function copy() {
    try {
        await navigator.clipboard.writeText(props.cron);
        copied.value = true;
        setTimeout(() => (copied.value = false), 2000);
    } catch {
        copied.value = false; // the command stays visible to select by hand
    }
}
</script>

<template>
    <InstallerLayout :step="6" title="Kestello is installed" description="Three things to do now, and your site is ready for visitors.">
        <div class="k-setup__done">
            <KIcon name="check-circle" />
            <p>
                <span v-if="site.name">{{ site.name }} is up</span><span v-else>Your site is up</span>
                <template v-if="site.url">
                    at <a :href="site.url" class="k-link k-setup__address">{{ site.url }}</a></template
                >.
            </p>
        </div>

        <ol class="k-setup__next">
            <li>
                <div>
                    <strong>Sign in</strong>
                    <p>Use the administrator account you just made. The dashboard shows what to set up first, such as email.</p>
                </div>
            </li>
            <li>
                <div>
                    <strong>Add a cron job</strong>
                    <p>
                        Background work (email, image sizes, scheduled posts, backups) runs on time with a cron job every minute. Add this in your control panel
                        under Cron Jobs:
                    </p>
                    <div v-if="cron" class="k-setup__command">
                        <code>{{ cron }}</code>
                        <button type="button" class="k-btn k-btn--secondary k-btn--md" @click="copy">{{ copied ? 'Copied' : 'Copy' }}</button>
                    </div>
                </div>
            </li>
            <li>
                <div>
                    <strong>Before you go live</strong>
                    <p>Turn on HTTPS for the domain, open System → Site Health, and make a first backup under System → Backups.</p>
                </div>
            </li>
        </ol>

        <div class="k-setup__actions">
            <a href="/login" class="k-btn k-btn--primary k-btn--md">Sign in</a>
        </div>
    </InstallerLayout>
</template>
