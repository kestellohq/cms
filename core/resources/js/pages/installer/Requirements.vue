<script setup>
import { KBadge, KButton } from '@kestello/ui';
import InstallerLayout from '../../components/InstallerLayout.vue';

defineProps({ checks: { type: Array, default: () => [] }, allPassed: Boolean, step: Number });
</script>

<template>
    <InstallerLayout :step="step" title="Server check" description="Kestello needs these on your server. Ask your host to fix anything marked as missing.">
        <ul class="k-list k-install__checks">
            <li v-for="check in checks" :key="check.name" class="k-list__item">
                <div class="k-list__main">
                    <strong>{{ check.name }}</strong>
                    <p class="k-muted k-small">Required: {{ check.required }} · Current: {{ check.current }}</p>
                </div>
                <KBadge :tone="check.passed ? 'success' : 'danger'">{{ check.passed ? 'OK' : 'Missing' }}</KBadge>
            </li>
        </ul>
        <div class="k-install__actions">
            <span v-if="!allPassed" class="k-muted k-small">Fix the items marked as missing, then reload this page.</span>
            <span v-else></span>
            <a v-if="allPassed" href="/install?step=2" class="k-btn k-btn--primary k-btn--md">Continue</a>
            <KButton v-else disabled>Continue</KButton>
        </div>
    </InstallerLayout>
</template>
