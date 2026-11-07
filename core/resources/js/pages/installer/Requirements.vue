<script setup>
import { computed } from 'vue';
import { KIcon } from '@kestello/ui';
import InstallerLayout from '../../components/InstallerLayout.vue';

const props = defineProps({ checks: { type: Array, default: () => [] }, allPassed: Boolean, step: Number });

const problems = computed(() => props.checks.filter((c) => !c.passed));
const passed = computed(() => props.checks.filter((c) => c.passed));
const groups = computed(() =>
    [...new Set(passed.value.map((c) => c.group))].map((group) => ({ group, checks: passed.value.filter((c) => c.group === group) })),
);
const things = (n) => (n === 1 ? '1 thing to fix' : `${n} things to fix`);
</script>

<template>
    <InstallerLayout
        :step="step"
        title="Server check"
        :description="
            allPassed
                ? 'Everything Kestello needs is in place on this server.'
                : 'Kestello needs a few things from your server. Each problem below says how to fix it.'
        "
    >
        <section v-if="problems.length" class="k-setup__problems" aria-labelledby="problems-title">
            <h2 id="problems-title" class="k-setup__section-title">{{ things(problems.length) }}</h2>
            <ul class="k-setup__problem-list">
                <li v-for="check in problems" :key="check.group + check.name" class="k-setup__problem">
                    <KIcon name="x-circle" />
                    <span class="k-setup__problem-name">
                        <template v-if="check.group === 'Folders'"
                            >Folder <code>{{ check.name }}</code></template
                        >
                        <template v-else>{{ check.name }}</template>
                    </span>
                    <span class="k-setup__problem-found">{{ check.current }}</span>
                    <p class="k-setup__problem-fix">{{ check.fix }}</p>
                </li>
            </ul>
        </section>
        <p v-else class="k-setup__ready"><KIcon name="check-circle" /> Your server is ready.</p>

        <details v-if="passed.length" class="k-setup__passed" :open="allPassed">
            <summary><KIcon name="caret-right" /> {{ passed.length }} {{ passed.length === 1 ? 'check' : 'checks' }} passed</summary>
            <table class="k-setup__table">
                <tbody v-for="g in groups" :key="g.group">
                    <tr>
                        <th scope="rowgroup" colspan="2">{{ g.group }}</th>
                    </tr>
                    <tr v-for="check in g.checks" :key="check.name">
                        <td>{{ check.name }}</td>
                        <td>{{ check.current }}</td>
                    </tr>
                </tbody>
            </table>
        </details>

        <div class="k-setup__actions">
            <template v-if="allPassed">
                <a href="/install?step=2" class="k-btn k-btn--primary k-btn--md">Continue</a>
            </template>
            <template v-else>
                <span class="k-setup__note">Fixed something? Check again to continue.</span>
                <a href="/install?step=1" class="k-btn k-btn--primary k-btn--md">Check again</a>
            </template>
        </div>
    </InstallerLayout>
</template>
