<script setup>
import { Head } from '@kestello/app';

defineProps({ title: { type: String, required: true }, description: { type: String, default: '' } });
</script>

<template>
    <Head :title="title" />
    <header class="k-page-header">
        <div>
            <h1 class="k-page-header__title">{{ title }}</h1>
            <p v-if="description" class="k-page-header__description">{{ description }}</p>
        </div>
        <div v-if="$slots.actions" class="k-page-header__actions"><slot name="actions" /></div>
    </header>
</template>
