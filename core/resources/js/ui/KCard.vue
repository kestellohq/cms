<script setup>
/** A cell on the sheet. `coord` adds a grid reference (A2) before the title. */
defineProps({ title: { type: String, default: '' }, description: { type: String, default: '' }, flush: Boolean, coord: { type: String, default: '' } });
</script>

<template>
    <section class="k-card">
        <header v-if="title || $slots.title || $slots.actions" class="k-card__header">
            <div>
                <h2 class="k-card__title">
                    <span v-if="coord" class="k-card__coord" aria-hidden="true">{{ coord }}</span
                    ><slot name="title">{{ title }}</slot>
                </h2>
                <p v-if="description" class="k-card__description">{{ description }}</p>
            </div>
            <div v-if="$slots.actions" class="k-card__actions"><slot name="actions" /></div>
        </header>
        <div :class="flush ? 'k-card__flush' : 'k-card__body'"><slot /></div>
        <footer v-if="$slots.footer" class="k-card__footer"><slot name="footer" /></footer>
    </section>
</template>
