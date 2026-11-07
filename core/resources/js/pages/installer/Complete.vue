<script setup>
import { KIcon } from '@kestello/ui';
import InstallerLayout from '../../components/InstallerLayout.vue';
</script>

<template>
    <InstallerLayout :step="6" title="Kestello is installed" description="Sign in with the administrator account you just created.">
        <div class="k-install__done">
            <KIcon name="check-circle" />
            <p>Your site is ready. The admin walks you through the first steps.</p>
        </div>
        <div class="k-install__actions">
            <span></span>
            <a href="/login" class="k-btn k-btn--primary k-btn--md">Sign in</a>
        </div>
    </InstallerLayout>
</template>
