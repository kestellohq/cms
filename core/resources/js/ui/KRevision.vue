<script setup>
/**
 * The notice: the one thing on a screen that needs the user, numbered like
 * a service notice on a departures board.
 *
 *   <KRevision :number="1"> …content… <template #action>…</template> </KRevision>
 */
defineProps({ number: { type: [Number, String], default: 1 } });
</script>

<template>
    <div class="k-revision" role="status">
        <span class="k-revision__mark" aria-hidden="true">{{ number }}</span>
        <div class="k-revision__text"><slot /></div>
        <slot name="action" />
    </div>
</template>
