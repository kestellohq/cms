<script setup>
import { computed, ref } from 'vue';
import { formatDateTime, notify, useApi, __ } from '@kestello/sdk';
import { KAlert, KButton } from '@kestello/ui';

/**
 * An update that stopped halfway (PHP was stopped by the host, the server
 * restarted). The site puts it back by itself on the next request; this
 * shows up when that did not work three times, with a way to try again.
 */
const props = defineProps({
    /** stopped_update from GET /updates or GET /plugins */
    stopped: { type: Object, required: true },
    /** Whether this person may ask for another try (core.update). */
    canRetry: { type: Boolean, default: true },
});

const api = useApi();
const busy = ref(false);
const what = computed(() => (props.stopped.owner === 'core' ? 'Kestello' : props.stopped.owner.replace(/^(plugin|theme):/, '')));
const describe = computed(() =>
    props.stopped.kind === 'switch-back'
        ? __('Switching {name} back from {to} to {from} started {date} and did not finish.', {
              name: what.value,
              from: props.stopped.from,
              to: props.stopped.to,
              date: formatDateTime(props.stopped.started_at),
          })
        : __('The update of {name} from {from} to {to} started {date} and stopped before it finished.', {
              name: what.value,
              from: props.stopped.from,
              to: props.stopped.to,
              date: formatDateTime(props.stopped.started_at),
          }),
);

async function retry() {
    busy.value = true;
    try {
        await api.post('/updates/recover');
        // the next request puts it back, before plugins load; then the page comes from the code that runs
        window.location.reload();
    } catch (e) {
        notify.error(e);
        busy.value = false;
    }
}
</script>

<template>
    <KAlert tone="danger" :title="stopped.gave_up ? __('An update stopped halfway and was not put back') : __('An update stopped halfway')">
        <p>{{ describe }}</p>
        <p v-if="stopped.gave_up">
            {{
                __(
                    'Putting {name} and the database back as they were failed {n} times. Visitors see the maintenance page until it works. The reasons are in the audit log.',
                    { name: what, n: stopped.attempts },
                )
            }}
        </p>
        <p v-else>{{ __('The site is putting it back. Reload the page in a moment.') }}</p>
        <p class="k-small">
            {{ __('On the server, the same is done with: bin/cms update:recover') }}
        </p>
        <div v-if="canRetry" class="k-stopped-update__actions">
            <KButton variant="primary" icon="arrow-counter-clockwise" :loading="busy" @click="retry">{{ __('Try again') }}</KButton>
        </div>
    </KAlert>
</template>

<style scoped>
.k-stopped-update__actions {
    margin-top: 8px;
}
</style>
